<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders: what was agreed, at what price, and how it progressed.
 *
 * An order line is a SNAPSHOT (CLAUDE.md section 6). The product and variant
 * ids are kept for reporting, but every name and every amount is copied at
 * checkout, so editing the catalogue tomorrow cannot change a receipt from
 * today. That is why `product_name` sits next to `product_id` rather than
 * being joined on read.
 *
 * Out of this milestone on purpose:
 *   - order_item_modifiers. Modifier groups are designed but not stored yet -
 *     the menu slice answers `modifier_groups: []` - so a table for their
 *     snapshots would have nothing to hold. `modifiers_total_amount` is here
 *     because a line total has to stay arithmetically complete.
 *   - stock_reservations and the checkout that writes these rows. This slice
 *     is the operations queue: read a store's orders and move them through
 *     their state machine. Orders arrive from the customer checkout, which is
 *     the next slice; until then the demo seeder creates them.
 *
 * Money is BIGINT whole dong, never a float, and the totals are checked
 * against each other in the database, so no code path can store a receipt
 * that does not add up.
 */
return new class extends Migration
{
    /** The eight states of CLAUDE.md section 6, as the database sees them. */
    private const string STATUSES = "'PENDING_PAYMENT', 'PLACED', 'CONFIRMED', 'PREPARING', 'READY', 'COMPLETED', 'CANCELLED', 'EXPIRED'";

    /** The states an order is in while it is being served. */
    private const string IN_SERVICE = "'PLACED', 'CONFIRMED', 'PREPARING', 'READY', 'COMPLETED'";

    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            // Said out loud across a counter, so short and unambiguous:
            // SCP-20261120-0042. Unique platform-wide, because a customer
            // reading a code over the phone does not name a store.
            $table->string('order_code', 32)->unique();
            // Null for a guest order. The contact snapshot below is what staff
            // actually work from, and it is kept either way.
            $table->uuid('customer_id')->nullable();
            $table->string('contact_name', 150);
            $table->string('contact_phone', 20);
            $table->string('contact_email', 255)->nullable();
            $table->string('status', 20);
            $table->string('fulfillment_type', 20);
            // When the customer wants it. Null means as soon as it is ready.
            $table->timestampTz('scheduled_at')->nullable();
            // A snapshot too: deleting a saved address does not rewrite history.
            $table->string('delivery_address_line', 255)->nullable();
            $table->text('note')->nullable();
            $table->bigInteger('subtotal_amount');
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('delivery_fee_amount')->default(0);
            // Zero until tax rules are decided (CLAUDE.md section 15). The
            // column exists so the totals stay complete when they are.
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('total_amount');
            $table->char('currency', 3)->default('VND');
            // When the order entered the service flow. Null while it is still
            // waiting for a transfer that may never arrive.
            $table->timestampTz('placed_at')->nullable();
            $table->timestampsTz();

            // Target for every child table's composite foreign key, so a line
            // or a payment can only ever belong to its order's store.
            $table->unique(['id', 'store_id']);
            // The queue reads one store newest-first; the work list reads one
            // store filtered by status. Both are hot on every shift.
            $table->index(['store_id', 'created_at']);
            $table->index(['store_id', 'status']);
            // What staff type when a customer reads a code aloud.
            $table->index(['store_id', 'order_code']);

            // restrictOnDelete, never cascade: an order is financial history
            // and must outlive any attempt to tidy up a store row.
            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            // The order survives the account being deleted; the contact
            // snapshot is what keeps it readable.
            $table->foreign('customer_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            $table->uuid('order_id');
            // Nullable because the snapshot is the authority: a line stays
            // readable even once master data is gone. Kept for reporting.
            $table->uuid('product_id')->nullable();
            $table->uuid('product_variant_id')->nullable();
            $table->string('product_name', 200);
            $table->string('variant_name', 100);
            $table->integer('quantity');
            $table->bigInteger('unit_price_amount');
            $table->bigInteger('modifiers_total_amount')->default(0);
            $table->bigInteger('line_total_amount');
            $table->char('currency', 3)->default('VND');
            $table->text('note')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['order_id', 'sort_order']);

            // The child is meaningless without its order, and orders are never
            // deleted, so cascade here costs nothing and keeps the schema honest.
            $table->foreign(['order_id', 'store_id'])
                ->references(['id', 'store_id'])->on('orders')
                ->cascadeOnDelete();
            // Composite, so a line can never point at another store's product.
            // MATCH SIMPLE: a null id satisfies the constraint, which is what
            // lets the snapshot outlive the master row.
            $table->foreign(['product_id', 'store_id'])
                ->references(['id', 'store_id'])->on('products')
                ->nullOnDelete();
            $table->foreign(['product_variant_id', 'store_id'])
                ->references(['id', 'store_id'])->on('product_variants')
                ->nullOnDelete();
        });

        Schema::create('order_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            $table->uuid('order_id');
            // Null on the row that records the order being created.
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            // Null when the system made the move - a scheduled job expiring an
            // unpaid transfer. A real distinction, not a gap to fill.
            $table->uuid('actor_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);

            $table->foreign(['order_id', 'store_id'])
                ->references(['id', 'store_id'])->on('orders')
                ->cascadeOnDelete();
            // Who moved an order is history that outlives their account.
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });

        $this->addConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }

    /**
     * Raw SQL for what the schema builder cannot express (CLAUDE.md section 7).
     *
     * These are the invariants that have to hold for a seeder, a data fix or a
     * future service on the same tables too - which is exactly why they live in
     * the database and not only in PHP.
     */
    private function addConstraints(): void
    {
        DB::statement('
            ALTER TABLE orders
            ADD CONSTRAINT orders_status_valid
            CHECK (status IN ('.self::STATUSES.'))
        ');

        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT orders_fulfillment_type_valid
            CHECK (fulfillment_type IN ('PICKUP', 'DELIVERY'))
        ");

        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT orders_currency_supported
            CHECK (currency = 'VND')
        ");

        DB::statement('
            ALTER TABLE orders
            ADD CONSTRAINT orders_amounts_nonnegative
            CHECK (
                subtotal_amount >= 0
                AND discount_amount >= 0
                AND delivery_fee_amount >= 0
                AND tax_amount >= 0
                AND total_amount >= 0
            )
        ');

        // A discount can take an order to zero but never below it.
        DB::statement('
            ALTER TABLE orders
            ADD CONSTRAINT orders_discount_within_subtotal
            CHECK (discount_amount <= subtotal_amount)
        ');

        // The receipt adds up. Without this a pricing bug writes a total
        // nobody can reproduce from the lines, and the money is simply wrong.
        DB::statement('
            ALTER TABLE orders
            ADD CONSTRAINT orders_total_consistent
            CHECK (total_amount = subtotal_amount - discount_amount + delivery_fee_amount + tax_amount)
        ');

        // Delivery with no address is an order nobody can fulfil.
        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT orders_delivery_has_address
            CHECK (fulfillment_type <> 'DELIVERY' OR delivery_address_line IS NOT NULL)
        ");

        // An order still waiting for payment was never placed...
        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT orders_pending_payment_not_placed
            CHECK (status <> 'PENDING_PAYMENT' OR placed_at IS NULL)
        ");

        // ...and one inside the service flow knows when it was.
        DB::statement('
            ALTER TABLE orders
            ADD CONSTRAINT orders_in_service_is_placed
            CHECK (status NOT IN ('.self::IN_SERVICE.') OR placed_at IS NOT NULL)
        ');

        DB::statement('
            ALTER TABLE order_items
            ADD CONSTRAINT order_items_quantity_positive
            CHECK (quantity > 0)
        ');

        DB::statement('
            ALTER TABLE order_items
            ADD CONSTRAINT order_items_amounts_nonnegative
            CHECK (
                unit_price_amount >= 0
                AND modifiers_total_amount >= 0
                AND line_total_amount >= 0
            )
        ');

        // The line adds up the same way the order does.
        DB::statement('
            ALTER TABLE order_items
            ADD CONSTRAINT order_items_line_total_consistent
            CHECK (line_total_amount = (unit_price_amount + modifiers_total_amount) * quantity)
        ');

        DB::statement("
            ALTER TABLE order_items
            ADD CONSTRAINT order_items_currency_supported
            CHECK (currency = 'VND')
        ");

        DB::statement('
            ALTER TABLE order_status_history
            ADD CONSTRAINT order_status_history_statuses_valid
            CHECK (
                to_status IN ('.self::STATUSES.')
                AND (from_status IS NULL OR from_status IN ('.self::STATUSES.'))
            )
        ');

        // Moving an order to the status it already holds is not a transition.
        DB::statement('
            ALTER TABLE order_status_history
            ADD CONSTRAINT order_status_history_moves
            CHECK (from_status IS NULL OR from_status <> to_status)
        ');

        // The queue's own read: this store's open orders, oldest first. A
        // partial index keeps it proportional to today rather than to every
        // order the store has ever served.
        DB::statement("
            CREATE INDEX idx_orders_open_queue
            ON orders (store_id, created_at)
            WHERE status IN ('PENDING_PAYMENT', 'PLACED', 'CONFIRMED', 'PREPARING', 'READY')
        ");
    }
};
