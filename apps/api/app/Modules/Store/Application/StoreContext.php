<?php

declare(strict_types=1);

namespace App\Modules\Store\Application;

use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use RuntimeException;

/**
 * The store this request is acting on.
 *
 * Resolved once per request by ResolveStoreContext and read by any module that
 * needs store scope. Nothing writes to it except that middleware, so no
 * request handler can quietly switch stores half way through.
 *
 * Exported deliberately: other modules depend on this application service
 * rather than querying the stores table themselves (CLAUDE.md section 3).
 */
final class StoreContext
{
    private ?Store $store = null;

    public function set(Store $store): void
    {
        if ($this->store !== null && $this->store->id !== $store->id) {
            throw new RuntimeException('Store context is already resolved for this request');
        }

        $this->store = $store;
    }

    public function has(): bool
    {
        return $this->store !== null;
    }

    /** Null when the endpoint is not store-scoped, e.g. a health probe. */
    public function get(): ?Store
    {
        return $this->store;
    }

    /** For code that cannot proceed without a store; fails loudly if unset. */
    public function require(): Store
    {
        if ($this->store === null) {
            throw new RuntimeException(
                'No store context. Apply the store.resolve middleware to this route.',
            );
        }

        return $this->store;
    }

    public function id(): ?string
    {
        return $this->store?->id;
    }

    /** The timezone every schedule and daily quota for this request is read in. */
    public function timezone(): string
    {
        if ($this->store === null) {
            return (string) config('commerce.default_store_timezone');
        }

        return $this->store->timezone;
    }
}
