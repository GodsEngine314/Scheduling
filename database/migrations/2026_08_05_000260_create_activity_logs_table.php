<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed what, and when. SCHEDULING-OWNED and append-only.
 *
 * Several managers share one schedule, so "the shift moved" is only half an
 * answer — the useful half is who moved it and what it looked like before.
 * This is the table that settles that argument.
 *
 * ONE table for every action rather than a shift log plus a general audit:
 * two write paths drift, and the same edit ends up in both. The shift record
 * is a query against this, not a second table.
 *
 * APPEND-ONLY: created_at and nothing else. No updated_at, because a row here
 * is never revised, and no soft delete, because an audit trail you can quietly
 * remove rows from is not an audit trail.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // nullOnDelete, NOT restrict. users is a PROJECTION of
            // auth.v1.user.* — under restrict, a legitimate user.deleted event
            // could never be applied, the handler would throw, burn its five
            // attempts and park forever. Exactly the failure the position_id
            // rule cured.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // ...which is why the name is COPIED IN at write time. The FK can
            // go null; the answer to "who did this" must not.
            $table->string('actor_name');

            // No FK: a store can be removed upstream and the history of what
            // happened there still has to survive.
            $table->unsignedBigInteger('store_id')->nullable();

            $table->enum('subject_type', ['shift', 'work_segment', 'employee_request', 'day']);

            // No FK either — polymorphic across three tables, and a hard-deleted
            // subject must not take its own history with it. Null for a day
            // close, which is about a date rather than a row.
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->enum('action', [
                'created',
                'updated',
                'moved',
                'copied',
                'split',
                'deleted',
                'approved',
                'corrected',
                'decided',
                'published',
                'unpublished',
                'day_closed',
            ]);

            // The day the change was ABOUT, not the day it was made. Lets the
            // board show "what happened to this date" rather than "what
            // happened while someone was looking at it".
            $table->date('business_date')->nullable();

            // {field: {from, to}} — only what actually changed.
            //
            // NOT named `changes`: Eloquent\Model has a protected $changes
            // property for dirty tracking, and PHP resolves a real property
            // before __get(), so $log->changes would silently hand back the
            // model's internals instead of this column and every audit detail
            // would render blank.
            $table->json('changed_fields')->nullable();

            // Everything else worth keeping: the delete rule used, the warning
            // that was on screen, the flash text the actor saw.
            $table->json('context')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['store_id', 'created_at']);        // the panel
            $table->index(['subject_type', 'subject_id']);    // one shift's history
            $table->index(['business_date', 'store_id']);     // one day's history
            $table->index(['user_id', 'created_at']);         // one person's history
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
