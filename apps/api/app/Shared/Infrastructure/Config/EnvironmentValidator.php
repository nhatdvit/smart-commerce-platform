<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Config;

use DateTimeZone;
use RuntimeException;

/**
 * Validates configuration at boot.
 *
 * A misconfigured deployment must fail loudly instead of serving traffic with
 * silent defaults - a missing APP_KEY or a production app left in debug mode is
 * a security incident, not a warning (CLAUDE.md section 14).
 *
 * This reads config(), not env(), so it keeps working once config:cache has
 * run. Calling env() outside config files returns null in a cached app, which
 * is a classic Laravel production bug.
 */
final class EnvironmentValidator
{
    private const array ALLOWED_ENVIRONMENTS = ['local', 'testing', 'staging', 'production'];

    /** @var array<int, string> */
    private array $problems = [];

    public function validate(): void
    {
        $this->problems = [];

        $environment = (string) config('app.env');

        $this->validateApplication($environment);
        $this->validateDatabase();
        $this->validateRedis();
        $this->validateCommerce();

        if ($environment === 'production' || $environment === 'staging') {
            $this->validateDeployed($environment);
        }

        if ($this->problems !== []) {
            throw new RuntimeException(
                "Invalid configuration:\n  - ".implode("\n  - ", $this->problems),
            );
        }
    }

    private function validateApplication(string $environment): void
    {
        if (! in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            $this->problems[] = sprintf(
                'APP_ENV must be one of %s, got "%s"',
                implode(', ', self::ALLOWED_ENVIRONMENTS),
                $environment,
            );
        }

        $key = (string) config('app.key');

        if ($key === '') {
            $this->problems[] = 'APP_KEY is empty. Generate one with: php artisan key:generate';
        }

        $timezone = (string) config('app.timezone');

        if ($timezone !== 'UTC') {
            // Timestamps are stored in UTC and converted per store; a global
            // non-UTC timezone silently corrupts schedule evaluation.
            $this->problems[] = sprintf('APP_TIMEZONE must be UTC, got "%s"', $timezone);
        }

        $this->validateTimezoneIdentifier(
            (string) config('commerce.default_store_timezone'),
            'COMMERCE_DEFAULT_STORE_TIMEZONE',
        );
    }

    private function validateDatabase(): void
    {
        $default = (string) config('database.default');

        if ($default !== 'pgsql') {
            // PostgreSQL-specific behaviour (row locking, partial indexes,
            // TIMESTAMPTZ) is load-bearing, so SQLite or MySQL would quietly
            // change business guarantees.
            $this->problems[] = sprintf(
                'DB_CONNECTION must be pgsql, got "%s". PostgreSQL is the source of truth.',
                $default,
            );
        }

        foreach (['host', 'database', 'username'] as $setting) {
            if ((string) config("database.connections.{$default}.{$setting}") === '') {
                $this->problems[] = sprintf('database.connections.%s.%s is empty', $default, $setting);
            }
        }
    }

    private function validateRedis(): void
    {
        if ((string) config('database.redis.default.host') === '') {
            $this->problems[] = 'REDIS_HOST is empty; cache, queues and Horizon all need it';
        }
    }

    private function validateCommerce(): void
    {
        $positiveIntegers = [
            'commerce.bank_transfer.expiry_minutes',
            'commerce.inventory.reservation_ttl_minutes',
        ];

        foreach ($positiveIntegers as $path) {
            $value = config($path);

            if (! is_int($value) || $value < 1) {
                $this->problems[] = sprintf('%s must be a positive integer', $path);
            }
        }

        foreach (['commerce.order.code_prefix', 'commerce.bank_transfer.reference_prefix'] as $path) {
            $value = (string) config($path);

            // Bank transfer references must survive banking systems that
            // accept only upper-case alphanumerics.
            if (preg_match('/^[A-Z0-9]{1,8}$/', $value) !== 1) {
                $this->problems[] = sprintf(
                    '%s must be 1-8 upper-case letters or digits, got "%s"',
                    $path,
                    $value,
                );
            }
        }
    }

    private function validateDeployed(string $environment): void
    {
        if (config('app.debug') === true) {
            $this->problems[] = sprintf('APP_DEBUG must be false in %s', $environment);
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $this->problems[] = sprintf('APP_URL must use https in %s', $environment);
        }

        foreach (['cache.default' => 'redis', 'queue.default' => 'redis'] as $path => $expected) {
            if ((string) config($path) !== $expected) {
                $this->problems[] = sprintf(
                    '%s must be "%s" in %s, got "%s"',
                    $path,
                    $expected,
                    $environment,
                    (string) config($path),
                );
            }
        }

        if (config('session.secure') !== true) {
            $this->problems[] = sprintf('SESSION_SECURE_COOKIE must be true in %s', $environment);
        }

        if (config('sanctum.stateful') === []) {
            $this->problems[] = 'SANCTUM_STATEFUL_DOMAINS is empty; cookie authentication would reject the web apps';
        }

        if (config('cors.allowed_origins') === ['*']) {
            $this->problems[] = sprintf('CORS_ALLOWED_ORIGINS must not be a wildcard in %s', $environment);
        }
    }

    private function validateTimezoneIdentifier(string $timezone, string $label): void
    {
        if ($timezone === '' || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $this->problems[] = sprintf('%s is not a valid IANA timezone, got "%s"', $label, $timezone);
        }
    }
}
