<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TCP job code role => our position. SCHEDULING-OWNED, so a replay cannot
 * erase it.
 *
 * One row per role suffix, MANY of which may point at the same position: 04 and
 * 08 are both Assistant Manager. That is the reason this is not a row in
 * integration_identities — see the migration.
 */
class TcpJobCodeRole extends Model
{
    protected $table = 'tcp_job_code_roles';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'code_count' => 'integer',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * The position a whole jobCodeId means, or null.
     *
     * Takes the WHOLE code and decodes it, so callers do not each re-implement
     * the franchise/store/role split. A four-digit company-wide code (1000
     * Regular, 2000 Sick) is not a role and returns null rather than borrowing
     * suffix '00'.
     */
    public static function positionIdFor(string $jobCodeId): ?int
    {
        if (preg_match('/^\d{4}\d{2}(\d{2})$/', trim($jobCodeId), $parts) !== 1) {
            return null;
        }

        $positionId = static::query()->where('role_suffix', $parts[1])->value('position_id');

        return $positionId === null ? null : (int) $positionId;
    }

    /**
     * The other direction: which whole jobCodeId to SEND for this store and
     * position.
     *
     * TCP REQUIRES IT ON A WRITE. POST /worksegments rejects a body without one
     * outright — "The jobCodeId must have a value." — so a segment that cannot
     * produce a code here cannot be pushed at all, and saying so early is worth
     * more than a 400 that names no field.
     *
     * Reassembles what positionIdFor() takes apart: franchise, store, role.
     * Store 03795-00042 + Crew Leader gives 37954202, which is the code in the
     * migration's own worked example.
     *
     * THE REVERSE MAPPING IS AMBIGUOUS AND HAS TO CHOOSE. Suffix 04 and suffix
     * 08 are both Assistant Manager, so a position does not name one code. The
     * tiebreak is code_count — how many of the estate's stores use that suffix —
     * which is exactly the fact that column was recorded to supply: 04 covers
     * 38 stores and 08 covers 1, so 04 is the estate's answer and the one-store
     * oddity is not silently adopted for everybody. role_suffix breaks a tie
     * beneath that, so the choice is at least deterministic when counts match.
     *
     * Returns null rather than guessing whenever any part is unavailable or out
     * of range: no position, no mapping for it, an unparseable store number, or
     * a store whose number will not fit the two digits the code allows. A wrong
     * job code does not fail — it books somebody's hours against the wrong role,
     * which is a payroll error nobody sees.
     */
    public static function jobCodeIdFor(?string $storeNumber, ?int $positionId): ?string
    {
        if ($positionId === null) {
            return null;
        }

        $storeKey = static::storeKeyFor($storeNumber);

        if ($storeKey === null) {
            return null;
        }

        // EVERY suffix this position could be, best first — not just the best
        // one. Assistant Manager is 04 at thirty-eight stores and 08 at one, so
        // a single answer would be wrong at whichever store it did not name.
        // Ordered by code_count so the estate-wide code is preferred and the
        // one-store variant is only reached where it is the only option.
        $suffixes = static::query()
            ->where('position_id', $positionId)
            ->orderByDesc('code_count')
            ->orderBy('role_suffix')
            ->pluck('role_suffix');

        if ($suffixes->isEmpty()) {
            return null;
        }

        // THE CATALOGUE DECIDES, when we have one. A code is only sent if TCP
        // is known to have it — see TcpJobCode and its migration: franchise +
        // store + role is a SHAPE, and 37951007 is a perfectly well-formed name
        // for something that does not exist.
        if (TcpJobCode::isPopulated()) {
            foreach ($suffixes as $suffix) {
                $code = TcpJobCode::codeFor($storeKey, (string) $suffix);

                if ($code !== null) {
                    return $code;
                }
            }

            // TCP has no code for this role at this store. A real answer, and
            // the caller must not fall through to synthesis on the strength of
            // it — that is the exact invention this branch exists to stop.
            return null;
        }

        // No catalogue read here yet, so fall back to building the code from
        // its parts. This is what shipped before the catalogue existed and it
        // is right for the 01–06 roles that every store carries; it is a guess
        // for 07 and 08. Preferred over refusing every punch, which is what
        // treating an unread table as an empty estate would do.
        return $storeKey.str_pad((string) $suffixes->first(), 2, '0', STR_PAD_LEFT);
    }

    /**
     * franchise + store, the first six digits of any per-store code.
     *
     * '03795-00010' is stores.store_number; '379510' is what the code carries.
     * Both halves arrive zero-padded and neither padding survives.
     */
    public static function storeKeyFor(?string $storeNumber): ?string
    {
        if ($storeNumber === null) {
            return null;
        }

        if (preg_match('/^(\d{1,5})-(\d{1,5})$/', trim($storeNumber), $parts) !== 1) {
            return null;
        }

        $franchise = (int) $parts[1];
        $store = (int) $parts[2];

        // Four digits for the franchise and two for the store is all the code
        // has. A store numbered past 99 has no representation, and a truncated
        // code would book hours against a DIFFERENT store.
        if ($franchise > 9999 || $store > 99) {
            return null;
        }

        return sprintf('%04d%02d', $franchise, $store);
    }

    /**
     * The positions whose hours this store can actually file at TCP.
     *
     * WHAT THIS IS FOR: the hand-entry form used to offer every position in the
     * table, and three of them — Driver, Insider, Shift Lead — have no TCP job
     * code at all. Picking one produced a punch that saved cleanly, appeared on
     * the board, and could never be pushed. The writer's guard caught it, but
     * only after the fact, which is the worst shape this bug takes: the hours
     * look recorded and payroll never sees them.
     *
     * PER STORE, not estate-wide, because the answer genuinely differs. TCP
     * carries Management at exactly one store, so offering it everywhere would
     * reproduce the same trap one role further along.
     *
     * AN EMPTY ARRAY IS A REAL ANSWER and does not mean "offer everything": it
     * means TCP has no codes for this store, which is true of the demo store and
     * of any store missing from the vendor. What a DROPDOWN should do with that
     * is a different question — see positionIdsOfferableAt().
     *
     * @return array<int,int> position ids
     */
    public static function positionIdsPushableAt(?string $storeNumber): array
    {
        $storeKey = static::storeKeyFor($storeNumber);

        if ($storeKey === null) {
            return [];
        }

        $roles = static::query()->get(['position_id', 'role_suffix']);

        // No catalogue read here yet. Every mapped role is offered, matching
        // what jobCodeIdFor() will do — see its fallback branch. Offering
        // nothing would leave the form unusable on a fresh environment.
        if (! TcpJobCode::isPopulated()) {
            return $roles->pluck('position_id')->unique()->map(intval(...))->values()->all();
        }

        $available = TcpJobCode::query()
            ->where('store_key', $storeKey)
            ->where('active', true)
            ->pluck('role_suffix')
            ->all();

        return $roles
            ->whereIn('role_suffix', $available)
            ->pluck('position_id')
            ->unique()
            ->map(intval(...))
            ->values()
            ->all();
    }

    /**
     * Every position TCP has a job code for SOMEWHERE in the estate.
     *
     * The set the board's dropdowns are drawn from when the per-store answer is
     * unavailable. Driver, Insider and Shift Lead are not in it — nothing in
     * TCP's 230 codes names them, at any store — which is the whole point:
     * they are positions this system invented, and every one of them is a
     * dead end for hours.
     *
     * @return array<int,int> position ids
     */
    public static function positionIdsWithJobCodes(): array
    {
        return static::query()
            ->pluck('position_id')
            ->unique()
            ->map(intval(...))
            ->values()
            ->all();
    }

    /**
     * WHAT A POSITION DROPDOWN SHOULD OFFER at this store.
     *
     * positionIdsPushableAt() answers a payroll question and is right to answer
     * [] for a store TCP does not carry. A FORM cannot render that: an empty
     * required select is a dead screen, and the demo store — the board's own
     * default — is exactly that store. This is the question the view asks
     * instead, and it never has an empty answer while anything is seeded.
     *
     * Three tiers, narrowest first:
     *
     *   1. The codes TCP has AT THIS STORE. Management is store 42's alone and
     *      is offered nowhere else.
     *   2. The codes TCP has ANYWHERE, when the store itself is not in TCP.
     *      Nothing can be filed from such a store today, and the writer says so
     *      on the chip; offering a role the vendor has never heard of would add
     *      a second, invisible reason for the same failure.
     *   3. Nothing seeded at all — a fresh environment. The caller falls back to
     *      the full table, because a form that offers nothing is worse than one
     *      that offers too much, and guardPushablePosition() is the boundary
     *      either way.
     *
     * @return array<int,int> position ids; empty only on tier 3
     */
    public static function positionIdsOfferableAt(?string $storeNumber): array
    {
        $pushable = static::positionIdsPushableAt($storeNumber);

        return $pushable === [] ? static::positionIdsWithJobCodes() : $pushable;
    }

    /**
     * The role suffix a position prefers, ignoring which stores have it.
     *
     * Kept for reporting and for tests; the push path uses jobCodeIdFor(),
     * which considers every suffix and checks each against the catalogue.
     */
    public static function roleSuffixFor(int $positionId): ?string
    {
        $suffix = static::query()
            ->where('position_id', $positionId)
            ->orderByDesc('code_count')
            ->orderBy('role_suffix')
            ->value('role_suffix');

        return $suffix === null ? null : (string) $suffix;
    }
}
