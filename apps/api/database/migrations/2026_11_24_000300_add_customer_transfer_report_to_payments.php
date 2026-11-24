<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a customer's own "I have transferred" report lands.
 *
 * CLAUDE.md section 6: this is a notification, never proof. Nothing reads
 * these two columns to change `status` - a human still confirms after
 * checking the real bank credit (see `ConfirmPayment`, unchanged by this).
 * `customer_receipt_path` is optional and may be overwritten by a later
 * report; only the most recent one is kept, there is no history of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestampTz('customer_reported_at')->nullable()->after('expires_at');
            $table->string('customer_receipt_path', 255)->nullable()->after('customer_reported_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['customer_reported_at', 'customer_receipt_path']);
        });
    }
};
