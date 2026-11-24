<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots the order's contact name onto `payments`, the same way
 * `order_code` already is (see the class note on
 * `2026_11_21_000100_create_payment_settlement_tables.php`).
 *
 * The admin payments screen lists who a reconciliation row belongs to without
 * joining Order's tables for it: `Payment reads nothing of Order's` stays
 * true, and a customer renaming themselves later does not rewrite who staff
 * actually collected from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('customer_name', 160)->nullable()->after('order_code');
        });

        $this->backfillCustomerName();

        DB::statement('ALTER TABLE payments ALTER COLUMN customer_name SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('customer_name');
        });
    }

    /** The only time this table is ever joined to `orders` for this column. */
    private function backfillCustomerName(): void
    {
        DB::statement('
            UPDATE payments
            SET customer_name = orders.contact_name
            FROM orders
            WHERE orders.id = payments.order_id
        ');
    }
};
