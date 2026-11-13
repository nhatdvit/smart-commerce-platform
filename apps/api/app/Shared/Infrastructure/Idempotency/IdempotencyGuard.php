<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency;

use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Infrastructure\Persistence\Model\IdempotencyRecord;
use App\Shared\Presentation\Http\ErrorCode;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes an operation safe to retry.
 *
 * A client that times out and retries must get the first outcome back, not a
 * second order (§6).
 *
 * ## The unique index is the mechanism
 *
 * The guard inserts the record first and lets the unique index on
 * `(scope, key)` reject the duplicate. Checking for an existing row and then
 * inserting would leave a window between the two statements where a concurrent
 * retry passes the check as well — exactly the race this exists to prevent.
 *
 * ## Which responses are stored
 *
 * Anything below 500 is stored and replayed, including client errors: a
 * rejected request is a real outcome, and replaying it is correct.
 *
 * A 5xx releases the key instead. The server failed for reasons that may be
 * transient, and a retry should get a genuine second attempt rather than a
 * permanently cached failure.
 *
 * ## Crash window
 *
 * If the process dies between claiming the key and storing the result, the
 * record stays `IN_PROGRESS` and later retries get 409 until it expires. That
 * is deliberate: refusing a retry is recoverable, whereas assuming the first
 * attempt failed and running it again could place a second order.
 *
 * ## Why the insert gets a savepoint
 *
 * PostgreSQL aborts the entire transaction when a statement fails, so a
 * duplicate insert made directly would poison the caller transaction: every
 * later statement returns 25P02, including the read that decides what to
 * answer. Checkout runs inside a transaction (section 16), so that is the
 * normal case here, not an edge case. Wrapping the insert in a nested
 * transaction makes Laravel issue a SAVEPOINT and roll back only to it,
 * leaving the caller transaction usable.
 */
final class IdempotencyGuard
{
    public function __construct(
        private readonly int $retentionHours = 24,
    ) {}

    /**
     * @param  Closure(): Response  $operation
     */
    public function run(string $scope, string $key, string $requestHash, Closure $operation): Response
    {
        $record = $this->claim($scope, $key, $requestHash);

        if ($record === null) {
            // Someone else holds the key. Decide why, and answer accordingly.
            return $this->resolveExisting($scope, $key, $requestHash);
        }

        try {
            $response = $operation();
        } catch (Throwable $e) {
            // The operation blew up. Release the key so a retry is a real
            // retry; leaving it claimed would brick this key for hours.
            $record->delete();

            throw $e;
        }

        $status = $response->getStatusCode();

        if ($status >= 500) {
            $record->delete();

            return $response;
        }

        $record->forceFill([
            'status' => IdempotencyRecord::STATUS_COMPLETED,
            'response_status' => $status,
            'response_body' => $this->decode($response),
        ])->save();

        return $response;
    }

    /** Returns null when the key is already taken. */
    private function claim(string $scope, string $key, string $requestHash): ?IdempotencyRecord
    {
        try {
            return DB::transaction(function () use ($scope, $key, $requestHash): IdempotencyRecord {
                /** @var IdempotencyRecord $record */
                $record = IdempotencyRecord::query()->create([
                    'scope' => $scope,
                    'key' => $key,
                    'request_hash' => $requestHash,
                    'status' => IdempotencyRecord::STATUS_IN_PROGRESS,
                    'expires_at' => now()->addHours($this->retentionHours),
                ]);

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function resolveExisting(string $scope, string $key, string $requestHash): Response
    {
        /** @var IdempotencyRecord|null $existing */
        $existing = IdempotencyRecord::query()
            ->where('scope', $scope)
            ->where('key', $key)
            ->first();

        // Expired and swept between the failed insert and this read; tell the
        // client to try again rather than guessing.
        if ($existing === null) {
            throw new BusinessRuleViolation(
                ErrorCode::RequestInProgress,
                'This request is already being processed. Retry shortly.',
            );
        }

        // Same key, different request. This is a client bug, and replaying the
        // stored response would answer a question that was never asked.
        if (! hash_equals($existing->request_hash, $requestHash)) {
            throw new BusinessRuleViolation(
                ErrorCode::IdempotencyKeyReused,
                'This Idempotency-Key was already used for a different request.',
            );
        }

        if ($existing->status !== IdempotencyRecord::STATUS_COMPLETED) {
            throw new BusinessRuleViolation(
                ErrorCode::RequestInProgress,
                'This request is already being processed. Retry shortly.',
            );
        }

        return response()->json(
            $existing->response_body ?? [],
            $existing->response_status ?? 200,
            // Lets a client tell a replay from a fresh execution, which is the
            // difference between "my retry worked" and "I created two".
            ['Idempotent-Replay' => 'true'],
        );
    }

    /** @return array<array-key, mixed>|null */
    private function decode(Response $response): ?array
    {
        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }
}
