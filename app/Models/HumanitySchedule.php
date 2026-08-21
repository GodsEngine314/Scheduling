<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Humanity schedule — what its API calls a POSITION and what a shift calls
 * `schedule`. SCHEDULING-OWNED, rebuilt by HumanitySeeder from GET /positions.
 *
 * The catalogue the publisher consults before naming a schedule on a shift.
 * See the migration for why this is not a row in integration_identities: a
 * Humanity schedule is per position PER STORE, and that table holds one mapping
 * per entity per system.
 */
class HumanitySchedule extends Model
{
    protected $table = 'humanity_schedules';

    protected $guarded = [];

    /**
     * NOT FOR DISPLAY, and hidden rather than merely unused.
     *
     * job_code is a join key and an audit trail. The board shows position
     * labels; a manager has no use for 37951001, and anything that serialises
     * this model should not start leaking TCP's numbering into a payload just
     * because the column exists.
     *
     * @var array<int, string>
     */
    protected $hidden = ['job_code'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'store_id' => 'integer',
            'position_id' => 'integer',
        ];
    }

    /**
     * The Humanity schedule id for this store and position, or null.
     *
     * NULL IS A REAL ANSWER, not a lookup miss to route around — the same rule
     * TcpJobCode::codeFor() follows. Only some stores carry Manager, Crew Leader
     * or Assistant Manager schedules, so asking for one where Humanity has none
     * is asking for something that does not exist, and the only safe reply is to
     * say so and refuse the publish.
     *
     * A JOB-CODE MATCH WINS, and that ordering is not a tie-breaker of
     * convenience — it is what the vendor's own data says.
     *
     * Store 3795-23 carries TWO Humanity positions for each of its roles: one
     * named "Assistant Manager - 3795-23" with job code 37952304, and a bare
     * "Assistant Manager" with no code at all. Both resolve to the same store
     * and position, so both are legitimate candidates.
     *
     * A live GET /shifts settles it. The store's real, existing shifts read
     * `schedule = 4070307` — the JOB-CODED one. Ordering by id would have picked
     * whichever the export happened to list first, and publishing into the bare
     * duplicate would have filed the store's week alongside its own history
     * instead of in it.
     *
     * Then id, so a genuine tie still resolves to the same schedule on every
     * run: silently alternating between two ids would have one shift bounce
     * between two Humanity rosters.
     */
    public static function scheduleFor(int $storeId, int $positionId): ?string
    {
        $scheduleId = static::query()
            ->where('store_id', $storeId)
            ->where('position_id', $positionId)
            ->where('active', true)
            ->orderByRaw("CASE WHEN matched_by = 'job_code' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->value('schedule_id');

        return $scheduleId === null ? null : (string) $scheduleId;
    }

    /**
     * Whether the catalogue has been read at all.
     *
     * An EMPTY table and a table that says "no such schedule" are different
     * facts and must not be collapsed. Empty means nobody has exported
     * GET /positions here yet, and the publisher says so differently — "run the
     * seeder" is a fixable instruction, where "this store does not staff Drivers
     * in Humanity" is a decision somebody has to make in Humanity.
     */
    public static function isPopulated(): bool
    {
        return static::query()->exists();
    }
}
