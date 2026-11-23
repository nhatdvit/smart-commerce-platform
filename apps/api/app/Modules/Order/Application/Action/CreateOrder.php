<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Action;

use App\Modules\Menu\Application\ComposeTodayMenu;
use App\Modules\Menu\Application\DTO\MenuItemView;
use App\Modules\Order\Application\DTO\CreateOrderInput;
use App\Modules\Order\Application\DTO\CreateOrderResult;
use App\Modules\Order\Domain\Entity\OrderItem as OrderItemEntity;
use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Modules\Order\Domain\ValueObject\OrderContact;
use App\Modules\Order\Domain\ValueObject\OrderTotals;
use App\Modules\Order\Infrastructure\Persistence\Model\Order as OrderModel;
use App\Modules\Order\Infrastructure\Persistence\Model\OrderItem as OrderItemModel;
use App\Modules\Order\Infrastructure\Persistence\Model\OrderStatusChange as OrderStatusChangeModel;
use App\Modules\Payment\Application\Action\GenerateBankTransferInstructions;
use App\Modules\Payment\Application\Action\InitiatePayment;
use App\Modules\Payment\Domain\Contract\PaymentMethodRepository;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Checkout: the only place a cart turns into an order.
 *
 * Every line is repriced against the live menu, never trusted from the
 * client (CLAUDE.md section 6) - the same `GET /menus/today` composition the
 * storefront itself reads from, so what a customer saw and what they are
 * charged can never quietly disagree. `expected_total_amount` is a consent
 * check on top of that, not a second source of truth: a mismatch means the
 * client's idea of the price is stale, and checkout refuses rather than
 * charging a number the customer never agreed to.
 *
 * What this slice deliberately does not do, and why:
 *   - No stock reservation. `stock_reservations` and the row-locking that
 *     would prevent overselling the last portion are Inventory module work
 *     that does not exist yet (CLAUDE.md section 15 leaves the reservation
 *     policy open); only the already-built `is_available` flag is checked.
 *   - No discount/coupon application. Pricing's coupon module validates and
 *     stores codes but has no action that prices an order against one yet;
 *     wiring that in here would invent the resolution policy CLAUDE.md
 *     section 15 leaves open (stacking, minimum spend enforcement point).
 *   - No persisted Cart. The designed contract is cart-based
 *     (`POST /carts` -> `POST /carts/{cart}/items` -> `POST /orders`), but no
 *     Cart module exists; this accepts line items directly, which is the
 *     smallest complete vertical slice that still reuses every other piece
 *     of the designed checkout (idempotency, revalidation, snapshots,
 *     guest access, VietQR). `docs/api/openapi.yaml`'s `CreateOrderRequest`
 *     is written to match.
 */
final readonly class CreateOrder
{
    public function __construct(
        private ComposeTodayMenu $todayMenu,
        private PaymentMethodRepository $paymentMethods,
        private InitiatePayment $initiatePayment,
        private GenerateBankTransferInstructions $generateBankTransferInstructions,
    ) {}

    public function handle(string $storeId, CreateOrderInput $input, ?string $customerId): CreateOrderResult
    {
        $this->assertPaymentMethodEnabled($storeId, $input->paymentMethod);

        $menu = $this->todayMenu->forStore($storeId);

        if ($menu === null) {
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'No menu is currently available');
        }

        $byMenuItemId = [];
        foreach ($menu->items as $view) {
            if ($view->item->id !== null) {
                $byMenuItemId[$view->item->id] = $view;
            }
        }

        $lines = $this->priceLines($input->items, $byMenuItemId);
        $totals = OrderTotals::fromLines($lines);

        if ($input->expectedTotalAmount !== null && ! $input->expectedTotalAmount->equals($totals->total)) {
            throw new BusinessRuleViolation(
                ErrorCode::PriceChanged,
                'The order total has changed since it was last shown',
                [[
                    'field' => 'expected_total_amount',
                    'message' => 'Giá đơn hàng đã thay đổi, vui lòng kiểm tra lại giỏ hàng.',
                ]],
            );
        }

        $contact = OrderContact::create($input->contactName, $input->contactPhone, $input->contactEmail);
        $now = CarbonImmutable::now();
        $status = $input->paymentMethod === PaymentMethod::Cash ? OrderStatus::Placed : OrderStatus::PendingPayment;
        $orderCode = $this->nextOrderCode($now);

        return DB::transaction(function () use (
            $storeId,
            $input,
            $contact,
            $lines,
            $totals,
            $status,
            $orderCode,
            $now,
            $customerId,
        ): CreateOrderResult {
            $guestAccessToken = $customerId === null ? Str::random(40) : null;

            /** @var OrderModel $order */
            $order = OrderModel::query()->create([
                'store_id' => $storeId,
                'order_code' => $orderCode,
                'customer_id' => $customerId,
                'contact_name' => $contact->name,
                'contact_phone' => $contact->phone,
                'contact_email' => $contact->email,
                'status' => $status->value,
                'fulfillment_type' => $input->fulfillmentType->value,
                'scheduled_at' => $input->scheduledAt,
                'delivery_address_line' => $input->deliveryAddressLine,
                'note' => $input->note,
                'subtotal_amount' => $totals->subtotal->amount,
                'discount_amount' => $totals->discount->amount,
                'delivery_fee_amount' => $totals->deliveryFee->amount,
                'tax_amount' => $totals->tax->amount,
                'total_amount' => $totals->total->amount,
                'currency' => 'VND',
                'placed_at' => $status === OrderStatus::Placed ? $now : null,
            ]);

            if ($guestAccessToken !== null) {
                $order->forceFill([
                    'guest_access_token_hash' => hash('sha256', $guestAccessToken),
                ])->save();
            }

            foreach ($lines as $sortOrder => $line) {
                OrderItemModel::query()->create([
                    'store_id' => $storeId,
                    'order_id' => $order->id,
                    'product_id' => $line->productId,
                    'product_variant_id' => $line->variantId,
                    'product_name' => $line->productName,
                    'variant_name' => $line->variantName,
                    'quantity' => $line->quantity,
                    'unit_price_amount' => $line->unitPrice->amount,
                    'modifiers_total_amount' => $line->modifiersTotal->amount,
                    'line_total_amount' => $line->lineTotal->amount,
                    'currency' => 'VND',
                    'note' => $line->note,
                    'sort_order' => $sortOrder,
                ]);
            }

            OrderStatusChangeModel::query()->create([
                'store_id' => $storeId,
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => $status->value,
                // The customer placed it; a staff decision has an actor,
                // placing an order does not (CLAUDE.md section 6).
                'actor_id' => null,
                'reason' => null,
                'created_at' => $now,
            ]);

            $paymentId = $this->initiatePayment->handle(
                $storeId,
                (string) $order->id,
                $orderCode,
                $contact->name,
                $input->paymentMethod,
                $totals->total,
                $input->paymentMethod === PaymentMethod::BankTransfer
                    ? $now->addMinutes((int) config('commerce.bank_transfer.expiry_minutes', 30))
                    : null,
            );

            if ($input->paymentMethod === PaymentMethod::BankTransfer) {
                // Throws (and rolls back everything above) when the store has
                // no bank account configured - a configuration gap, not a
                // half-created order left for a customer to find.
                $this->generateBankTransferInstructions->handle($storeId, $paymentId, $orderCode, $totals->total);
            }

            return new CreateOrderResult((string) $order->id, $orderCode, $guestAccessToken);
        });
    }

    /**
     * @param  list<\App\Modules\Order\Application\DTO\CreateOrderLineInput>  $requested
     * @param  array<string, MenuItemView>  $byMenuItemId
     * @return list<OrderItemEntity>
     */
    private function priceLines(array $requested, array $byMenuItemId): array
    {
        $lines = [];

        foreach ($requested as $sortOrder => $line) {
            $view = $byMenuItemId[$line->menuItemId] ?? null;

            if ($view === null) {
                throw new BusinessRuleViolation(
                    ErrorCode::MenuUnavailable,
                    'An item in the cart is no longer on the menu',
                    [['field' => sprintf('items.%d.menu_item_id', $sortOrder), 'message' => 'Món này không còn trong thực đơn.']],
                );
            }

            if (! $view->item->isAvailable) {
                throw new BusinessRuleViolation(
                    ErrorCode::OutOfStock,
                    'An item in the cart is sold out',
                    [[
                        'field' => sprintf('items.%d.menu_item_id', $sortOrder),
                        'message' => sprintf('"%s" hiện đã hết.', $view->variant->productName),
                    ]],
                );
            }

            $lines[] = OrderItemEntity::create(
                id: null,
                productId: $view->variant->productId,
                variantId: $view->variant->variantId,
                productName: $view->variant->productName,
                variantName: $view->variant->variantName,
                quantity: $line->quantity,
                unitPrice: $view->item->price,
                sortOrder: $sortOrder,
                note: $line->note,
            );
        }

        return $lines;
    }

    private function assertPaymentMethodEnabled(string $storeId, PaymentMethod $method): void
    {
        $enabled = $this->paymentMethods->forStore($storeId)[$method->value] ?? false;

        if (! $enabled) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                sprintf('Payment method %s is not enabled for this store', $method->value),
                [['field' => 'payment_method', 'message' => 'Phương thức thanh toán này hiện không khả dụng.']],
            );
        }
    }

    /**
     * `SCP-20261123-4821`: the format `docs/api/openapi.yaml` documents.
     *
     * A random four-digit suffix rather than a count, so two checkouts
     * arriving in the same instant do not both compute "next" from the same
     * stale count - the same bounded-retry-on-collision shape
     * `GenerateBankTransferInstructions::uniqueReference()` already uses for
     * the same reason, one call site over in Payment.
     */
    private function nextOrderCode(CarbonImmutable $at): string
    {
        $prefix = (string) config('commerce.order.code_prefix', 'SCP');
        $datePart = $at->format('Ymd');

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = sprintf('%s-%s-%04d', $prefix, $datePart, random_int(0, 9999));

            if (! OrderModel::query()->where('order_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new BusinessRuleViolation(
            ErrorCode::InternalError,
            'Could not generate a unique order code after several attempts',
        );
    }
}
