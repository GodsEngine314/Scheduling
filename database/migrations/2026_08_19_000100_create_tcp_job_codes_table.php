<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TCP's job code CATALOGUE — every per-store code that actually exists.
 * SCHEDULING-OWNED, read from GET /jobcodes.
 *
 * WHY THIS EXISTS, AND WHY tcp_job_code_roles WAS NOT ENOUGH. That table maps a
 * role suffix to a position, which is all the INBOUND direction needs: a punch
 * arrives carrying a whole code and only the last two digits say what was
 * worked. Going the other way needs something it cannot answer — whether the
 * code we are about to send is one TCP HAS.
 *
 * It is not, uniformly. Reading the live list:
 *
 *     230 per-store codes across 38 stores
 *     every store carries 01–06
 *     ONLY store 42 carries 07 (Management) and 08 (Assistant Manager)
 *
 * So franchise+store+role is a shape, not a guarantee. Building 37951007 for
 * Management at store 10 produces a well-formed code for something that does
 * not exist, and the failure mode is the bad one: TCP either rejects it — after
 * a round trip, with a message about a field no manager has heard of — or
 * accepts hours against a code nobody reconciles.
 *
 * The seven COMPANY-WIDE codes (1000 Regular, 1001 Training, 1002 Tipping,
 * 1003 Bonus, 2000 Sick, 3000 Meal Penalty, 3001 Rest Penalty) are deliberately
 * NOT stored here. They are pay categories, not positions — "Regular" names how
 * an hour is paid, not what somebody did — and PositionSeeder has always left
 * them unmapped for the same reason.
 *
 * REBUILT, NEVER EDITED. PositionSeeder truncates and re-reads, so a code
 * retired at TCP disappears here rather than lingering as something we would
 * still send. Nothing else writes to it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tcp_job_codes', function (Blueprint $table) {
            $table->id();

            // The whole code as TCP spells it: 37951001. A string, because the
            // leading digits are structure rather than magnitude and nothing
            // here does arithmetic on it.
            $table->string('job_code_id', 16)->unique();

            /**
             * franchise + store, the code's first six digits: 379510.
             *
             * Stored rather than derived at query time so the lookup is a plain
             * indexed equality. It is built from stores.store_number the same
             * way on both sides — see TcpJobCodeRole::storeKeyFor().
             */
            $table->string('store_key', 8)->index();

            // The trailing two digits: what was worked. Joins to
            // tcp_job_code_roles.role_suffix, without a foreign key — a code TCP
            // has for a role we have not mapped is a fact worth keeping, and a
            // constraint would refuse to record it.
            $table->string('role_suffix', 8);

            // TCP's own label, for the seeder's reporting and for anyone
            // wondering what 07 is at the one store that has it.
            $table->string('description');

            /**
             * Every code read so far has been active, so this has never yet
             * excluded anything. It is recorded because an inactive code is
             * exactly the kind of thing that would otherwise be sent
             * confidently and rejected — and the lookup filters on it.
             */
            $table->boolean('active')->default(true);

            $table->timestamps();

            // The lookup: "which code covers this store and this role".
            $table->unique(['store_key', 'role_suffix'], 'tcp_job_codes_store_role_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tcp_job_codes');
    }
};
