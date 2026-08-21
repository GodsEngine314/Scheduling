<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The TCP job code carried by a Humanity position, and how the mapping was made.
 *
 * WHY THE CATALOGUE STOPPED JOINING ON NAMES. A live GET /positions shows this
 * account puts the TCP job code ON the Humanity position:
 *
 *     Crew Member - 3795-10        job_code 37951001
 *     Assistant Manager - 3795-23  job_code 37952304
 *
 * 65 of the 67 codes present are real rows in tcp_job_codes. That makes the job
 * code a far better join than the name, and not marginally:
 *
 *   IT IS UNAMBIGUOUS. Store 3795-23 carries a position called
 *       "Assistant Manager - 3795-23" AND the account carries a bare
 *       "Assistant Manager" with no job code and no location. Matching on names
 *       made those collide and the seeder reported a duplicate it had to break
 *       by picking the lower id. The job code separates them outright — the bare
 *       one has no code, so it claims no store and no position.
 *
 *   IT SURVIVES A RENAME. A manager retitling a position in Humanity silently
 *       broke the name join and every shift for that store stopped publishing.
 *       The code does not change when the label does.
 *
 *   IT IS THE SAME CATALOGUE PUNCHES ALREADY USE. tcp_job_codes is what an
 *       outbound punch is checked against, so plan and actual now resolve a
 *       store's roles through one shared list instead of two guesses.
 *
 * The two codes that are NOT in tcp_job_codes are correct exclusions: "Bonus"
 * (1003) is one of the seven COMPANY-WIDE pay categories the tcp_job_codes
 * migration deliberately leaves out — it names how an hour is paid, not what
 * somebody did — and "test" (1) is not a code at all.
 *
 * THE JOB CODE IS NOT FOR DISPLAY. It is a join key and an audit trail; nothing
 * renders it. The board goes on showing position labels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('humanity_schedules', function (Blueprint $table) {
            /**
             * The code as Humanity spells it, verbatim, whether or not TCP has
             * it. A code we cannot account for is worth keeping — it is how
             * anyone works out later why a schedule fell back to a name match.
             */
            $table->string('job_code', 16)->nullable()->after('name');

            /**
             * Which join produced store_id and position_id: 'job_code', 'name',
             * or 'none'.
             *
             * Recorded because the two joins are not equally trustworthy and a
             * row cannot otherwise say which it got. 'name' is a fallback that
             * works and can be wrong; seeing how many rows rely on it is the
             * only way to know whether Humanity's job codes have gone stale.
             */
            $table->string('matched_by', 16)->default('none');

            $table->index('job_code');
        });
    }

    public function down(): void
    {
        Schema::table('humanity_schedules', function (Blueprint $table) {
            $table->dropIndex(['job_code']);
            $table->dropColumn(['job_code', 'matched_by']);
        });
    }
};
