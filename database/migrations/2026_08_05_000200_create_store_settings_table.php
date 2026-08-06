<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEDULING-OWNED. A stream replay must never touch this table.
 *
 * timezone is the load-bearing column. It is what turns a UTC start_at into a
 * business_date, what decides which day an overnight shift belongs to, and
 * what makes "close the day" mean the same thing in two states. auth's store
 * events do not carry it, so it cannot live on the stores projection — a
 * replay would erase it.
 *
 * NO foreign key to stores, on purpose. Scheduling-owned rows must not be
 * constrained by a replayable table: rebuilding the projection (truncate +
 * replay) would either fail against the constraint or, with a cascade, delete
 * the settings it was supposed to preserve. Orphan rows are reported by a
 * maintenance check instead, where a human can look at them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('store_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->unique();
            $table->string('timezone', 64)->default('America/New_York');

            // Local time after which the day may be closed; null = no cutoff.
            $table->time('day_close_cutoff_time')->nullable();

            // How far ahead a publish run is allowed to push shifts to Humanity.
            $table->unsignedSmallInteger('publish_lead_days')->default(14);
            $table->boolean('auto_publish')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_settings');
    }
};
