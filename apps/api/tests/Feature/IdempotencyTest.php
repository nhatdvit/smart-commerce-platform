<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\Model\IdempotencyRecord;
use Illuminate\Support\Facades\Route;

/*
 * A client that times out and retries must get the first outcome back, not a
 * second order (§6). These tests exercise the middleware end to end.
 *
 * Each test counts how many times the handler actually ran. Asserting only on
 * the response would pass even if the operation executed twice, which is the
 * exact failure this middleware exists to prevent.
 */

/**
 * Registers a route guarded by the middleware.
 *
 * $runs is taken by reference because a route closure is not bound to the test
 * case, so $this->runs inside it would not reach the test.
 */
function guardedRoute(string $scope, int &$runs, int $status = 201): void
{
    Route::middleware(['api', "idempotent:{$scope}"])
        ->post("/test/{$scope}", function () use (&$runs, $status) {
            $runs++;

            return response()->json(['id' => 'result-'.$runs], $status);
        });
}

/** The fingerprint the middleware computes, reproduced so a test can pre-claim a key. */
function fingerprintFor(string $scope, array $body): string
{
    return hash('sha256', implode('|', ['POST', "test/{$scope}", '', json_encode($body)]));
}

it('runs the operation once and replays the stored result on retry', function (): void {
    $runs = 0;
    guardedRoute('create', $runs);

    $first = $this->postJson('/test/create', ['qty' => 1], ['Idempotency-Key' => 'key-aaaaaaaa'])
        ->assertCreated();
    $second = $this->postJson('/test/create', ['qty' => 1], ['Idempotency-Key' => 'key-aaaaaaaa'])
        ->assertCreated();

    expect($runs)->toBe(1)
        ->and($second->json('id'))->toBe($first->json('id'))
        // Lets a client tell a replay from a fresh execution.
        ->and($second->headers->get('Idempotent-Replay'))->toBe('true');
});

it('rejects the same key used for a different request', function (): void {
    $runs = 0;
    guardedRoute('create', $runs);

    $this->postJson('/test/create', ['qty' => 1], ['Idempotency-Key' => 'key-aaaaaaaa'])->assertCreated();

    $this->postJson('/test/create', ['qty' => 99], ['Idempotency-Key' => 'key-aaaaaaaa'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

    expect($runs)->toBe(1);
});

it('requires the header on a protected operation', function (): void {
    $runs = 0;
    guardedRoute('create', $runs);

    $this->postJson('/test/create', ['qty' => 1])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    expect($runs)->toBe(0);
});

it('rejects a malformed key rather than storing it', function (): void {
    $runs = 0;
    guardedRoute('create', $runs);

    $this->postJson('/test/create', ['qty' => 1], ['Idempotency-Key' => 'short'])
        ->assertStatus(422);

    expect(IdempotencyRecord::query()->count())->toBe(0);
});

it('tells a concurrent caller to retry rather than running the operation again', function (): void {
    $runs = 0;
    guardedRoute('create', $runs);

    // The first request claimed the key and has not finished. Same request, so
    // the hash matches and this is a genuine in-flight case, not key reuse.
    IdempotencyRecord::query()->create([
        'scope' => 'create',
        'key' => 'key-aaaaaaaa',
        'request_hash' => fingerprintFor('create', ['qty' => 1]),
        'status' => IdempotencyRecord::STATUS_IN_PROGRESS,
        'expires_at' => now()->addHour(),
    ]);

    $this->postJson('/test/create', ['qty' => 1], ['Idempotency-Key' => 'key-aaaaaaaa'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'REQUEST_IN_PROGRESS');

    expect($runs)->toBe(0);
});

it('stores a client error, because a rejection is a real outcome', function (): void {
    $runs = 0;
    guardedRoute('reject', $runs, 422);

    $this->postJson('/test/reject', [], ['Idempotency-Key' => 'key-bbbbbbbb'])->assertStatus(422);
    $this->postJson('/test/reject', [], ['Idempotency-Key' => 'key-bbbbbbbb'])->assertStatus(422);

    expect($runs)->toBe(1);
});

it('releases the key after a server error so a retry is a real retry', function (): void {
    $runs = 0;
    guardedRoute('boom', $runs, 503);

    $this->postJson('/test/boom', [], ['Idempotency-Key' => 'key-cccccccc'])->assertStatus(503);

    expect(IdempotencyRecord::query()->where('scope', 'boom')->count())->toBe(0);

    // The retry genuinely executes rather than replaying a cached failure.
    $this->postJson('/test/boom', [], ['Idempotency-Key' => 'key-cccccccc'])->assertStatus(503);

    expect($runs)->toBe(2);
});

it('releases the key when the handler throws', function (): void {
    Route::middleware(['api', 'idempotent:throw'])
        ->post('/test/throw', function (): void {
            throw new RuntimeException('handler exploded');
        });

    // Exception handling stays on: the point is that the handler turns the
    // throw into a 500 and the guard still releases the key, so the next
    // retry is a real attempt.
    $this->postJson('/test/throw', [], ['Idempotency-Key' => 'key-dddddddd'])
        ->assertStatus(500);

    // Left claimed, this key would be unusable until it expired.
    expect(IdempotencyRecord::query()->where('scope', 'throw')->count())->toBe(0);
});

it('keeps scopes separate, so one key can serve different operations', function (): void {
    $a = 0;
    $b = 0;
    guardedRoute('create', $a);
    guardedRoute('other', $b);

    $this->postJson('/test/create', ['qty' => 1], ['Idempotency-Key' => 'key-aaaaaaaa'])->assertCreated();
    $this->postJson('/test/other', ['qty' => 1], ['Idempotency-Key' => 'key-aaaaaaaa'])->assertCreated();

    expect($a)->toBe(1)
        ->and($b)->toBe(1)
        ->and(IdempotencyRecord::query()->count())->toBe(2);
});

it('records what was stored, so support can see the replayed answer', function (): void {
    $runs = 0;
    guardedRoute('create', $runs);

    $this->postJson('/test/create', ['qty' => 1], ['Idempotency-Key' => 'key-aaaaaaaa'])->assertCreated();

    $record = IdempotencyRecord::query()->sole();

    expect($record->status)->toBe(IdempotencyRecord::STATUS_COMPLETED)
        ->and($record->response_status)->toBe(201)
        ->and($record->response_body)->toBe(['id' => 'result-1']);
});
