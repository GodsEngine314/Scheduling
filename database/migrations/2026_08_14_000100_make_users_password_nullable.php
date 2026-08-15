<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users.password becomes NULLABLE, because this table is a PROJECTION.
 *
 * THIS FIXES A BUG THAT STOPPED USERS PROJECTING AT ALL. The table came from
 * Laravel's stock migration, where password is NOT NULL — but scheduling does
 * not authenticate anyone, and UserCreatedHandler says so in as many words:
 * "Only replicate what the event gives us; do not invent password/role/etc".
 * auth.v1.user.* carries no password and never should.
 *
 * So every user.created event hit a NOT NULL violation, threw, burned its five
 * attempts and PARKED — which is the worst shape this failure could take,
 * because a parked event is dropped from JetStream and never redelivered. The
 * projection was silently missing every user, and the only visible symptom was
 * that attribution never resolved.
 *
 * Nullable rather than defaulted to '': an empty hash is a value that could be
 * mistaken for a credential by anything that later tries to verify one. NULL
 * says there is no password here, which is the truth — scheduling verifies
 * tokens against the auth service and holds nothing to check them with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows projected from auth have no password, so they must go before the
        // column can be NOT NULL again — otherwise the down migration fails on
        // exactly the data this change exists to allow.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->default('')->change();
        });
    }
};
