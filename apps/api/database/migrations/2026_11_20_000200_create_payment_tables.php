<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment: how an order is being paid, and whether the money has been seen.
 *
 * Payment state is a SEPARATE state machine from order state (CLAUDE.md
 * section 6). An order can be COMPLETED with its payment still PENDING, which
 * is precisely what an uncollected cash order is - so the two never live in
 * one column, and `COMPLETED` is never read as `PAID`.
 *
 * What this milestone stores is what the operations queue displays: the method
 * and the status of one order's payment. Deliberately NOT here yet:
 *
 *   - store_bank_accounts, the VietQR payload and the transfer reference. They
 *     belong to the payments screen together with the manual confirmation flow
 *     that gives them meaning; half of a settlement feature is worse than none.
 *   - payment_transactions and payment_events, which record bank evidence and
 *     who confirmed what. They arrive with that confirmation endpoint.
 *   - refunds, and any provider webhook. Out of scope until approved.
 *
 * One payment per order: multiple partial payments are not in MVP scope
 * (docs/database/DATABASE_SPEC.md section 9, open question 5), and the unique
 * index below is what keeps a second one from appearing by accident.
 */
return new class extends Migration
{
    private const string STATUSES = "'PENDING', 'PROCESSING', 'PAID', 'PARTIALLY_REFUNDED', 'REFUNDED', 'FAILED'";

    /** The statuses that mean money was actually seen at some point. */
    private const string SETTLED = "'PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'";

    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            $table->uuid('order_id');
            // CASH or BANK_TRANSFER. Fixed MVP scope (CLAUDE.md section 6).
            $table->string('method', 20);
            $table->string('status', 20)->default('PENDING');
            $table->bigInteger('amount');
            $table->char('currency', 3)->default('VND');
            // End of the transfer window, for BANK_TRANSFER. Null for cash,
            // which is collected when the customer arrives.
            $table->timestampTz('expires_at')->nullable();
            // When authorized staff confirmed the REAL money. Never when a QR
            // was generated or a customer said they had transferred.
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            // One payment per order; see the class note.
            $table->unique('order_id');
            // The reconciliation queue reads one store by status.
            $table->index(['store_id', 'status']);

            // restrictOnDelete throughout: a payment is financial history.
            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            $table->foreign(['order_id', 'store_id'])
                ->references(['id', 'store_id'])->on('orders')
                ->restrictOnDelete();
        });

        $this->addConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }

    /** Raw SQL for what the schema builder cannot express (CLAUDE.md section 7). */
    private function addConstraints(): void
    {
        DB::statement("
            ALTER TABLE payments
            ADD CONSTRAINT payments_method_valid
            CHECK (method IN ('CASH', 'BANK_TRANSFER'))
        ");

        DB::statement('
            ALTER TABLE payments
            ADD CONSTRAINT payments_status_valid
            CHECK (status IN ('.self::STATUSES.'))
        ');

        DB::statement("
            ALTER TABLE payments
            ADD CONSTRAINT payments_currency_supported
            CHECK (currency = 'VND')
        ");

        DB::statement('
            ALTER TABLE payments
            ADD CONSTRAINT payments_amount_nonnegative
            CHECK (amount >= 0)
        ');

        // A payment that claims to be paid says when the money was seen, and
        // one that has not been paid cannot carry a time. Both directions,
        // because either half alone still allows a lie about settlement.
        DB::statement('
            ALTER TABLE payments
            ADD CONSTRAINT payments_paid_at_matches_status
            CHECK (
                (status IN ('.self::SETTLED.') AND paid_at IS NOT NULL)
                OR (status NOT IN ('.self::SETTLED.') AND paid_at IS NULL)
            )
        ');

        // Cash is collected in person; there is no transfer window to expire.
        DB::statement("
            ALTER TABLE payments
            ADD CONSTRAINT payments_cash_has_no_window
            CHECK (method <> 'CASH' OR expires_at IS NULL)
        ");
    }
};
