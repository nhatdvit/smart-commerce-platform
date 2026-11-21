<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Settlement: where transfers land, what arrived, and who said so.
 *
 * The MVP payment decision (CLAUDE.md section 6, dated 2026-11-09) is that
 * money is confirmed by a named human who has seen it - cash in the till, or a
 * credit on the real bank statement. Everything here exists to make that
 * statement recordable, attributable and hard to fake:
 *
 *   - `store_bank_accounts` is where transfers are asked to go. A typo here
 *     does not fail loudly, it sends customers' money to a stranger, so the
 *     format is checked by the database as well as the application and every
 *     change is audited.
 *   - `payments` grows the beneficiary SNAPSHOT, the transfer reference and
 *     the QR payload. Snapshotted because editing the account tomorrow must
 *     not rewrite the instructions a customer paid against yesterday.
 *   - `payment_events` is the trail: every status change with its actor.
 *   - `payment_discrepancies` is what settlement actually produces in a shop -
 *     underpayment, a wrong reference, a duplicate credit, money that landed
 *     after the order was cancelled. Kept as data because resolving them is a
 *     store policy decision section 15 has not settled.
 *   - `payment_methods` is the switch a store flips per method. A store that
 *     accepts neither cash nor transfer cannot take an order at all, which is
 *     exactly the state the application layer refuses to leave it in.
 *
 * `payments.order_code` is a SNAPSHOT, the same way an order line snapshots a
 * product name (CLAUDE.md section 6): the reconciliation queue has to show
 * which order a payment belongs to, and the alternative - this module reading
 * Order's tables, or depending on an exported Order contract - would make
 * Order and Payment depend on each other in both directions. A code never
 * changes once an order exists, so copying it costs nothing a join would not
 * have cost anyway, and it keeps the dependency one-way: Order reads Payment
 * through `ProvidesPaymentSummaries`, Payment reads nothing of Order's.
 *
 * Deliberately NOT here: `payment_transactions` from the database spec. It is
 * a ledger of bank credits, and it only earns its keep once something reads a
 * bank feed automatically - which is explicitly out of scope until approved.
 * One payment, one settlement, and anything else is a discrepancy a human
 * looks at.
 */
return new class extends Migration
{
    private const string DISCREPANCY_TYPES = "'UNDERPAID', 'OVERPAID', 'MISSING_REFERENCE', 'UNKNOWN_REFERENCE', 'DUPLICATE_TRANSFER', 'LATE_TRANSFER', 'CANCELLED_ORDER_PAID'";

    private const string PAYMENT_STATUSES = "'PENDING', 'PROCESSING', 'PAID', 'PARTIALLY_REFUNDED', 'REFUNDED', 'FAILED'";

    public function up(): void
    {
        Schema::create('store_bank_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            // VietQR bank identifier, six digits. Not the SWIFT code: this is
            // what a NAPAS payload carries.
            $table->string('bank_bin', 6);
            $table->string('bank_name', 120);
            $table->string('account_number', 20);
            // As registered at the bank; a mismatch can bounce a transfer.
            $table->string('account_name', 160);
            $table->boolean('is_default')->default(true);
            $table->boolean('is_active')->default(true);
            // Who last touched where the money goes. The audit log carries the
            // detail; this is for showing it on the screen without a join.
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->index(['store_id', 'is_active']);

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table): void {
            // Snapshot, not a foreign key into Order's tables; see the class
            // note. Nullable for the rename step below only - every row is
            // backfilled before the column is made mandatory.
            $table->string('order_code', 32)->nullable()->after('order_id');
            // Unique platform-wide: this is what a bank statement line is
            // matched against, and two orders answering to one reference would
            // make that match a guess.
            $table->string('transfer_reference', 32)->nullable()->unique();
            // The EMVCo string a customer's banking app scans. Stored, not
            // regenerated, so what they paid against stays readable.
            $table->text('qr_payload')->nullable();
            $table->uuid('bank_account_id')->nullable();
            // Beneficiary snapshot. See the class note.
            $table->string('bank_bin', 6)->nullable();
            $table->string('bank_account_number', 20)->nullable();
            $table->string('bank_account_name', 160)->nullable();
            // What staff recorded as actually received, which is not always
            // what was owed.
            $table->bigInteger('confirmed_amount')->nullable();
            // The bank's own reference, typed from a statement line.
            $table->string('bank_transaction_reference', 120)->nullable();
            $table->uuid('confirmed_by')->nullable();
            // When staff recorded it. `paid_at` is when the money appeared;
            // keeping both is what lets a late confirmation stay honest about
            // when the credit actually landed.
            $table->timestampTz('confirmed_at')->nullable();

            // Target for the composite foreign keys of the two child tables
            // below, so an event or a discrepancy can never cross stores. The
            // first migration had no child to reference it and so had no
            // reason to carry it.
            $table->unique(['id', 'store_id']);

            $table->foreign('bank_account_id')
                ->references('id')->on('store_bank_accounts')
                ->restrictOnDelete();
            $table->foreign('confirmed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('payment_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            $table->uuid('payment_id');
            $table->string('event_type', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            // Null when the system moved it - an expiry job, say.
            $table->uuid('actor_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['payment_id', 'created_at']);

            $table->foreign(['payment_id', 'store_id'])
                ->references(['id', 'store_id'])->on('payments')
                ->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            $table->string('method', 20);
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();

            $table->unique(['store_id', 'method']);

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });

        Schema::create('payment_discrepancies', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            $table->uuid('payment_id');
            $table->string('type', 40);
            $table->bigInteger('expected_amount')->nullable();
            $table->bigInteger('observed_amount')->nullable();
            $table->string('note', 500)->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->timestampTz('recorded_at')->useCurrent();

            $table->index(['store_id', 'recorded_at']);
            $table->index('payment_id');

            $table->foreign(['payment_id', 'store_id'])
                ->references(['id', 'store_id'])->on('payments')
                ->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
        });

        $this->backfillOrderCode();
        $this->returnUnattributedPaymentsToTheQueue();
        $this->seedDefaultPaymentMethods();
        $this->addConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('payment_discrepancies');
        Schema::dropIfExists('payment_events');

        // The raw-SQL index from addConstraints(): the schema builder did not
        // create it, so dropColumn() will not remove it either.
        DB::statement('DROP INDEX IF EXISTS idx_payments_outstanding');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['bank_account_id']);
            $table->dropForeign(['confirmed_by']);
            $table->dropUnique(['id', 'store_id']);
            $table->dropColumn([
                'order_code',
                'transfer_reference',
                'qr_payload',
                'bank_account_id',
                'bank_bin',
                'bank_account_number',
                'bank_account_name',
                'confirmed_amount',
                'bank_transaction_reference',
                'confirmed_by',
                'confirmed_at',
            ]);
        });

        Schema::dropIfExists('store_bank_accounts');
    }

    /**
     * Copies each payment's order code from `orders`, once, before the column
     * is made mandatory.
     *
     * The only time this table is ever joined to `orders` directly: every
     * later read of `order_code` is the snapshot column, never this join. A
     * payment created after this migration gets its code at creation time,
     * from whatever created the order in the first place.
     */
    private function backfillOrderCode(): void
    {
        DB::statement('
            UPDATE payments
            SET order_code = orders.order_code
            FROM orders
            WHERE orders.id = payments.order_id
        ');
    }

    /**
     * Every store gets both MVP methods, enabled, the same way a store gets a
     * menu in DefaultMenuSeeder: reference data a store cannot function
     * without, provisioned once rather than invented by a "create defaults"
     * endpoint nothing offers.
     */
    private function seedDefaultPaymentMethods(): void
    {
        $storeIds = DB::table('stores')->pluck('id');
        $now = now();

        $rows = [];

        foreach ($storeIds as $storeId) {
            foreach (['CASH', 'BANK_TRANSFER'] as $method) {
                $rows[] = [
                    // UUIDv7, matching every other id in this schema, rather
                    // than the database default - this insert bypasses Eloquent.
                    'id' => (string) Str::uuid7(),
                    'store_id' => $storeId,
                    'method' => $method,
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('payment_methods')->insert($chunk);
        }
    }

    /**
     * A PAID payment that names nobody is not a confirmation.
     *
     * Nothing in the application has ever been able to set PAID - the confirm
     * endpoint arrives with this very migration - so on a deployed database
     * this is a no-op. On a development one it returns the demo seeder's
     * pre-confirmed rows to the queue, which is where a payment with no named
     * confirmer belongs under the rule the CHECK below enforces.
     *
     * Grandfathering them would mean carrying money nobody is accountable for,
     * which is the one thing this whole table exists to prevent.
     */
    private function returnUnattributedPaymentsToTheQueue(): void
    {
        DB::table('payments')
            ->where('status', 'PAID')
            ->whereNull('confirmed_by')
            ->update(['status' => 'PENDING', 'paid_at' => null]);
    }

    /** Raw SQL for what the schema builder cannot express (CLAUDE.md section 7). */
    private function addConstraints(): void
    {
        // Mandatory from here on; every row was backfilled above.
        DB::statement('ALTER TABLE payments ALTER COLUMN order_code SET NOT NULL');

        // A typo in a bank identifier sends money to a stranger, so the shape
        // is checked here too and not only in a Form Request.
        DB::statement("
            ALTER TABLE store_bank_accounts
            ADD CONSTRAINT store_bank_accounts_bin_format
            CHECK (bank_bin ~ '^[0-9]{6}\$')
        ");

        DB::statement("
            ALTER TABLE store_bank_accounts
            ADD CONSTRAINT store_bank_accounts_number_format
            CHECK (account_number ~ '^[0-9]{6,20}\$')
        ");

        // At most one account can be the live default for a store. A second
        // one would make "where does the money go" a question with two answers.
        DB::statement('
            CREATE UNIQUE INDEX uq_active_default_bank_account
            ON store_bank_accounts (store_id)
            WHERE is_default = TRUE AND is_active = TRUE
        ');

        DB::statement("
            ALTER TABLE payments
            ADD CONSTRAINT payments_transfer_reference_format
            CHECK (transfer_reference IS NULL OR transfer_reference ~ '^[A-Z0-9]{6,32}\$')
        ");

        // Only a transfer has a reference to match or an account to land in.
        DB::statement("
            ALTER TABLE payments
            ADD CONSTRAINT payments_transfer_fields_match_method
            CHECK (
                method = 'BANK_TRANSFER'
                OR (
                    transfer_reference IS NULL
                    AND qr_payload IS NULL
                    AND bank_account_id IS NULL
                    AND bank_bin IS NULL
                    AND bank_account_number IS NULL
                )
            )
        ");

        DB::statement('
            ALTER TABLE payments
            ADD CONSTRAINT payments_confirmed_amount_nonnegative
            CHECK (confirmed_amount IS NULL OR confirmed_amount >= 0)
        ');

        // A confirmation is a statement by a named person at a known moment:
        // the two halves arrive together or not at all.
        DB::statement('
            ALTER TABLE payments
            ADD CONSTRAINT payments_confirmation_is_attributed
            CHECK (
                (confirmed_by IS NULL AND confirmed_at IS NULL AND confirmed_amount IS NULL)
                OR (confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL AND confirmed_amount IS NOT NULL)
            )
        ');

        // PAID means somebody said so. Without this, a stray UPDATE could set
        // the status and leave no one accountable for the money.
        DB::statement("
            ALTER TABLE payments
            ADD CONSTRAINT payments_paid_is_confirmed
            CHECK (status <> 'PAID' OR confirmed_by IS NOT NULL)
        ");

        DB::statement('
            ALTER TABLE payment_events
            ADD CONSTRAINT payment_events_statuses_valid
            CHECK (
                (from_status IS NULL OR from_status IN ('.self::PAYMENT_STATUSES.'))
                AND (to_status IS NULL OR to_status IN ('.self::PAYMENT_STATUSES.'))
            )
        ');

        DB::statement('
            ALTER TABLE payment_discrepancies
            ADD CONSTRAINT payment_discrepancies_type_valid
            CHECK (type IN ('.self::DISCREPANCY_TYPES.'))
        ');

        DB::statement("
            ALTER TABLE payment_methods
            ADD CONSTRAINT payment_methods_method_valid
            CHECK (method IN ('CASH', 'BANK_TRANSFER'))
        ");

        DB::statement('
            ALTER TABLE payment_discrepancies
            ADD CONSTRAINT payment_discrepancies_amounts_nonnegative
            CHECK (
                (expected_amount IS NULL OR expected_amount >= 0)
                AND (observed_amount IS NULL OR observed_amount >= 0)
            )
        ');

        // The reconciliation queue reads one store's unsettled payments. A
        // partial index keeps that scan proportional to what is outstanding
        // rather than to every payment the store has ever taken.
        DB::statement("
            CREATE INDEX idx_payments_outstanding
            ON payments (store_id, created_at)
            WHERE status IN ('PENDING', 'PROCESSING')
        ");
    }
};
