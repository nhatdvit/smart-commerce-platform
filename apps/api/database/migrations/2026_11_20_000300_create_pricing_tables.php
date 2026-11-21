<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing: the discount codes a store hands out.
 *
 * Scope, stated plainly because the documents disagree with this file's
 * existence. docs/database/DATABASE_SPEC.md lists `promotions`, `coupons` and
 * `coupon_redemptions` as "Later", and CLAUDE.md section 1 puts promotions in
 * delivery stage 2. What is built here is the half the admin screen needs -
 * defining and managing codes - and nothing else:
 *
 *   - `coupon_redemptions` is NOT here. A redemption is written when an order
 *     uses a code, and there is no checkout yet. `redeemed_count` is the
 *     counter that checkout will increment inside the order transaction, and
 *     the log table arrives with it, because a counter with no log cannot be
 *     audited and a log with nothing to write is furniture.
 *   - `promotions` is NOT here either. An automatic campaign - "10% off every
 *     Friday", no code typed - is a different thing from a code a customer
 *     reads out, and guessing its shape now would be inventing business policy
 *     nobody has asked for (CLAUDE.md section 15).
 *
 * So: until checkout applies them, a code created here is a definition. The
 * screen says so, and so does the API contract.
 *
 * Money is BIGINT whole dong. A percentage lives in the same column as an
 * amount because a coupon carries exactly one number; which one it is, is
 * `discount_type`, and the CHECK constraints below keep the pair honest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            // Typed by a customer and read out loud, so upper-case ASCII only:
            // the application normalises, the CHECK below enforces.
            $table->string('code', 32);
            $table->string('description', 255)->nullable();
            // PERCENTAGE or FIXED_AMOUNT.
            $table->string('discount_type', 20);
            // Percent (1-100) or whole dong, according to discount_type.
            $table->bigInteger('discount_value');
            // Ceiling for a percentage, so "10% off" cannot take 200.000d off
            // a party order. Meaningless for a fixed amount, and refused there.
            $table->bigInteger('max_discount_amount')->nullable();
            $table->bigInteger('min_order_amount')->default(0);
            // Null means unlimited. Zero would mean "unusable", which is what
            // PAUSED is for.
            $table->integer('usage_limit')->nullable();
            // Maintained by checkout when it lands; see the class note.
            $table->integer('redeemed_count')->default(0);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->char('currency', 3)->default('VND');
            $table->timestampsTz();

            // One live code per store. Codes are stored upper-case, so this is
            // also the case-insensitive rule - no separate comparison key is
            // needed the way product names need one.
            $table->unique(['store_id', 'code']);
            // Target for the composite foreign key of `coupon_redemptions`
            // when checkout brings it, so a redemption can never cross stores.
            $table->unique(['id', 'store_id']);
            $table->index(['store_id', 'status']);

            // restrictOnDelete: a code that has been redeemed is part of what
            // an order was charged, and must outlive a tidy-up of the store row.
            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
        });

        $this->addConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }

    /** Raw SQL for what the schema builder cannot express (CLAUDE.md section 7). */
    private function addConstraints(): void
    {
        DB::statement("
            ALTER TABLE coupons
            ADD CONSTRAINT coupons_status_valid
            CHECK (status IN ('ACTIVE', 'PAUSED', 'ARCHIVED'))
        ");

        DB::statement("
            ALTER TABLE coupons
            ADD CONSTRAINT coupons_discount_type_valid
            CHECK (discount_type IN ('PERCENTAGE', 'FIXED_AMOUNT'))
        ");

        // Upper-case ASCII, three characters or more: what a customer can read
        // out over a counter and a cashier can type without ambiguity.
        DB::statement("
            ALTER TABLE coupons
            ADD CONSTRAINT coupons_code_format
            CHECK (code ~ '^[A-Z0-9_-]{3,32}\$')
        ");

        DB::statement("
            ALTER TABLE coupons
            ADD CONSTRAINT coupons_currency_supported
            CHECK (currency = 'VND')
        ");

        DB::statement('
            ALTER TABLE coupons
            ADD CONSTRAINT coupons_discount_value_positive
            CHECK (discount_value > 0)
        ');

        // A percentage is a percentage. Without this, "50000% off" is storable
        // and every order it touches goes negative.
        DB::statement("
            ALTER TABLE coupons
            ADD CONSTRAINT coupons_percentage_within_range
            CHECK (discount_type <> 'PERCENTAGE' OR discount_value BETWEEN 1 AND 100)
        ");

        // A cap only means something for a percentage.
        DB::statement("
            ALTER TABLE coupons
            ADD CONSTRAINT coupons_cap_only_for_percentage
            CHECK (
                max_discount_amount IS NULL
                OR (discount_type = 'PERCENTAGE' AND max_discount_amount > 0)
            )
        ");

        // A fixed discount bigger than the minimum order is a shop paying its
        // customers to order breakfast.
        DB::statement("
            ALTER TABLE coupons
            ADD CONSTRAINT coupons_fixed_within_minimum
            CHECK (
                discount_type <> 'FIXED_AMOUNT'
                OR min_order_amount = 0
                OR discount_value <= min_order_amount
            )
        ");

        DB::statement('
            ALTER TABLE coupons
            ADD CONSTRAINT coupons_counts_nonnegative
            CHECK (
                min_order_amount >= 0
                AND redeemed_count >= 0
                AND (usage_limit IS NULL OR usage_limit > 0)
            )
        ');

        // The counter can never pass its own limit: that is the rule the
        // checkout transaction will be held to.
        DB::statement('
            ALTER TABLE coupons
            ADD CONSTRAINT coupons_redeemed_within_limit
            CHECK (usage_limit IS NULL OR redeemed_count <= usage_limit)
        ');

        DB::statement('
            ALTER TABLE coupons
            ADD CONSTRAINT coupons_window_ordered
            CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at)
        ');
    }
};
