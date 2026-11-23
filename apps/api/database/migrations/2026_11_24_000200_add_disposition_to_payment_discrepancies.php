<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What happens to the excess, for an OVERPAID discrepancy.
 *
 * CLAUDE.md section 6 (decision dated 2026-11-24): confirming an overpaid
 * payment requires the operator to say on the spot whether the store keeps
 * the excess, refunds it immediately, or owes it back as a debt to settle
 * later - {@see App\Modules\Payment\Domain\Enum\OverpaymentDisposition}.
 * Null for every other discrepancy type, which this decision does not touch.
 */
return new class extends Migration
{
    private const string DISPOSITIONS = "'KEEP', 'REFUND', 'DEBT'";

    public function up(): void
    {
        Schema::table('payment_discrepancies', function (Blueprint $table): void {
            $table->string('disposition', 20)->nullable()->after('observed_amount');
        });

        DB::statement('
            ALTER TABLE payment_discrepancies
            ADD CONSTRAINT payment_discrepancies_disposition_check
            CHECK (disposition IS NULL OR disposition IN ('.self::DISPOSITIONS.'))
        ');

        DB::statement("
            ALTER TABLE payment_discrepancies
            ADD CONSTRAINT payment_discrepancies_disposition_only_for_overpaid
            CHECK (disposition IS NULL OR type = 'OVERPAID')
        ");
    }

    public function down(): void
    {
        Schema::table('payment_discrepancies', function (Blueprint $table): void {
            $table->dropColumn('disposition');
        });
    }
};
