<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Feature tests get the application and a real PostgreSQL schema wrapped in a
 * transaction per test. Unit tests get neither, which keeps them fast and
 * stops framework behaviour leaking into domain tests.
 */

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit/ArchitectureTest.php');
