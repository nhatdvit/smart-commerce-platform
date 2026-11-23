<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence;

use App\Modules\Identity\Application\StaffDirectory;
use App\Modules\Payment\Domain\Contract\PaymentRepository;
use App\Modules\Payment\Domain\Criteria\PaymentCriteria;
use App\Modules\Payment\Domain\Entity\Payment;
use App\Modules\Payment\Domain\Entity\PaymentDiscrepancy;
use App\Modules\Payment\Domain\Entity\PaymentEvent;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
use App\Modules\Payment\Infrastructure\Persistence\Model\Payment as PaymentModel;
use App\Modules\Payment\Infrastructure\Persistence\Model\PaymentDiscrepancy as PaymentDiscrepancyModel;
use App\Modules\Payment\Infrastructure\Persistence\Model\PaymentEvent as PaymentEventModel;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent implementation of the admin payment seam.
 *
 * The only class in the module that knows the payment tables exist, and where
 * rows become domain objects (CLAUDE.md section 16). Every read is
 * store-scoped by construction.
 */
final class EloquentPaymentRepository implements PaymentRepository
{
    public const int MAX_LIMIT = 100;

    public function __construct(private readonly StaffDirectory $staff) {}

    public function findInStore(string $storeId, string $paymentId): ?Payment
    {
        $row = $this->baseQuery($storeId)
            ->with([
                'paymentEvents' => static fn ($query) => $query->orderBy('created_at')->orderBy('id'),
                'discrepancies' => static fn ($query) => $query->orderBy('recorded_at')->orderBy('id'),
            ])
            ->whereKey($paymentId)
            ->first();

        return $row === null ? null : $this->toEntity($row, withHistory: true);
    }

    public function findForUpdate(string $storeId, string $paymentId): ?Payment
    {
        $row = $this->baseQuery($storeId)
            ->whereKey($paymentId)
            // The row, not the table: PostgreSQL locks exactly this payment
            // for the rest of the caller transaction, so a second confirmation
            // in flight at the same moment waits and then sees the committed
            // result instead of a stale PENDING.
            ->lockForUpdate()
            ->first();

        return $row === null ? null : $this->toEntity($row, withHistory: false);
    }

    public function paginate(string $storeId, PaymentCriteria $criteria): array
    {
        $limit = max(1, min($criteria->limit, self::MAX_LIMIT));
        $query = $this->baseQuery($storeId);

        if ($criteria->statuses !== []) {
            $query->whereIn('status', array_map(
                static fn (PaymentStatus $status): string => $status->value,
                $criteria->statuses,
            ));
        }

        if ($criteria->method !== null) {
            $query->where('method', $criteria->method->value);
        }

        if ($criteria->needsAttention === true) {
            $query->needsAttention();
        }

        if ($criteria->transferReference !== null && trim($criteria->transferReference) !== '') {
            // Exact match: what staff paste from a bank statement line, not a
            // search box.
            $query->where('transfer_reference', trim($criteria->transferReference));
        }

        // Cursor pagination over the primary key: UUIDv7 keys are
        // time-ordered, so "id descending" is "newest first" and a page
        // cannot shift under a client while new payments arrive.
        $after = $this->decodeCursor($criteria->cursor);

        if ($after !== null) {
            $query->where('id', '<', $after);
        }

        // A list row never eager-loads the discrepancies relation itself (it
        // would cost a query per page just to show a flag), so toEntity()
        // cannot tell "has none" from "never checked" through relationLoaded()
        // the way the detail read does. withExists() answers that in the same
        // query instead, as a plain boolean column.
        $rows = $query->withExists('discrepancies')->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $page = $hasMore ? $rows->take($limit) : $rows;
        $last = $page->last();

        return [
            'items' => array_values($page->map(
                fn (PaymentModel $row): Payment => $this->toEntity($row, withHistory: false),
            )->all()),
            'next_cursor' => $hasMore && $last instanceof PaymentModel ? $this->encodeCursor($last->id) : null,
            'has_more' => $hasMore,
        ];
    }

    public function transferReferenceExists(string $transferReference): bool
    {
        return PaymentModel::query()->where('transfer_reference', $transferReference)->exists();
    }

    public function recordConfirmation(Payment $payment, ?string $note): Payment
    {
        $row = PaymentModel::query()
            ->where('store_id', $payment->storeId)
            ->whereKey($payment->id)
            ->sole();

        $from = $row->status;

        $row->fill([
            'status' => $payment->status->value,
            'confirmed_amount' => $payment->confirmedAmount?->amount,
            'bank_transaction_reference' => $payment->bankTransactionReference,
            'confirmed_by' => $payment->confirmedBy,
            'confirmed_at' => $payment->confirmedAt,
            'paid_at' => $payment->confirmedAt,
        ])->save();

        PaymentEventModel::query()->create([
            'store_id' => $payment->storeId,
            'payment_id' => $payment->id,
            'event_type' => 'CONFIRMED',
            'from_status' => $from->value,
            'to_status' => $payment->status->value,
            'actor_id' => $payment->confirmedBy,
            'note' => $note,
            'created_at' => $payment->confirmedAt ?? now()->toImmutable(),
        ]);

        return $this->findInStore($payment->storeId, (string) $payment->id) ?? $payment;
    }

    public function recordDiscrepancy(Payment $payment, PaymentDiscrepancy $discrepancy): Payment
    {
        PaymentDiscrepancyModel::query()->create([
            'store_id' => $payment->storeId,
            'payment_id' => $payment->id,
            'type' => $discrepancy->type->value,
            'expected_amount' => $discrepancy->expectedAmount?->amount,
            'observed_amount' => $discrepancy->observedAmount?->amount,
            'note' => $discrepancy->note,
            'recorded_by' => $discrepancy->recordedBy,
            'recorded_at' => $discrepancy->recordedAt ?? now()->toImmutable(),
        ]);

        PaymentEventModel::query()->create([
            'store_id' => $payment->storeId,
            'payment_id' => $payment->id,
            'event_type' => 'FLAGGED',
            // A flag never changes status - see the domain entity's own note.
            'from_status' => $payment->status->value,
            'to_status' => $payment->status->value,
            'actor_id' => $discrepancy->recordedBy,
            'note' => $discrepancy->note,
            'created_at' => $discrepancy->recordedAt ?? now()->toImmutable(),
        ]);

        return $this->findInStore($payment->storeId, (string) $payment->id) ?? $payment;
    }

    public function attachBankTransferInstructions(
        string $storeId,
        string $paymentId,
        string $bankAccountId,
        string $bankBin,
        string $bankAccountNumber,
        string $bankAccountName,
        string $transferReference,
        string $qrPayload,
        ?DateTimeImmutable $expiresAt = null,
    ): Payment {
        $row = PaymentModel::query()
            ->where('store_id', $storeId)
            ->whereKey($paymentId)
            ->sole();

        $row->fill([
            'bank_account_id' => $bankAccountId,
            'bank_bin' => $bankBin,
            'bank_account_number' => $bankAccountNumber,
            'bank_account_name' => $bankAccountName,
            'transfer_reference' => $transferReference,
            'qr_payload' => $qrPayload,
            ...($expiresAt === null ? [] : ['expires_at' => $expiresAt]),
        ])->save();

        return $this->toEntity($row->refresh(), withHistory: false);
    }

    /** @return Builder<PaymentModel> */
    private function baseQuery(string $storeId): Builder
    {
        return PaymentModel::query()->where('store_id', $storeId);
    }

    private function toEntity(PaymentModel $row, bool $withHistory): Payment
    {
        if (! $withHistory) {
            return Payment::create(
                $row->id,
                $row->store_id,
                $row->order_id,
                $row->order_code,
                $row->customer_name,
                $row->method,
                $row->status,
                Money::vnd($row->amount),
                $row->confirmed_amount === null ? null : Money::vnd($row->confirmed_amount),
                $row->transfer_reference,
                $row->qr_payload,
                $row->bank_account_id,
                $row->bank_bin,
                $row->bank_account_number,
                $row->bank_account_name,
                $row->bank_transaction_reference,
                $row->confirmed_by,
                // Resolved only when history is loaded, same as Order's status
                // history: a list row does not pay for a name it never shows
                // alongside confirmed_by.
                null,
                $row->confirmed_at,
                $row->expires_at,
                $row->created_at,
                // withExists() on the list query sets this as a plain
                // attribute rather than a loaded relation; a caller that
                // skipped it too (e.g. findForUpdate(), which never needs the
                // flag) falls back to relationLoaded() the same as before.
                $row->discrepancies_exists ?? ($row->relationLoaded('discrepancies') && $row->discrepancies->isNotEmpty()),
            );
        }

        $names = $this->staff->namesByIds($this->actorIdsIn($row));

        $events = array_values($row->paymentEvents->map(
            static fn (PaymentEventModel $event): PaymentEvent => new PaymentEvent(
                $event->event_type,
                $event->from_status,
                $event->to_status,
                $event->actor_id,
                $event->actor_id === null ? null : ($names[$event->actor_id] ?? null),
                $event->note,
                $event->created_at,
            ),
        )->all());

        $discrepancies = array_values($row->discrepancies->map(
            static fn (PaymentDiscrepancyModel $discrepancy): PaymentDiscrepancy => new PaymentDiscrepancy(
                $discrepancy->type,
                $discrepancy->expected_amount === null ? null : Money::vnd($discrepancy->expected_amount),
                $discrepancy->observed_amount === null ? null : Money::vnd($discrepancy->observed_amount),
                $discrepancy->note,
                $discrepancy->recorded_by,
                $discrepancy->recorded_by === null ? null : ($names[$discrepancy->recorded_by] ?? null),
                $discrepancy->recorded_at,
            ),
        )->all());

        return Payment::create(
            $row->id,
            $row->store_id,
            $row->order_id,
            $row->order_code,
            $row->customer_name,
            $row->method,
            $row->status,
            Money::vnd($row->amount),
            $row->confirmed_amount === null ? null : Money::vnd($row->confirmed_amount),
            $row->transfer_reference,
            $row->qr_payload,
            $row->bank_account_id,
            $row->bank_bin,
            $row->bank_account_number,
            $row->bank_account_name,
            $row->bank_transaction_reference,
            $row->confirmed_by,
            $row->confirmed_by === null ? null : ($names[$row->confirmed_by] ?? null),
            $row->confirmed_at,
            $row->expires_at,
            $row->created_at,
            $row->discrepancies->isNotEmpty(),
            $events,
            $discrepancies,
        );
    }

    /** @return list<string> */
    private function actorIdsIn(PaymentModel $row): array
    {
        $ids = [];

        if ($row->confirmed_by !== null) {
            $ids[] = $row->confirmed_by;
        }

        foreach ($row->paymentEvents as $event) {
            if ($event->actor_id !== null) {
                $ids[] = $event->actor_id;
            }
        }

        foreach ($row->discrepancies as $discrepancy) {
            if ($discrepancy->recorded_by !== null) {
                $ids[] = $discrepancy->recorded_by;
            }
        }

        return array_values(array_unique($ids));
    }

    /** Opaque to the client: a cursor is a position, not a value to construct. */
    private function encodeCursor(string $id): string
    {
        return rtrim(strtr(base64_encode($id), '+/', '-_'), '=');
    }

    private function decodeCursor(?string $cursor): ?string
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        return is_string($decoded) && preg_match('/^[0-9a-f-]{36}$/i', $decoded) === 1
            ? $decoded
            : null;
    }
}
