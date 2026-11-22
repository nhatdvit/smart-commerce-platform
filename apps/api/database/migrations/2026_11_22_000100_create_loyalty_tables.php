<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty: points earned on paid orders, spent on rewards.
 *
 * A new module of its own rather than folded into Pricing, which already owns
 * discount codes - a points ledger, point expiry and membership tiers are not
 * pricing concerns, they are a different relationship with a different
 * lifecycle (see CLAUDE.md section 5's module table, added in the same change
 * as this migration).
 *
 * There is no registered-account module yet, so a "member" here is the same
 * identity Order's customer directory already uses: the contact phone number
 * an order was placed under (CLAUDE.md section 5 still lists `Customer` as a
 * future module). Nothing here duplicates that directory - it stays a derived
 * read over `loyalty_ledger_entries`, grouped by phone.
 *
 * `loyalty_ledger_entries` is the one source of truth for a phone's balance:
 * every row is a signed delta, `EARN` always positive and `REDEEM` always
 * negative, so a balance is a plain `SUM(points)` with nothing to reconcile.
 * An `EARN` row's `payment_id` is what makes awarding idempotent - the sync
 * job that reads paid payments can run every minute forever and never award
 * the same payment twice, the unique index below is what guarantees it.
 *
 * Earning has no checkout to react to yet (CLAUDE.md delivery stage 4 is
 * explicit that Loyalty needs a real customer identity and a real checkout
 * before either number means anything) - so this reads `payments.status`
 * directly rather than through an exported contract, the same pragmatic
 * choice `EloquentCustomerDirectory` already made for the same reason: Payment
 * carries an open, unrelated, in-flight settlement change, and this is a
 * read-only query in the dependency direction the module already allows
 * (Order -> Payment). Replace it with a proper export once that settles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_settings', function (Blueprint $table): void {
            // One row per store; store_id IS the key, not a surrogate id - this
            // is configuration, not a collection of records.
            $table->uuid('store_id')->primary();
            // Whole VND a customer spends (on a PAID order) to earn one point.
            $table->bigInteger('points_per_currency')->default(10_000);
            // Lifetime points earned at or above this makes a phone "Thân
            // thiết" rather than "Thành viên" - the only tier rule today.
            $table->integer('loyal_tier_threshold_points')->default(100);
            $table->timestampsTz();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });

        Schema::create('loyalty_rewards', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            $table->string('name', 160);
            $table->integer('points_cost');
            // One of FoodIcon's known names, same catalogue the menu screen
            // already uses - a reward is usually a menu item given away free.
            $table->string('icon', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['store_id', 'is_active']);
            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
        });

        Schema::create('loyalty_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            $table->string('phone', 20);
            $table->string('type', 10);
            // Signed: positive for EARN, negative for REDEEM - see the class
            // note on why a balance is a plain SUM of this column.
            $table->integer('points');
            // Set only for EARN: the payment that was awarded for. Null for
            // REDEEM, which is never traced back to one.
            $table->uuid('payment_id')->nullable();
            // Set only for REDEEM: which reward was given. Null for EARN.
            $table->uuid('reward_id')->nullable();
            $table->string('note', 500)->nullable();
            // Who processed a redemption - there is no customer self-service
            // redemption flow, so this is never null for a REDEEM row. Null
            // for EARN, which the sync job writes on nobody's behalf.
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['store_id', 'phone']);
            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            // restrictOnDelete: a payment or a reward is never deleted while a
            // ledger entry still points to it (financial-adjacent history).
            $table->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete();
            $table->foreign('reward_id')->references('id')->on('loyalty_rewards')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });

        $this->addConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_ledger_entries');
        Schema::dropIfExists('loyalty_rewards');
        Schema::dropIfExists('loyalty_settings');
    }

    /** Raw SQL for what the schema builder cannot express (CLAUDE.md section 7). */
    private function addConstraints(): void
    {
        DB::statement('
            ALTER TABLE loyalty_settings
            ADD CONSTRAINT loyalty_settings_ratio_positive
            CHECK (points_per_currency > 0)
        ');

        DB::statement('
            ALTER TABLE loyalty_settings
            ADD CONSTRAINT loyalty_settings_threshold_nonnegative
            CHECK (loyal_tier_threshold_points >= 0)
        ');

        DB::statement('
            ALTER TABLE loyalty_rewards
            ADD CONSTRAINT loyalty_rewards_cost_positive
            CHECK (points_cost > 0)
        ');

        DB::statement("
            ALTER TABLE loyalty_ledger_entries
            ADD CONSTRAINT loyalty_ledger_entries_type_valid
            CHECK (type IN ('EARN', 'REDEEM'))
        ");

        // The sign IS the type, so a query never has to trust both agreeing -
        // reading points alone already says which one this row is.
        DB::statement("
            ALTER TABLE loyalty_ledger_entries
            ADD CONSTRAINT loyalty_ledger_entries_sign_matches_type
            CHECK (
                (type = 'EARN' AND points > 0)
                OR (type = 'REDEEM' AND points < 0)
            )
        ");

        DB::statement("
            ALTER TABLE loyalty_ledger_entries
            ADD CONSTRAINT loyalty_ledger_entries_fields_match_type
            CHECK (
                (type = 'EARN' AND payment_id IS NOT NULL AND reward_id IS NULL)
                OR (type = 'REDEEM' AND reward_id IS NOT NULL AND payment_id IS NULL AND actor_id IS NOT NULL)
            )
        ");

        // The sync job's idempotency guarantee: one EARN row per payment,
        // ever - a partial index because a payment appears at most once
        // regardless, but REDEEM rows carry no payment_id to collide on.
        DB::statement('
            CREATE UNIQUE INDEX loyalty_ledger_entries_one_earn_per_payment
            ON loyalty_ledger_entries (payment_id)
            WHERE type = \'EARN\'
        ');
    }
};
