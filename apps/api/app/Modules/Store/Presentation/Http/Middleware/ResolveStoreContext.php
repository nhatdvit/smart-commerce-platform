<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Http\Middleware;

use App\Modules\Store\Application\StoreContext;
use App\Modules\Store\Infrastructure\Persistence\Model\Store as StoreModel;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves which store this request acts on, from trusted sources only.
 *
 * Order of precedence:
 *   1. a route parameter, e.g. /admin/stores/{store}/...
 *   2. the request host, matched against the configured host-to-store map
 *   3. the configured single-store default, for a single-store deployment
 *
 * A query string is never consulted. `?store_id=` is attacker-controlled, and
 * trusting it is the classic broken-object-authorization bug: it would let a
 * cashier at one store read another store's orders (CLAUDE.md §8).
 *
 * Resolving a store grants nothing. EnsureStorePermission still has to confirm
 * that this user has an active membership and the required permission.
 */
final class ResolveStoreContext
{
    public function __construct(private readonly StoreContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $store = $this->fromRoute($request)
            ?? $this->fromHost($request)
            ?? $this->fromDefault();

        if ($store === null) {
            throw new BusinessRuleViolation(
                ErrorCode::InvalidStoreContext,
                'This endpoint requires a store context, and none could be resolved',
            );
        }

        if (! $store->status->canAcceptOrders()) {
            // An inactive store is reported as missing rather than disabled:
            // its existence is not public information.
            throw new BusinessRuleViolation(
                ErrorCode::ResourceNotFound,
                'Store not found',
            );
        }

        $this->context->set($store);

        return $next($request);
    }

    private function fromRoute(Request $request): ?StoreModel
    {
        $parameter = $request->route('store');

        if ($parameter instanceof StoreModel) {
            // Route-model binding already resolved and scoped it.
            return $parameter;
        }

        if (! is_string($parameter) || $parameter === '') {
            return null;
        }

        return StoreModel::query()
            ->where('id', $parameter)
            ->orWhere('slug', $parameter)
            ->first();
    }

    private function fromHost(Request $request): ?StoreModel
    {
        /** @var array<string, string> $map */
        $map = (array) config('commerce.store_hosts', []);
        $slug = $map[$request->getHost()] ?? null;

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return StoreModel::query()->where('slug', $slug)->first();
    }

    private function fromDefault(): ?StoreModel
    {
        $slug = config('commerce.default_store_slug');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return StoreModel::query()->where('slug', $slug)->first();
    }
}
