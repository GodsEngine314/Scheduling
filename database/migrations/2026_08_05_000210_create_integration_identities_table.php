<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEDULING-OWNED. A stream replay must never touch this table.
 *
 * The map between our projected entities and their ids in TCP and Humanity:
 * the Humanity employee id (nothing populates this today — it is the known gap
 * that makes shift staffing fail), the Humanity `schedule` id per position,
 * the TCP jobCodeId per position, and the TCP/Humanity location ids per store.
 *
 * Why this is separate from employee_identifiers: that table is a projection
 * and gets rebuilt from the stream. If a Humanity shift id mapping lived on a
 * projection, a replay would wipe it and the next publish run would create
 * every shift in Humanity a second time.
 *
 * NO foreign keys: entity_id is polymorphic across three projections, and
 * constraining non-replayable rows to replayable ones is the exact coupling
 * this table exists to avoid.
 *
 * external_id stays NULL until the remote create succeeds — a row with
 * external_id IS NULL and sync_state = 'failed' is precisely what a retry
 * command selects on.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('integration_identities', function (Blueprint $table) {
            $table->id();
            $table->enum('entity_type', ['employee', 'store', 'position']);
            $table->unsignedBigInteger('entity_id');
            $table->enum('system', ['tcp', 'humanity']);

            $table->string('external_id', 64)->nullable();
            $table->string('external_record_id', 64)->nullable();

            $table->enum('sync_state', ['pending', 'synced', 'failed'])->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // One mapping per entity per system.
            $table->unique(['entity_type', 'entity_id', 'system']);

            // And no two entities of a kind claiming the same remote id.
            // MySQL permits repeated NULLs here, so unsynced rows don't collide.
            $table->unique(['system', 'entity_type', 'external_id']);

            $table->index(['sync_state', 'system']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_identities');
    }
};
