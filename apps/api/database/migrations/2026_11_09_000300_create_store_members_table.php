<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff membership of a store, with the store-scoped role.
 *
 * Owned by the Identity module (CLAUDE.md section 5) but created after stores,
 * because it references both. This table is what makes store scoping real:
 * every admin operation checks the role permission AND a matching active row
 * here (CLAUDE.md section 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_members', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id');
            $table->uuid('user_id');
            $table->uuid('role_id');
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampsTz();

            // One membership per user per store.
            $table->unique(['store_id', 'user_id']);
            $table->index('user_id');
            $table->index('role_id');
            // The authorization hot path: given a user and a store, find the
            // active membership.
            $table->index(['user_id', 'store_id', 'status']);

            // Restrict throughout: losing a membership row silently would
            // either remove someone access without a trace or orphan an audit
            // trail that refers to it.
            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_members');
    }
};
