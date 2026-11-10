<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but a test database.
     *
     * RefreshDatabase runs migrate:fresh, which drops every table in the
     * configured database. Inside the api container DB_DATABASE names the
     * development database, and PHP fills $_SERVER from the real environment,
     * which Laravel reads in preference to the value in phpunit.xml. A run
     * started without an explicit override would therefore wipe development
     * data, so the check happens before parent::setUp() boots the framework
     * and the trait gets a chance to migrate.
     */
    protected function setUp(): void
    {
        $database = $_SERVER['DB_DATABASE'] ?? $_ENV['DB_DATABASE'] ?? (getenv('DB_DATABASE') ?: null);

        if (is_string($database) && $database !== '' && ! str_ends_with($database, '_test')) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against database "%s": the name must end in "_test". '
                .'Run "pnpm api:test", or pass -e DB_DATABASE=%s_test to docker compose.',
                $database,
                $database
            ));
        }

        parent::setUp();
    }
}
