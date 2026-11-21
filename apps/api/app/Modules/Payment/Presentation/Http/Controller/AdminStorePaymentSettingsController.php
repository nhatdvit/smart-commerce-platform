<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Controller;

use App\Modules\Payment\Application\Action\UpdateBankAccount;
use App\Modules\Payment\Application\Action\UpdatePaymentMethods;
use App\Modules\Payment\Domain\Contract\BankAccountRepository;
use App\Modules\Payment\Domain\Contract\PaymentMethodRepository;
use App\Modules\Payment\Domain\Entity\Payment;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Presentation\Http\Request\UpdateBankAccountRequest;
use App\Modules\Payment\Presentation\Http\Request\UpdatePaymentMethodsRequest;
use App\Modules\Payment\Presentation\Http\Resource\StoreBankAccountResource;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\Controllers\Controller;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Store-level payment configuration: where transfers land, and which of the
 * two MVP methods a store currently accepts.
 *
 * Grouped in one controller because the designed contract couples them: a
 * store cannot usefully enable `BANK_TRANSFER` without an account configured,
 * and `UpdatePaymentMethods` enforces exactly that (CLAUDE.md section 6).
 */
final class AdminStorePaymentSettingsController extends Controller
{
    public function __construct(
        private readonly BankAccountRepository $bankAccounts,
        private readonly PaymentMethodRepository $paymentMethods,
    ) {}

    public function showBankAccount(StoreContext $storeContext): JsonResponse
    {
        Gate::authorize('manageBankAccount', Payment::class);

        $account = $this->bankAccounts->findDefaultInStore($storeContext->require()->id);

        if ($account === null) {
            throw new BusinessRuleViolation(
                ErrorCode::ResourceNotFound,
                'No bank account is configured for this store yet',
            );
        }

        return (new StoreBankAccountResource($account))->response();
    }

    public function updateBankAccount(
        UpdateBankAccountRequest $request,
        StoreContext $storeContext,
        UpdateBankAccount $updateBankAccount,
    ): JsonResponse {
        Gate::authorize('manageBankAccount', Payment::class);

        $account = $updateBankAccount->handle(
            $storeContext->require()->id,
            $request->toInput(),
            $this->actorId($request),
        );

        return (new StoreBankAccountResource($account))->response();
    }

    /**
     * The current switches, for the toggle form to open against.
     *
     * Same permission as the write: a store's own method configuration is not
     * a day-to-day operational read, the same reasoning as the bank-account
     * pair above.
     */
    public function showPaymentMethods(StoreContext $storeContext): JsonResponse
    {
        Gate::authorize('updateMethods', Payment::class);

        $storeId = $storeContext->require()->id;

        return new JsonResponse([
            'items' => $this->renderMethods(
                $this->paymentMethods->forStore($storeId),
                $this->bankAccounts->findDefaultInStore($storeId) !== null,
            ),
        ]);
    }

    public function updatePaymentMethods(
        UpdatePaymentMethodsRequest $request,
        StoreContext $storeContext,
        UpdatePaymentMethods $updatePaymentMethods,
    ): JsonResponse {
        Gate::authorize('updateMethods', Payment::class);

        $storeId = $storeContext->require()->id;
        $current = $this->paymentMethods->forStore($storeId);
        $enabled = $updatePaymentMethods->handle(
            $storeId,
            $request->toEnabledMap($current),
            $this->actorId($request),
        );

        return new JsonResponse([
            'items' => $this->renderMethods(
                $enabled,
                $this->bankAccounts->findDefaultInStore($storeId) !== null,
            ),
        ]);
    }

    /**
     * @param  array<string, bool>  $enabled
     * @return list<array{method: string, enabled: bool, label: string, description: null, unavailable_reason: string|null}>
     */
    private function renderMethods(array $enabled, bool $hasBankAccount): array
    {
        return array_values(array_map(
            static fn (PaymentMethod $method): array => [
                'method' => $method->value,
                'enabled' => $enabled[$method->value] ?? false,
                'label' => $method->label(),
                'description' => null,
                'unavailable_reason' => $method === PaymentMethod::BankTransfer
                    && ! ($enabled[$method->value] ?? false)
                    && ! $hasBankAccount
                    ? 'Cần cấu hình tài khoản ngân hàng trước khi bật chuyển khoản.'
                    : null,
            ],
            PaymentMethod::cases(),
        ));
    }

    private function actorId(UpdateBankAccountRequest|UpdatePaymentMethodsRequest $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        if (! is_string($id)) {
            throw new BusinessRuleViolation(ErrorCode::Unauthenticated, 'Authentication required');
        }

        return $id;
    }
}
