<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Controller;

use App\Modules\Payment\Domain\Contract\PaymentMethodRepository;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Presentation\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * What a customer may pay with at this store, enabled or not.
 *
 * Public: a store's accepted payment methods are what the checkout screen
 * shows before anyone signs in. A method that is listed but `enabled: false`
 * is deliberate - the storefront can explain why a choice is greyed out
 * instead of silently hiding it (`docs/api/openapi.yaml`).
 */
final class PublicPaymentMethodController extends Controller
{
    public function __construct(private readonly PaymentMethodRepository $methods) {}

    public function index(StoreContext $storeContext): JsonResponse
    {
        $enabled = $this->methods->forStore($storeContext->require()->id);

        $items = array_map(
            static fn (PaymentMethod $method): array => [
                'method' => $method->value,
                'enabled' => $enabled[$method->value] ?? false,
                'label' => $method->label(),
                'description' => null,
                'unavailable_reason' => ($enabled[$method->value] ?? false)
                    ? null
                    : 'Phương thức này hiện chưa được cửa hàng bật.',
            ],
            PaymentMethod::cases(),
        );

        return new JsonResponse(['items' => array_values($items)]);
    }
}
