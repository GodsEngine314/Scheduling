<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROJECTION of auth.v1.store.* — rebuilt by replaying the stream.
 * Nothing scheduling discovers for itself may live here.
 *
 * The primary key is NOT auto-incrementing: store ids are assigned by the
 * auth system and must match byte-for-byte across auth, hiring and
 * scheduling. This mirrors HiringPizza's own stores migration.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('store_number');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
