<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Infrastructure\Persistence\Model\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Model\ProductVariant;
use App\Modules\Identity\Domain\Enum\MembershipStatus;
use App\Modules\Identity\Infrastructure\Persistence\Model\Role;
use App\Modules\Identity\Infrastructure\Persistence\Model\StoreMember;
use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use App\Modules\Menu\Infrastructure\Persistence\Model\MenuItem;
use App\Modules\Order\Domain\Enum\FulfillmentType;
use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Modules\Order\Infrastructure\Persistence\Model\Order;
use App\Modules\Order\Infrastructure\Persistence\Model\OrderItem;
use App\Modules\Order\Infrastructure\Persistence\Model\OrderStatusChange;
use App\Modules\Payment\Application\Action\GenerateBankTransferInstructions;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
use App\Modules\Payment\Infrastructure\Persistence\Model\BankAccount;
use App\Modules\Payment\Infrastructure\Persistence\Model\Payment;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Shared\Domain\ValueObject\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A morning's worth of orders, for local development and staging.
 *
 * Why this exists. Orders are created by the customer checkout, which is the
 * next slice; until it lands there is no way to get an order into the database,
 * and an operations queue with nothing in it cannot be worked on or reviewed.
 * This seeder fills that gap and nothing more - it is not a checkout, and it
 * deliberately skips everything checkout will own: idempotency keys, stock
 * reservations, repricing against the live menu.
 *
 * Never runs in production: DatabaseSeeder gates it, and this class refuses
 * anyway. Invented orders in a real shop's database would corrupt its takings.
 *
 * Idempotent: a store that already has orders is left alone, so re-running the
 * seeders after adding dishes by hand does not pile up a second breakfast.
 */
final class DemoOrderSeeder extends Seeder
{
    /**
     * The demo morning: one order per state staff actually see.
     *
     * Chosen so every branch of the screen has something to show - an unpaid
     * transfer, a cash order waiting, one in the kitchen, one on the counter,
     * one finished and one cancelled.
     *
     * @var list<array{
     *     status: OrderStatus,
     *     method: PaymentMethod,
     *     settled: bool,
     *     customer: string,
     *     phone: string,
     *     pickup: string,
     *     lines: list<array{int, int}>,
     *     reason?: string,
     *     delivery?: string,
     * }>
     */
    private const array DEMO_ORDERS = [
        [
            'status' => OrderStatus::PendingPayment,
            'method' => PaymentMethod::BankTransfer,
            'settled' => false,
            'customer' => 'Vũ Hồng Nhung',
            'phone' => '0903123456',
            'pickup' => '08:15',
            'lines' => [[1, 2], [2, 2]],
        ],
        [
            'status' => OrderStatus::Placed,
            'method' => PaymentMethod::Cash,
            'settled' => false,
            'customer' => 'Đỗ Thanh Hà',
            'phone' => '0903123457',
            'pickup' => '08:00',
            'lines' => [[0, 1]],
        ],
        [
            'status' => OrderStatus::Confirmed,
            'method' => PaymentMethod::BankTransfer,
            'settled' => true,
            'customer' => 'Nguyễn Minh Anh',
            'phone' => '0903123458',
            'pickup' => '07:15',
            'lines' => [[0, 2], [2, 1]],
        ],
        [
            'status' => OrderStatus::Preparing,
            'method' => PaymentMethod::Cash,
            'settled' => false,
            'customer' => 'Trần Quốc Huy',
            'phone' => '0903123459',
            'pickup' => '07:30',
            'lines' => [[1, 1], [2, 1]],
        ],
        [
            'status' => OrderStatus::Ready,
            'method' => PaymentMethod::BankTransfer,
            'settled' => true,
            'customer' => 'Lê Thảo Vy',
            'phone' => '0903123460',
            'pickup' => '07:45',
            'lines' => [[0, 2]],
        ],
        [
            // Completed with the cash still PENDING is the state that matters
            // most: handing over breakfast is not collecting money.
            'status' => OrderStatus::Completed,
            'method' => PaymentMethod::Cash,
            'settled' => false,
            'customer' => 'Phạm Hoàng Long',
            'phone' => '0903123461',
            'pickup' => '07:00',
            'lines' => [[3, 1], [2, 1]],
            'delivery' => '45 Lê Lợi, Quận 1, TP. Hồ Chí Minh',
        ],
        [
            'status' => OrderStatus::Cancelled,
            'method' => PaymentMethod::Cash,
            'settled' => false,
            'customer' => 'Khách lẻ',
            'phone' => '0903123462',
            'pickup' => '07:20',
            'lines' => [[1, 1]],
            'reason' => 'Khách đổi ý, hủy trước khi chế biến',
        ],
    ];

    /**
     * Dishes to fall back on when the store's menu is still empty.
     *
     * An order line is a snapshot, and `product_id` is nullable precisely so a
     * line stays readable without master data (CLAUDE.md section 6). A fresh
     * database has no products until someone adds them on the menu screen, and
     * these orders are "from before that" - which the schema supports exactly
     * as written.
     *
     * @var list<array{name: string, price: int}>
     */
    private const array FALLBACK_DISHES = [
        ['name' => 'Bánh mì pate trứng', 'price' => 25_000],
        ['name' => 'Xôi gà xé', 'price' => 30_000],
        ['name' => 'Cà phê sữa đá', 'price' => 18_000],
        ['name' => 'Phở bò', 'price' => 45_000],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoOrderSeeder must never run in production');
        }

        Store::query()->each(function (Store $store): void {
            if (Order::query()->where('store_id', $store->id)->exists()) {
                return;
            }

            // A bank-transfer order needs somewhere to generate a VietQR
            // against; see ensureBankAccount().
            $this->ensureBankAccount($store->id);

            $dishes = $this->dishesOf($store->id);
            $actorId = $this->ensureManager($store->id);
            $timezone = $store->timezone;

            foreach (self::DEMO_ORDERS as $demo) {
                DB::transaction(function () use ($store, $demo, $dishes, $actorId, $timezone): void {
                    $this->createOrder($store->id, $timezone, $demo, $dishes, $actorId);
                });
            }
        });

        $this->command?->info('Demo orders seeded for every store (local and staging only).');
    }

    /**
     * A receiving account for the store, if it does not already have one.
     *
     * Without this a `BANK_TRANSFER` order has nowhere to generate a VietQR
     * against - {@see GenerateBankTransferInstructions} refuses one with no
     * configured account, exactly as it should for a real store that has not
     * set one up yet. A demo store has, so its orders can show a real,
     * scannable code rather than an order permanently stuck with none.
     */
    private function ensureBankAccount(string $storeId): void
    {
        $exists = BankAccount::query()
            ->where('store_id', $storeId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->exists();

        if ($exists) {
            return;
        }

        BankAccount::query()->create([
            'store_id' => $storeId,
            'bank_bin' => '970436',
            'bank_name' => 'Vietcombank',
            'account_number' => '0071000123456',
            'account_name' => 'HO KINH DOANH BEP NHA MINH',
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    /**
     * What this store sells, for the snapshots.
     *
     * Real menu items when there are any, so `product_id` and `variant_id` are
     * populated and future reports can group by dish. The fallback list
     * otherwise; see the note on FALLBACK_DISHES.
     *
     * @return list<array{name: string, price: int, product_id: string|null, variant_id: string|null}>
     */
    private function dishesOf(string $storeId): array
    {
        $items = MenuItem::query()
            ->where('store_id', $storeId)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(count(self::FALLBACK_DISHES))
            ->get();

        if ($items->isEmpty()) {
            return $this->fallbackDishes();
        }

        $variants = ProductVariant::query()
            ->whereIn('id', $items->pluck('product_variant_id')->all())
            ->get()
            ->keyBy('id');

        $products = Product::query()
            ->whereIn('id', $variants->pluck('product_id')->all())
            ->get()
            ->keyBy('id');

        $dishes = [];

        foreach ($items as $index => $item) {
            $variant = $variants->get($item->product_variant_id);

            if (! $variant instanceof ProductVariant) {
                continue;
            }

            $product = $products->get($variant->product_id);
            $fallback = self::FALLBACK_DISHES[$index] ?? self::FALLBACK_DISHES[0];

            $dishes[] = [
                'name' => $product instanceof Product ? $product->name : $fallback['name'],
                // The menu price, which is what a customer was charged.
                'price' => $item->selling_price_amount,
                'product_id' => $variant->product_id,
                'variant_id' => $variant->id,
            ];
        }

        // Menu items whose variant has since been deleted leave nothing to
        // snapshot, so fall back rather than seed an order with no lines.
        if ($dishes === []) {
            return $this->fallbackDishes();
        }

        // A store with one dish on its menu would otherwise get a morning of
        // orders for that one dish, which demonstrates nothing. Pad with the
        // fallback snapshots so the queue looks like a real service.
        $fallbacks = $this->fallbackDishes();

        while (count($dishes) < count($fallbacks)) {
            $dishes[] = $fallbacks[count($dishes)];
        }

        return $dishes;
    }

    /**
     * @return list<array{name: string, price: int, product_id: string|null, variant_id: string|null}>
     */
    private function fallbackDishes(): array
    {
        return array_map(
            static fn (array $dish): array => [
                'name' => $dish['name'],
                'price' => $dish['price'],
                'product_id' => null,
                'variant_id' => null,
            ],
            self::FALLBACK_DISHES,
        );
    }

    /** The demo manager, so the status history names somebody plausible. */
    private function managerOf(string $storeId): ?string
    {
        $roleId = Role::query()->where('code', 'STORE_MANAGER')->value('id');

        if (! is_string($roleId)) {
            return null;
        }

        $userId = StoreMember::query()
            ->where('store_id', $storeId)
            ->where('role_id', $roleId)
            ->value('user_id');

        return is_string($userId) ? $userId : null;
    }

    /**
     * A manager to act as, creating one if this store genuinely has none.
     *
     * A settled demo payment needs a named confirmer the same CHECK
     * constraint a real confirmation does ({@see createOrder()}); a store
     * seeded with no staff at all must still get a valid morning rather than
     * a constraint violation, the same reasoning as {@see ensureBankAccount()}.
     */
    private function ensureManager(string $storeId): string
    {
        $existing = $this->managerOf($storeId);

        if ($existing !== null) {
            return $existing;
        }

        $roleId = Role::query()->where('code', 'STORE_MANAGER')->value('id');

        if (! is_string($roleId)) {
            throw new RuntimeException('RolePermissionSeeder must run before DemoOrderSeeder');
        }

        $manager = User::factory()->create(['full_name' => 'Thu ngân Demo']);

        StoreMember::query()->create([
            'store_id' => $storeId,
            'user_id' => $manager->id,
            'role_id' => $roleId,
            'status' => MembershipStatus::Active->value,
        ]);

        return $manager->id;
    }

    /**
     * @param  array{
     *     status: OrderStatus,
     *     method: PaymentMethod,
     *     settled: bool,
     *     customer: string,
     *     phone: string,
     *     pickup: string,
     *     lines: list<array{int, int}>,
     *     reason?: string,
     *     delivery?: string,
     * }  $demo
     * @param  list<array{name: string, price: int, product_id: string|null, variant_id: string|null}>  $dishes
     */
    private function createOrder(
        string $storeId,
        string $timezone,
        array $demo,
        array $dishes,
        ?string $actorId,
    ): void {
        // The pickup time is read in the store's own timezone and stored in
        // UTC, which is the rule for every schedule in the system
        // (CLAUDE.md section 6).
        $pickupAt = CarbonImmutable::now($timezone)
            ->setTimeFromTimeString($demo['pickup'])
            ->utc();

        $status = $demo['status'];
        $path = $this->pathTo($status);
        $placedAt = $status === OrderStatus::PendingPayment ? null : $pickupAt->subMinutes(25);

        $lines = [];
        $subtotal = 0;

        foreach ($demo['lines'] as [$dishIndex, $quantity]) {
            $dish = $dishes[$dishIndex] ?? $dishes[0];
            $lineTotal = $dish['price'] * $quantity;
            $subtotal += $lineTotal;

            $lines[] = [
                'product_id' => $dish['product_id'],
                'product_variant_id' => $dish['variant_id'],
                'product_name' => $dish['name'],
                'variant_name' => 'Mặc định',
                'quantity' => $quantity,
                'unit_price_amount' => $dish['price'],
                'modifiers_total_amount' => 0,
                'line_total_amount' => $lineTotal,
                'currency' => 'VND',
                'note' => null,
                'sort_order' => count($lines),
            ];
        }

        $deliveryFee = isset($demo['delivery']) ? 15_000 : 0;

        /** @var Order $order */
        $order = Order::query()->create([
            'store_id' => $storeId,
            'order_code' => $this->nextOrderCode($pickupAt),
            'customer_id' => null,
            'contact_name' => $demo['customer'],
            'contact_phone' => $demo['phone'],
            'contact_email' => null,
            'status' => $status->value,
            'fulfillment_type' => isset($demo['delivery'])
                ? FulfillmentType::Delivery->value
                : FulfillmentType::Pickup->value,
            'scheduled_at' => $pickupAt,
            'delivery_address_line' => $demo['delivery'] ?? null,
            'note' => null,
            'subtotal_amount' => $subtotal,
            'discount_amount' => 0,
            'delivery_fee_amount' => $deliveryFee,
            'tax_amount' => 0,
            'total_amount' => $subtotal + $deliveryFee,
            'currency' => 'VND',
            'placed_at' => $placedAt,
        ]);

        foreach ($lines as $line) {
            OrderItem::query()->create(['order_id' => $order->id, 'store_id' => $storeId] + $line);
        }

        $this->seedHistory($order, $storeId, $path, $placedAt ?? $pickupAt, $actorId, $demo['reason'] ?? null);

        $total = $subtotal + $deliveryFee;
        $confirmedAt = $pickupAt->subMinutes(20);

        /** @var Payment $payment */
        $payment = Payment::query()->create([
            'store_id' => $storeId,
            'order_id' => $order->id,
            'order_code' => $order->order_code,
            'method' => $demo['method']->value,
            'status' => $demo['settled'] ? PaymentStatus::Paid->value : PaymentStatus::Pending->value,
            'amount' => $total,
            'currency' => 'VND',
            // Only a transfer has a window to miss; cash is collected in person.
            'expires_at' => $demo['method'] === PaymentMethod::BankTransfer && ! $demo['settled']
                ? $pickupAt->addMinutes((int) config('commerce.bank_transfer.expiry_minutes', 30))
                : null,
            // Set only when the money was really seen, which for demo data
            // means "this order is one a cashier already confirmed" - and the
            // CHECK constraint on `payments` refuses PAID without a named
            // confirmer, same as the real confirm endpoint would.
            'paid_at' => $demo['settled'] ? $confirmedAt : null,
            'confirmed_by' => $demo['settled'] ? $actorId : null,
            'confirmed_at' => $demo['settled'] ? $confirmedAt : null,
            'confirmed_amount' => $demo['settled'] ? $total : null,
        ]);

        // A transfer order gets the same VietQR a customer would actually
        // scan - generated through the real use case, not hand-built data,
        // so the admin reconciliation screen has a genuine reference and
        // payload to show and match against (CLAUDE.md section 6).
        if ($demo['method'] === PaymentMethod::BankTransfer) {
            app(GenerateBankTransferInstructions::class)->handle(
                $storeId,
                $payment->id,
                $order->order_code,
                Money::vnd($total),
            );
        }
    }

    /**
     * The route this order took to its current status.
     *
     * Written out rather than derived, because the history is a record of what
     * happened and a demo order should look like it really happened - the
     * screen shows this timeline.
     *
     * @return list<OrderStatus>
     */
    private function pathTo(OrderStatus $status): array
    {
        return match ($status) {
            OrderStatus::PendingPayment => [OrderStatus::PendingPayment],
            OrderStatus::Placed => [OrderStatus::Placed],
            OrderStatus::Confirmed => [OrderStatus::Placed, OrderStatus::Confirmed],
            OrderStatus::Preparing => [
                OrderStatus::Placed,
                OrderStatus::Confirmed,
                OrderStatus::Preparing,
            ],
            OrderStatus::Ready => [
                OrderStatus::Placed,
                OrderStatus::Confirmed,
                OrderStatus::Preparing,
                OrderStatus::Ready,
            ],
            OrderStatus::Completed => [
                OrderStatus::Placed,
                OrderStatus::Confirmed,
                OrderStatus::Preparing,
                OrderStatus::Ready,
                OrderStatus::Completed,
            ],
            OrderStatus::Cancelled => [OrderStatus::Placed, OrderStatus::Cancelled],
            OrderStatus::Expired => [OrderStatus::PendingPayment, OrderStatus::Expired],
        };
    }

    /**
     * @param  list<OrderStatus>  $path
     */
    private function seedHistory(
        Order $order,
        string $storeId,
        array $path,
        CarbonImmutable $from,
        ?string $actorId,
        ?string $reason,
    ): void {
        $previous = null;
        $at = $from;
        $last = count($path) - 1;

        foreach ($path as $index => $status) {
            OrderStatusChange::query()->create([
                'store_id' => $storeId,
                'order_id' => $order->id,
                'from_status' => $previous?->value,
                'to_status' => $status->value,
                // The first row is the customer placing the order; the moves
                // after it are staff decisions.
                'actor_id' => $previous === null ? null : $actorId,
                'reason' => $index === $last ? $reason : null,
                'created_at' => $at,
            ]);

            $previous = $status;
            $at = $at->addMinutes(5);
        }
    }

    /**
     * `SCP-20261120-0042`: the format the contract documents.
     *
     * Counted off the table rather than from one, so re-seeding a second store
     * on the same day cannot collide with the first - the code is unique
     * platform-wide. Checkout will own code generation properly when it lands;
     * this is the demo-data version of it.
     */
    private function nextOrderCode(CarbonImmutable $placedAt): string
    {
        $prefix = (string) config('commerce.order.code_prefix', 'SCP');

        return sprintf(
            '%s-%s-%04d',
            $prefix,
            $placedAt->format('Ymd'),
            Order::query()->count() + 1,
        );
    }
}
