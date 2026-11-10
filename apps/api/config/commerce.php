<?php

declare(strict_types=1);

/*
 * Commerce policy that is not yet decided.
 *
 * CLAUDE.md §15 lists business decisions still open. Until each is answered the
 * implementation stays configurable and invents no policy, so a decision later
 * is an environment change rather than a code change.
 *
 * Read through config(), never env(), outside this file: env() returns null once
 * config:cache has run.
 */

return [

    /*
     |--------------------------------------------------------------------------
     | Store context
     |--------------------------------------------------------------------------
     |
     | Timestamps are stored in UTC. Opening hours, menu schedules and daily
     | quotas are evaluated in the store's own timezone, which each store row
     | carries; this is only the fallback for a store without one.
     |
     | Open decision (§15): single-store launch vs multi-store. The data model
     | is multi-store already. default_store_slug serves a single-store
     | deployment where no host mapping or route parameter is present;
     | store_hosts maps a public hostname to a store slug for multi-store.
     | Neither is ever read from the query string.
     */
    'default_store_timezone' => env('COMMERCE_DEFAULT_STORE_TIMEZONE', 'Asia/Ho_Chi_Minh'),

    'default_store_slug' => env('COMMERCE_DEFAULT_STORE_SLUG'),

    /** @var array<string, string> hostname => store slug */
    'store_hosts' => array_filter(
        array_reduce(
            explode(',', (string) env('COMMERCE_STORE_HOSTS', '')),
            static function (array $carry, string $pair): array {
                $parts = array_map('trim', explode('=', $pair, 2));

                if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                    $carry[$parts[0]] = $parts[1];
                }

                return $carry;
            },
            [],
        ),
    ),

    /*
     |--------------------------------------------------------------------------
     | Rate limits (requests per minute)
     |--------------------------------------------------------------------------
     |
     | The second layer of defence; the Nginx gateway sheds obvious floods
     | first. Writes are limited far harder than reads because checkout and
     | payment confirmation are the expensive, stateful operations.
     */
    'rate_limits' => [
        'read_per_minute' => (int) env('COMMERCE_RATE_LIMIT_READ', 120),
        'write_per_minute' => (int) env('COMMERCE_RATE_LIMIT_WRITE', 20),
    ],

    /*
     |--------------------------------------------------------------------------
     | Orders
     |--------------------------------------------------------------------------
     */
    'order' => [
        // Prefix for the human-readable order code shown to customers and
        // used to derive the bank transfer reference.
        'code_prefix' => env('COMMERCE_ORDER_CODE_PREFIX', 'SCP'),
    ],

    /*
     |--------------------------------------------------------------------------
     | Payments - MVP is CASH and BANK_TRANSFER only
     |--------------------------------------------------------------------------
     |
     | Fixed decision (§6, dated 2026-11-09). No gateway, no card processing and
     | no automatic bank reconciliation without a separate approved requirement.
     |
     | A generated VietQR is NOT a confirmation of payment. Authorized staff
     | confirm receipt after checking the actual bank credit; a screenshot, a QR
     | display or an "I have transferred" click is never proof of funds.
     */
    'bank_transfer' => [
        // How long an unpaid transfer order stays open before it expires.
        // Still to be confirmed with the business (§15).
        'expiry_minutes' => (int) env('COMMERCE_BANK_TRANSFER_EXPIRY_MINUTES', 30),

        // Prefix of the unique transfer reference. Upper-case alphanumeric
        // only: some Vietnamese banking apps reject anything else, and the
        // reference is the only reliable way to match a credit to an order.
        'reference_prefix' => env('COMMERCE_TRANSFER_REFERENCE_PREFIX', 'SCP'),
    ],

    /*
     |--------------------------------------------------------------------------
     | Inventory
     |--------------------------------------------------------------------------
     |
     | Open decision (§15): whether a cash order reserves stock at placement or
     | consumes it on confirmation. The recommendation is reserve at creation,
     | consume at confirmation, release on timely cancellation.
     */
    'inventory' => [
        'reservation_ttl_minutes' => (int) env('COMMERCE_STOCK_RESERVATION_TTL_MINUTES', 30),
    ],

    /*
     |--------------------------------------------------------------------------
     | Idempotency
     |--------------------------------------------------------------------------
     |
     | How long a stored result stays replayable. Long enough to cover client
     | retries and support investigation, short enough to bound the table.
     */
    'idempotency' => [
        'retention_hours' => (int) env('COMMERCE_IDEMPOTENCY_RETENTION_HOURS', 24),
    ],

    /*
     |--------------------------------------------------------------------------
     | Notifications
     |--------------------------------------------------------------------------
     |
     | Open decision (§15): provider not chosen. Both default to a no-op so
     | nothing silently fails to send and nothing accidentally sends.
     */
    'notifications' => [
        'email_provider' => env('COMMERCE_NOTIFICATION_EMAIL_PROVIDER', 'none'),
        'sms_provider' => env('COMMERCE_NOTIFICATION_SMS_PROVIDER', 'none'),
    ],

];
