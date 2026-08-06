<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROJECTION of the positions carried inside hiring.v1.employee.* payloads
 * (positions.position). Rebuilt by replay.
 *
 * The Humanity `schedule` id and TCP `jobCodeId` that correspond to a
 * position do NOT belong here — they are scheduling's own discoveries and
 * live in integration_identities so a replay cannot erase them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
