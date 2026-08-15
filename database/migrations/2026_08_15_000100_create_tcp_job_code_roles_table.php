<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TCP job code role => our position. SCHEDULING-OWNED.
 *
 * A TCP job code encodes three things at once:
 *
 *     jobCodeId 37954202   "Crew Leader - 3795-42"
 *                ^^^^ ^^ ^^
 *                3795 42 02    franchise, store, role
 *
 * There are 237 of them across the estate because the same handful of roles is
 * repeated per store. Only the last two digits say WHAT was worked, and that is
 * the whole content of this table.
 *
 * WHY NOT integration_identities, which is where every other external id lives.
 * That table is UNIQUE(entity_type, entity_id, system) — one external id per
 * entity — and the real mapping is MANY-TO-ONE: suffix 04 and suffix 08 are
 * both Assistant Manager, at 38 stores and at 1 store respectively. Writing both
 * through integration_identities does not fail loudly; the second overwrites the
 * first, and the surviving row was the one covering a single store. Thirty-eight
 * stores' Assistant Manager punches would have resolved to NULL, silently, with
 * a seeder reporting success.
 *
 * The alternative was a position per store per role: 237 rows in a PROJECTION
 * table hiring never sent, and 237 entries in the shift dropdown.
 *
 * ON DELETE CASCADE is right here and nowhere else in this schema: without a
 * position this row means nothing, and unlike a punch it is not evidence of
 * anything — it is re-derived by running PositionSeeder again.
 *
 * A NEW STORE NEEDS NO SEEDING. 37954902 decodes through the same '02' the day
 * that store opens, because the store half of the code is not consulted.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tcp_job_code_roles', function (Blueprint $table) {
            $table->id();

            // The trailing two digits of a per-store job code. A string, not an
            // integer: '04' and '4' are the same number and different codes, and
            // the leading zero is how it arrives.
            $table->string('role_suffix', 8)->unique();

            // The name TCP gives it, kept for the seeder's own reporting — a
            // suffix that starts naming something else is a thing somebody has
            // to see, and the label on the position may since have been edited.
            $table->string('tcp_label');

            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();

            // How many store codes carried this suffix when it was last read.
            // One store out of thirty-nine is the shape of an anomaly worth
            // looking at rather than trusting.
            $table->unsignedInteger('code_count')->default(0);

            $table->timestamps();

            $table->index('position_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tcp_job_code_roles');
    }
};
