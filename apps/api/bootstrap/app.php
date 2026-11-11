<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Http\Middleware\EnsureStorePermission;
use App\Modules\Store\Presentation\Http\Middleware\ResolveStoreContext;
use App\Shared\Infrastructure\Exception\ApiExceptionRenderer;
use App\Shared\Presentation\Http\Middleware\AssignRequestId;
use App\Shared\Presentation\Http\Middleware\EnsureIdempotency;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            // Probes live outside api/v1 so orchestrators never depend on the
            // business contract, and outside the api throttle so a flood of
            // traffic cannot make a healthy instance look dead.
            Route::middleware('health')
                ->group(base_path('routes/health.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every request gets a correlation id before anything else runs, so
        // even a failure inside another middleware is traceable.
        $middleware->prepend(AssignRequestId::class);

        $middleware->group('health', [
            AssignRequestId::class,
        ]);

        $middleware->alias([
            'store.resolve' => ResolveStoreContext::class,
            'store.can' => EnsureStorePermission::class,
            // Takes the scope that namespaces the key, e.g. idempotent:order.create
            'idempotent' => EnsureIdempotency::class,
        ]);

        // This API is stateless by default; cookie-authenticated routes opt in
        // through Sanctum stateful domains.
        $middleware->statefulApi();

        // Named limiter defined in AppServiceProvider, so the limit is config
        // rather than a magic number in the middleware stack.
        $middleware->throttleApi('api');

        $middleware->trustProxies(at: (string) env('TRUSTED_PROXIES', '*'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This is an API: never try to redirect to a login page. Without this,
        // an unauthenticated request that does not send Accept:
        // application/json reaches Laravel's guest redirect, which calls
        // route('login') - a route an API-only application has no reason to
        // define - and a clean 401 turns into a 500.
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*')
                || $request->is('health/*')
                || $request->expectsJson(),
        );

        // One envelope for every failure: no stack traces, no SQL, no secrets.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->is('health/*') || $request->expectsJson()) {
                return app(ApiExceptionRenderer::class)->render($e, $request);
            }

            return null;
        });

        // Payment and personal data never reach the logs.
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'account_number',
            'transfer_reference',
        ]);
    })
    ->create();
