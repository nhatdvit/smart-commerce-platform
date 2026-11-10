<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores, their settings, opening hours and closures.
 *
 * Every store carries its own timezone. Timestamps are UTC; schedules and
 * daily quotas are evaluated in store-local time (CLAUDE.md section 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->string('slug', 200)->unique();
            $table->string('phone', 20)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampsTz();

            $table->index('status');
        });

        Schema::create('store_settings', function (Blueprint $table): void {
            // One row per store, so the store id is the primary key.
            $table->uuid('store_id')->primary();
            $table->boolean('allow_guest_checkout')->default(true);
            $table->boolean('pickup_enabled')->default(true);
            // Delivery is off until the fulfillment decision is made (section 15).
            $table->boolean('delivery_enabled')->default(false);
            $table->integer('order_lead_minutes')->default(15);
            $table->char('currency', 3)->default('VND');
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });

        Schema::create('store_hours', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            // ISO-8601: 1 is Monday through 7 is Sunday. Range enforced by a
            // CHECK constraint in the constraints migration.
            $table->smallInteger('day_of_week');
            $table->time('open_time', 0);
            $table->time('close_time', 0);
            $table->boolean('is_closed')->default(false);

            $table->unique(['store_id', 'day_of_week', 'open_time']);
            $table->index('store_id');

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });

        Schema::create('store_closures', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->string('reason', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['store_id', 'start_at', 'end_at']);

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_closures');
        Schema::dropIfExists('store_hours');
        Schema::dropIfExists('store_settings');
        Schema::dropIfExists('stores');
    }
};
