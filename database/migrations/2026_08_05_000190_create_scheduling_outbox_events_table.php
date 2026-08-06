<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copied from HiringPizza's hiring_outbox_events, renamed for this service so
 * PublishPendingOutboxCommand / PublishOutboxEventJob port across unchanged.
 *
 * Carries scheduling.v1.* — shift published, hours approved, day closed.
 * Written in the same transaction as the state change it describes, which is
 * the point of an outbox: no published event without the row it reports, and
 * no row silently unpublished.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('scheduling_outbox_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('subject', 190)->index();
            $table->string('type', 190)->index(); // same as subject/type
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_outbox_events');
    }
};
