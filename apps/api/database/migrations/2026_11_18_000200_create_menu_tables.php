<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menu: a store's menus and the priced items on them.
 *
 * Products are master data; a MENU decides when, where and at what price a
 * variant is sold (CLAUDE.md section 6). So the selling price lives here, not
 * on the variant, and so do today's portions.
 *
 * Out of this milestone on purpose: menu_schedules and menu_exceptions. Time
 * windows and date overrides are the next slice; this one carries only what the
 * admin menu screen needs, and designing half a scheduler now would be worse
 * than designing it once with the screen that drives it.
 *
 * menu_items reference a variant through a COMPOSITE foreign key that includes
 * store_id, so a menu can only ever list variants from its own store - the
 * cross-store leak is impossible at the schema level, not merely checked.
 *
 * Removing an item from a menu sets deleted_at. The row survives because an
 * order line will reference it, and "it is not on the menu any more" must not
 * mean "it was never sold".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            $table->string('name', 160);
            // The case-folded comparison key, written by the application; see
            // the note in the catalog migration.
            $table->string('name_key', 160);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampsTz();

            // Target for menu_items' composite foreign key.
            $table->unique(['id', 'store_id']);
            $table->index(['store_id', 'status']);

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });

        Schema::create('menu_items', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            $table->uuid('menu_id');
            $table->uuid('product_variant_id');
            // What a customer actually pays at this store. Whole dong.
            $table->bigInteger('selling_price_amount');
            $table->char('currency', 3)->default('VND');
            // The staff-controlled sold-out switch, independent of any quota.
            $table->boolean('is_available')->default(true);
            // Portions left today and portions prepared today. Null means this
            // item is not counted - a bottled drink rather than a dish.
            $table->integer('available_quantity')->nullable();
            $table->integer('daily_capacity')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();

            // One row per variant per menu, live or removed. Re-listing a
            // variant restores its row rather than inserting a second one, so
            // the history of that listing stays in one place.
            $table->unique(['menu_id', 'product_variant_id']);
            $table->index(['store_id', 'is_available']);

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign(['menu_id', 'store_id'])
                ->references(['id', 'store_id'])->on('menus')
                ->cascadeOnDelete();
            // restrictOnDelete, not cascade: a variant that is listed anywhere
            // is archived, never deleted.
            $table->foreign(['product_variant_id', 'store_id'])
                ->references(['id', 'store_id'])->on('product_variants')
                ->restrictOnDelete();
        });

        $this->addConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menus');
    }

    /** Raw SQL for what the schema builder cannot express (CLAUDE.md section 7). */
    private function addConstraints(): void
    {
        DB::statement("
            ALTER TABLE menus
            ADD CONSTRAINT menus_status_valid
            CHECK (status IN ('DRAFT', 'ACTIVE', 'ARCHIVED'))
        ");

        DB::statement('
            ALTER TABLE menu_items
            ADD CONSTRAINT menu_items_selling_price_nonnegative
            CHECK (selling_price_amount >= 0)
        ');

        DB::statement("
            ALTER TABLE menu_items
            ADD CONSTRAINT menu_items_currency_supported
            CHECK (currency = 'VND')
        ");

        DB::statement('
            ALTER TABLE menu_items
            ADD CONSTRAINT menu_items_quantities_nonnegative
            CHECK (
                (available_quantity IS NULL OR available_quantity >= 0)
                AND (daily_capacity IS NULL OR daily_capacity >= 0)
            )
        ');

        // What is left can never exceed what was prepared. Without this, a
        // typo in one field silently invents portions.
        DB::statement('
            ALTER TABLE menu_items
            ADD CONSTRAINT menu_items_available_within_capacity
            CHECK (
                available_quantity IS NULL
                OR daily_capacity IS NULL
                OR available_quantity <= daily_capacity
            )
        ');

        // A live menu reads its items ordered, and only the live ones. A
        // partial index keeps that scan proportional to today's menu rather
        // than to every item ever listed.
        DB::statement('
            CREATE INDEX idx_menu_items_live
            ON menu_items (menu_id, sort_order)
            WHERE deleted_at IS NULL
        ');

        // Menu names are compared the same way product names are, and for the
        // same reason: lower() does not fold Vietnamese on a C-collation
        // cluster. One menu per store in this slice makes a collision
        // unlikely, but an index that silently fails to enforce its rule is
        // worse than no index.
        DB::statement('
            CREATE UNIQUE INDEX idx_menus_store_name_key
            ON menus (store_id, name_key)
        ');
    }
};
