<?php

declare(strict_types=1);

/*
 * The Phase 01 definition of done: the probes answer, every response carries a
 * correlation id, and failures come back as the one documented envelope.
 */

it('reports liveness without touching any dependency', function (): void {
    $this->getJson('/health/live')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure(['status', 'checked_at']);
});

it('reports readiness with each dependency named', function (): void {
    $this->getJson('/health/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.status', 'up')
        ->assertJsonPath('checks.redis.status', 'up');
});

it('echoes an inbound correlation id so a client trace survives', function (): void {
    $this->withHeader('X-Request-Id', 'trace-from-gateway-123')
        ->getJson('/health/live')
        ->assertOk()
        ->assertHeader('X-Request-Id', 'trace-from-gateway-123');
});

it('mints a correlation id when the client sends none', function (): void {
    $response = $this->getJson('/health/live')->assertOk();

    expect($response->headers->get('X-Request-Id'))->not->toBeNull()->not->toBe('');
});

it('refuses a malformed correlation id rather than reflecting it', function (): void {
    // A header that reaches log files and response headers must be validated;
    // this one carries CRLF, the classic header-injection payload.
    $response = $this->withHeader('X-Request-Id', "bad\r\nX-Injected: yes")
        ->getJson('/health/live')
        ->assertOk();

    expect($response->headers->get('X-Request-Id'))
        ->not->toContain('X-Injected')
        ->not->toContain("\n");
});

it('returns the documented error envelope for an unknown route', function (): void {
    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND')
        ->assertJsonPath('status_code', 404)
        ->assertJsonStructure(['status_code', 'code', 'message', 'request_id', 'timestamp', 'path']);
});

it('returns the envelope with a machine-readable code for an unauthenticated request', function (): void {
    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('answers 401 even when the client sends no Accept header', function (): void {
    // Regression: the Authenticate middleware builds a guest redirect before
    // throwing, and its default target is route('login'). An API-only app
    // defines no such route, so without an explicit override this returned
    // 500 RouteNotFoundException instead of 401 - but only for clients that
    // did not ask for JSON, which is exactly what curl and most scripts do.
    $this->get('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('never leaks a stack trace in the error body', function (): void {
    $response = $this->getJson('/api/v1/does-not-exist')->assertNotFound();

    expect($response->json())
        ->not->toHaveKey('trace')
        ->not->toHaveKey('exception')
        ->not->toHaveKey('file');
});
