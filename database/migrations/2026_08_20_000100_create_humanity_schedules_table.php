<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Humanity's POSITION catalogue — the `schedule` id every published shift has to
 * name. SCHEDULING-OWNED, read from GET /positions.
 *
 * WHY THIS IS NOT integration_identities, which the positions migration says is
 * where the Humanity schedule id belongs. That table can hold exactly one
 * mapping per entity per system: UNIQUE(entity_type, entity_id, system). A
 * Humanity schedule is not per position — it is per position PER LOCATION.
 * Reading the live account, one position spreads across the estate:
 *
 *     Crew Member - 3795-01  => 4086919
 *     Crew Member - 3795-02  => 4086932
 *     Crew Member - 3795-05  => 4086929
 *     ... 40 more, one per store that staffs the role
 *
 * So "Crew Member" has no single Humanity id to record, and the shape the other
 * table can express is the wrong shape. Widening it with a nullable store scope
 * was the alternative and it is worse: MySQL permits repeated NULLs inside a
 * unique index, so adding a nullable column to those keys would quietly retire
 * the "one mapping per entity per system" guarantee that employee and store rows
 * depend on.
 *
 * tcp_job_codes is the precedent and the parallel is exact — a per-store
 * catalogue of the vendor's own ids, kept because franchise+store+role is a
 * SHAPE and not a guarantee that the vendor has the thing. Here too: only some
 * stores carry Manager, Crew Leader or Assistant Manager schedules, so a
 * well-formed request naming a position the store does not have is a request for
 * something that does not exist.
 *
 * WHY IT IS LOAD-BEARING. `schedule` is REQUIRED on POST /shifts. Until this
 * table is populated the publisher has nothing to send, the field is dropped,
 * and Humanity rejects every shift in the week.
 *
 * REBUILT, NEVER EDITED. HumanitySeeder replaces the whole catalogue, so a
 * position retired at Humanity disappears here instead of lingering as an id we
 * would still send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('humanity_schedules', function (Blueprint $table) {
            $table->id();

            /**
             * Humanity's own id for the position, and the value that travels as
             * the `schedule` field of a shift.
             *
             * A string, because it is an identifier rather than a quantity and
             * nothing here does arithmetic on it — the same reason
             * integration_identities.external_id is a string.
             */
            $table->string('schedule_id', 32)->unique();

            /**
             * NULLABLE ON PURPOSE, both of them, and null means two different
             * things worth keeping apart from "no row at all":
             *
             *   store_id null    — a company-wide schedule. The live account has
             *                      "Bonus" and a bare "Assistant Manager", which
             *                      belong to no location. Recorded so the seeder
             *                      can report them rather than appear to have
             *                      silently lost rows.
             *   position_id null — Humanity staffs something we have no position
             *                      for. A fact worth holding: it is the list a
             *                      human needs to see to decide whether the
             *                      position is missing here or obsolete there.
             *
             * No foreign keys. stores and positions are PROJECTIONS rebuilt from
             * the stream, and constraining a scheduling-owned catalogue to
             * replayable rows is the coupling integration_identities exists to
             * avoid. A restrictOnDelete here would also let one retired position
             * park the projector permanently.
             */
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('position_id')->nullable();

            // Humanity's label, verbatim: "Crew Member - 3795-25". Kept for the
            // seeder's reporting and so anyone reading the table can see which
            // vendor row a mapping came from without re-exporting.
            $table->string('name');

            // Humanity's location id for this schedule, when the export carries
            // one. Not sent on a shift — see SchedulePublisher::desiredState —
            // but it is what confirms a name-based store join was right.
            $table->string('location_external_id', 32)->nullable();

            /**
             * A deleted position stays in the table and stops being offered.
             * GET /positions can return deleted rows (include_deleted), and a
             * retired schedule that merely vanished from the catalogue would be
             * indistinguishable from one nobody has exported yet.
             */
            $table->boolean('active')->default(true);

            $table->timestamps();

            /**
             * THE LOOKUP: "which schedule covers this store and this position".
             *
             * An index and not a unique key. Nothing stops a Humanity account
             * from carrying two positions with the same name at one location,
             * and a unique key would abort the entire catalogue rebuild over one
             * vendor-side duplicate — trading a reportable ambiguity for a
             * publish path with no schedule ids at all. The seeder reports
             * duplicates instead, and the lookup is deterministic.
             */
            $table->index(['store_id', 'position_id'], 'humanity_schedules_store_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('humanity_schedules');
    }
};
