<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit;

/**
 * One line of the audit trail.
 *
 * Plain data, no framework: any module can build one without reaching for a
 * facade, and it can be asserted on in a unit test.
 *
 * `action` follows `module.entity.verb` — `payment.confirm`,
 * `store.bank-account.update`, `identity.member.suspend`. Using the same shape
 * everywhere is what makes the trail searchable later.
 */
final readonly class AuditEntry
{
    /**
     * @param  array<array-key, mixed>  $changes  Redacted on the way in; never pass raw request input.
     */
    public function __construct(
        public string $action,
        public string $entityType,
        public string $entityId,
        public array $changes = [],
        public ?string $storeId = null,
        public ?string $actorId = null,
    ) {}

    /** @param array<array-key, mixed> $changes */
    public static function for(
        string $action,
        string $entityType,
        string $entityId,
        array $changes = [],
    ): self {
        return new self($action, $entityType, $entityId, $changes);
    }

    /** Attach the store this action belongs to, for store-scoped queries later. */
    public function inStore(?string $storeId): self
    {
        return new self(
            $this->action,
            $this->entityType,
            $this->entityId,
            $this->changes,
            $storeId,
            $this->actorId,
        );
    }

    /** Override the actor. Normally resolved from the authenticated user. */
    public function by(?string $actorId): self
    {
        return new self(
            $this->action,
            $this->entityType,
            $this->entityId,
            $this->changes,
            $this->storeId,
            $actorId,
        );
    }
}
