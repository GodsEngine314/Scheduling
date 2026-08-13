<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\StoreSetting;
use App\Support\BusinessDay;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The store roster for the 03795 group.
 *
 * stores is a PROJECTION of auth.v1.store.*, so in a real environment these rows
 * arrive as events and this seeder never runs. It exists because scheduling has
 * to be developable before auth is publishing anything: it writes exactly what a
 * StoreCreatedHandler would write — id and store_number, nothing else — so that
 * a later replay overwrites it with identical values rather than fighting it.
 *
 * IDEMPOTENT, AND IT NEVER DELETES. Re-running adds what is missing and leaves
 * the rest alone, including any store_settings row a human has since edited.
 * DemoSeeder calls it after its own wipe so the roster survives a reseed.
 */
class StoreSeeder extends Seeder
{
    /** The group prefix — the first half of every store_number here. */
    private const GROUP = '03795';

    /**
     * ASSUMPTION, and the one worth checking first.
     *
     * store_settings.timezone is what turns a UTC start_at into a business_date
     * and decides which day an overnight shift belongs to, so a wrong value here
     * puts shifts on the wrong day rather than merely displaying them oddly. All
     * 38 stores are seeded in one zone because nothing upstream tells us where
     * any of them are; auth's store events do not carry a timezone. Split this
     * per store as soon as that is known.
     */
    private const TIMEZONE = 'America/New_York';

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->numbers() as $number) {
                $suffix = str_pad((string) $number, 5, '0', STR_PAD_LEFT);

                Store::query()->updateOrCreate(
                    ['id' => $this->storeId($suffix)],
                    ['store_number' => self::GROUP.'-'.$suffix],
                );

                // firstOrCreate, NOT updateOrCreate: everything on this row is
                // scheduling-owned and tunable per store. Re-running the seeder
                // must not reset a timezone or a publish window somebody set.
                StoreSetting::query()->firstOrCreate(
                    ['store_id' => $this->storeId($suffix)],
                    [
                        'timezone' => self::TIMEZONE,
                        'day_close_cutoff_time' => null,
                        'publish_lead_days' => 14,
                        'auto_publish' => false,
                    ],
                );
            }
        });

        // store_settings just moved, and BusinessDay memoises store_id =>
        // timezone in a STATIC array that outlives this seeder in the same
        // process. Without this, anything seeding shifts afterwards resolves
        // business_date against a zone read before these rows existed.
        BusinessDay::flushTimezoneCache();
    }

    /**
     * The id auth would have assigned: the store_number with the dash taken out.
     *
     * Derivable from the number in both directions, so a row can be matched up
     * by hand against auth later, and prefixed by the group so another group's
     * 00001 cannot land on top of this one.
     */
    private function storeId(string $suffix): int
    {
        return (int) (self::GROUP.$suffix);
    }

    /**
     * The stores that exist, as integers.
     *
     * 00032 to 00037 ARE ABSENT ON PURPOSE. They are not a gap waiting to be
     * tidied into a single range — closing it would invent six stores that
     * nobody can staff and that every roster query would then report as empty.
     *
     * @return list<int>
     */
    private function numbers(): array
    {
        return [
            ...range(1, 31),
            38, 39, 40, 41, 42, 43, 44,
        ];
    }
}
