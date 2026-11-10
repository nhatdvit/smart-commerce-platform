<?php

declare(strict_types=1);

/*
 * CORS allowlist.
 *
 * Browser requests from the customer and admin apps are cross-origin, so this
 * has to be explicit. A wildcard is never acceptable in a deployed environment
 * (CLAUDE.md section 8), and with supports_credentials the browser rejects one
 * anyway: cookie authentication requires a named origin.
 */

return [

    'paths' => ['api/*', 'health/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Idempotency-Key',
        'X-Guest-Order-Token',
        'X-Requested-With',
        'X-Request-Id',
        'X-XSRF-TOKEN',
    ],

    // Clients read the correlation id off the response to report problems.
    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 600,

    // Required for Sanctum cookie authentication from the web apps.
    'supports_credentials' => true,

];
