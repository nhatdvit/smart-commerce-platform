<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform infrastructure: audit trail, transactional outbox and idempotency.
 *
 * These three carry the guarantees the business flows depend on, so they exist
 * from the foundation milestone rather than being retrofitted once orders are
 * already flowing (CLAUDE.md sections 6 and 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Append only. Never updated, never deleted.
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('store_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('action', 100);
            $table->string('entity_type', 100);
            $table->string('entity_id', 64);
            // Must arrive already redacted: no passwords, tokens or secrets.
            $table->jsonb('changes')->nullable();
            $table->string('request_id', 128)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['store_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_id', 'created_at']);

            // nullOnDelete, not cascade: the history of an action outlives the
            // store or the account that performed it.
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });

        // Transactional outbox. A row is inserted in the same transaction as
        // the business change, so an event can never describe a change that
        // rolled back, and a committed change can never lose its event.
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('aggregate_type', 100);
            $table->string('aggregate_id', 64);
            $table->string('event_type', 100);
            $table->jsonb('payload');
            $table->string('status', 20)->default('PENDING');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('published_at')->nullable();

            $table->index(['status', 'created_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        // Stored result of an idempotent request, so a client retry replays the
        // first outcome instead of creating a second order.
        Schema::create('idempotency_records', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            // Operation namespace, for example order.create
            $table->string('scope', 100);
            $table->string('key', 255);
            // Hash of the canonicalised request, so reusing a key with
            // different input is rejected rather than silently replayed.
            $table->string('request_hash', 128);
            $table->string('status', 20)->default('IN_PROGRESS');
            $table->integer('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            // This unique index is the mechanism: a concurrent retry collides
            // here rather than duplicating the work.
            $table->unique(['scope', 'key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('audit_logs');
    }
};
