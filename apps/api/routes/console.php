<?php

declare(strict_types=1);

use App\Shared\Presentation\Console\DispatchOutboxCommand;
use App\Shared\Presentation\Console\PruneIdempotencyRecordsCommand;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled work.
 *
 * Exactly one scheduler container runs per deployment (CLAUDE.md section 16).
 * Two would run every due task twice: publishing the same event twice is
 * survivable because consumers are idempotent, but expiring the same stock
 * reservation twice is not.
 */

// Publishes domain events recorded by business transactions. withoutOverlapping
// keeps a slow run from stacking on the next tick.
Schedule::command(DispatchOutboxCommand::class)
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Keeps idempotency_records bounded. Hourly is ample: the retention window is
// measured in hours, so nothing here is time-critical.
Schedule::command(PruneIdempotencyRecordsCommand::class)
    ->hourly()
    ->withoutOverlapping(10)
    ->runInBackground();
