<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A guest order's own key, for reading it back without a customer session.
 *
 * Only the hash is stored - the same reasoning as `auth_sessions.refresh_token_hash`
 * (CLAUDE.md section 8): a stolen database must not yield a usable token, and
 * "forgot the id" must not be a way to brute-force one, since a guest order
 * token is the ONLY thing standing between the order's id and its contact
 * name, phone and delivery address (CLAUDE.md section 6: "do not expose
 * orders by guessable ID alone").
 *
 * Null for a signed-in customer's order, which is read through their session
 * instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('guest_access_token_hash', 64)->nullable()->unique()->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('guest_access_token_hash');
        });
    }
};
