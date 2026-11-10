<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CHECK constraints and partial indexes.
 *
 * The Laravel schema builder cannot express either, so they are raw SQL - the
 * justified use of raw SQL the conventions allow (CLAUDE.md section 7). They live in
 * the same milestone as the tables they protect, so no environment ever runs
 * without them.
 *
 * These are not belt and braces over application validation: a constraint in
 * the database is the only rule that also holds for a seeder, a data fix or a
 * future service talking to the same tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Enumerations -------------------------------------------------
        // The application casts these to PHP enums; the database refuses
        // anything outside the set, so a bad write fails at the source rather
        // than becoming an unmatched case in a match() later.
        DB::statement("
            ALTER TABLE users
            ADD CONSTRAINT users_status_valid
            CHECK (status IN ('ACTIVE', 'BLOCKED'))
        ");

        DB::statement("
            ALTER TABLE roles
            ADD CONSTRAINT roles_scope_valid
            CHECK (scope IN ('GLOBAL', 'STORE'))
        ");

        DB::statement("
            ALTER TABLE stores
            ADD CONSTRAINT stores_status_valid
            CHECK (status IN ('ACTIVE', 'INACTIVE'))
        ");

        DB::statement("
            ALTER TABLE store_members
            ADD CONSTRAINT store_members_status_valid
            CHECK (status IN ('ACTIVE', 'SUSPENDED'))
        ");

        DB::statement("
            ALTER TABLE outbox_events
            ADD CONSTRAINT outbox_events_status_valid
            CHECK (status IN ('PENDING', 'PUBLISHED', 'FAILED'))
        ");

        DB::statement("
            ALTER TABLE idempotency_records
            ADD CONSTRAINT idempotency_records_status_valid
            CHECK (status IN ('IN_PROGRESS', 'COMPLETED', 'FAILED'))
        ");

        // --- Ranges and intervals -----------------------------------------
        // ISO-8601 day numbering.
        DB::statement('
            ALTER TABLE store_hours
            ADD CONSTRAINT store_hours_day_of_week_range
            CHECK (day_of_week BETWEEN 1 AND 7)
        ');

        // A closure has to cover a real interval.
        DB::statement('
            ALTER TABLE store_closures
            ADD CONSTRAINT store_closures_interval_valid
            CHECK (end_at > start_at)
        ');

        // Lead time is a duration, never negative.
        DB::statement('
            ALTER TABLE store_settings
            ADD CONSTRAINT store_settings_order_lead_minutes_nonnegative
            CHECK (order_lead_minutes >= 0)
        ');

        // A session that expires before it was created is a bug, not a state.
        DB::statement('
            ALTER TABLE auth_sessions
            ADD CONSTRAINT auth_sessions_expiry_after_creation
            CHECK (expires_at > created_at)
        ');

        DB::statement('
            ALTER TABLE outbox_events
            ADD CONSTRAINT outbox_events_attempts_nonnegative
            CHECK (attempts >= 0)
        ');

        // --- Cross-field consistency --------------------------------------
        // A published event must record when; an unpublished one must not
        // claim to have been published.
        DB::statement("
            ALTER TABLE outbox_events
            ADD CONSTRAINT outbox_events_published_at_matches_status
            CHECK (
                (status = 'PUBLISHED' AND published_at IS NOT NULL)
                OR (status <> 'PUBLISHED' AND published_at IS NULL)
            )
        ");

        // A completed idempotency record must carry the response it replays,
        // otherwise a retry would get an empty success.
        DB::statement("
            ALTER TABLE idempotency_records
            ADD CONSTRAINT idempotency_records_completed_has_response
            CHECK (status <> 'COMPLETED' OR response_status IS NOT NULL)
        ");

        // --- Partial indexes ----------------------------------------------
        // The dispatcher only ever scans PENDING rows, oldest first. A partial
        // index keeps that scan proportional to the backlog rather than to all
        // history ever published.
        DB::statement("
            CREATE INDEX idx_outbox_pending
            ON outbox_events (created_at)
            WHERE status = 'PENDING'
        ");

        // Authorization looks up active memberships only.
        DB::statement("
            CREATE INDEX idx_store_members_active
            ON store_members (user_id, store_id)
            WHERE status = 'ACTIVE'
        ");

        // Case-insensitive uniqueness for login identifiers: two accounts
        // differing only in capitalisation would be an account-takeover risk.
        DB::statement('
            CREATE UNIQUE INDEX idx_users_email_lower
            ON users (lower(email))
            WHERE email IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_users_email_lower');
        DB::statement('DROP INDEX IF EXISTS idx_store_members_active');
        DB::statement('DROP INDEX IF EXISTS idx_outbox_pending');

        $constraints = [
            'idempotency_records' => [
                'idempotency_records_status_valid',
                'idempotency_records_completed_has_response',
            ],
            'outbox_events' => [
                'outbox_events_status_valid',
                'outbox_events_attempts_nonnegative',
                'outbox_events_published_at_matches_status',
            ],
            'auth_sessions' => ['auth_sessions_expiry_after_creation'],
            'store_settings' => ['store_settings_order_lead_minutes_nonnegative'],
            'store_closures' => ['store_closures_interval_valid'],
            'store_hours' => ['store_hours_day_of_week_range'],
            'store_members' => ['store_members_status_valid'],
            'stores' => ['stores_status_valid'],
            'roles' => ['roles_scope_valid'],
            'users' => ['users_status_valid'],
        ];

        foreach ($constraints as $table => $names) {
            foreach ($names as $name) {
                DB::statement(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', $table, $name));
            }
        }
    }
};
