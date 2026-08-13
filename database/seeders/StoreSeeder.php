<?php

namespace Database\Seeders;

use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSyncState;
use App\Enums\IntegrationSystem;
use App\Models\IntegrationIdentity;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Support\BusinessDay;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The store roster for the 03795 group, and its TCP location ids.
 *
 * stores is a PROJECTION of auth.v1.store.*, so in a real environment those rows
 * arrive as events and this seeder never runs. It exists because scheduling has
 * to be developable before auth is publishing anything: it writes exactly what a
 * StoreCreatedHandler would write — id and store_number, nothing else — so that
 * a later replay overwrites it with identical values rather than fighting it.
 *
 * The integration_identities half is a different kind of row and is NOT a
 * stand-in for anything. Those ids came from TCP's own GET /locations, they are
 * SCHEDULING-OWNED, and they are the only thing that lets an inbound punch's
 * locationId resolve to one of our stores — see
 * WorkSegmentSyncService::resolveStoreId(). Putting them on the stores
 * projection instead would mean a replay erased them.
 *
 * IDEMPOTENT, AND IT NEVER DELETES. DemoSeeder calls it after its own wipe so
 * the roster survives a reseed.
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
     * puts shifts on the wrong day rather than merely displaying them oddly.
     *
     * TCP's location records carry a `state` and these stores span at least
     * OH, IN, KY, IA, MA, CO, SD, MS, AL, WY and MI — so this single default is
     * WRONG for the western and central ones. It stays until someone confirms
     * the real zone per store, because a state is not a timezone: IN, KY and SD
     * each straddle two.
     */
    private const TIMEZONE = 'America/New_York';

    /**
     * store_number suffix => TCP location id, verbatim from GET /locations.
     *
     * The suffixes are NOT a contiguous range: 00032 to 00037 do not exist and
     * are not a gap waiting to be tidied away. Closing it would invent six
     * stores nobody can staff.
     *
     * TCP's ids are not contiguous either, and not ordered by store number —
     * 00004, 00023, 00027 and 00042 were created in earlier batches and carry
     * much lower ids. Do not try to compute one from the other.
     *
     * @var array<string, string>
     */
    private const TCP_LOCATION_IDS = [
        '00001' => '9830400',
        '00002' => '9830401',
        '00003' => '9830402',
        '00004' => '6127616',
        '00005' => '9830403',
        '00006' => '9830404',
        '00007' => '9830405',
        '00008' => '9830406',
        '00009' => '9830407',
        '00010' => '9830408',
        '00011' => '9830409',
        '00012' => '12189696',
        '00013' => '12189697',
        '00014' => '12189698',
        '00015' => '12189699',
        '00016' => '12189700',
        '00017' => '12189701',
        '00018' => '12189702',
        '00019' => '12189703',
        '00020' => '12189704',
        '00021' => '12189705',
        '00022' => '12189706',
        '00023' => '3440640',
        '00024' => '12189707',
        '00025' => '12189708',
        '00026' => '12189709',
        '00027' => '6127617',
        '00028' => '12189710',
        '00029' => '12189711',
        '00030' => '12189712',
        '00031' => '12189713',
        '00038' => '12189714',
        '00039' => '12189715',
        '00040' => '12189716',
        '00041' => '12189717',
        '00042' => '6127618',
        '00043' => '12189718',
        '00044' => '12189719',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::TCP_LOCATION_IDS as $suffix => $tcpLocationId) {
                $storeId = $this->storeId($suffix);

                Store::query()->updateOrCreate(
                    ['id' => $storeId],
                    ['store_number' => self::GROUP.'-'.$suffix],
                );

                // firstOrCreate, NOT updateOrCreate: everything on this row is
                // scheduling-owned and tunable per store. Re-running the seeder
                // must not reset a timezone or a publish window somebody set.
                StoreSetting::query()->firstOrCreate(
                    ['store_id' => $storeId],
                    [
                        'timezone' => self::TIMEZONE,
                        'day_close_cutoff_time' => null,
                        'publish_lead_days' => 14,
                        'auto_publish' => false,
                    ],
                );

                // updateOrCreate here, unlike the settings above: an external id
                // is a fact TCP owns, not a preference, so a corrected one in
                // the table above should win on the next run. Keyed on the
                // UNIQUE(entity_type, entity_id, system) side.
                IntegrationIdentity::query()->updateOrCreate(
                    [
                        'entity_type' => IntegrationEntityType::Store,
                        'entity_id' => $storeId,
                        'system' => IntegrationSystem::Tcp,
                    ],
                    [
                        'external_id' => $tcpLocationId,
                        // Synced, not pending: these ids were read back from
                        // TCP's own /locations response, so the mapping IS
                        // confirmed. sync_state is the record of that, and
                        // anything less would have the retry command treat a
                        // known-good row as unfinished work.
                        'sync_state' => IntegrationSyncState::Synced,
                        'synced_at' => now(),
                        'last_error' => null,
                        'attempts' => 0,
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
     * 00001 cannot land on top of this one. Deliberately NOT TCP's id — that one
     * belongs in integration_identities, where a projection rebuild cannot
     * erase it.
     */
    private function storeId(string $suffix): int
    {
        return (int) (self::GROUP.$suffix);
    }
}
