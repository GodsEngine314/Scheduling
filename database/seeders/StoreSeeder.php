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
     * State => IANA timezone.
     *
     * store_settings.timezone is what turns a UTC start_at into a business_date
     * and decides which day an overnight shift belongs to, so a wrong value here
     * puts shifts on the wrong DAY rather than merely displaying them oddly.
     *
     * A STATE IS NOT A TIMEZONE, and three of these straddle a boundary. Each
     * is set to the zone the state's population centre keeps, which is a
     * defensible default and not a confirmed fact:
     *
     *   IN  Indiana is mostly Eastern, but the north-west and south-west
     *       corners are Central.
     *   KY  Louisville and the east are Eastern; the west is Central.
     *   SD  Sioux Falls and the east are Central; the west is Mountain.
     *
     * Confirm those three against the actual addresses before payroll leans on
     * them; the rest are unambiguous.
     *
     * @var array<string, string>
     */
    private const TIMEZONE_BY_STATE = [
        'OH' => 'America/New_York',
        'MA' => 'America/New_York',
        'MI' => 'America/Detroit',
        'IN' => 'America/Indiana/Indianapolis',
        'KY' => 'America/Kentucky/Louisville',
        'IA' => 'America/Chicago',
        'MS' => 'America/Chicago',
        'AL' => 'America/Chicago',
        'SD' => 'America/Chicago',
        'CO' => 'America/Denver',
        'WY' => 'America/Denver',
    ];

    /** Used only if a state ever appears here without a mapping above. */
    private const FALLBACK_TIMEZONE = 'America/New_York';

    /**
     * store_number suffix => [TCP location id, state], verbatim from
     * GET /locations.
     *
     * The suffixes are NOT a contiguous range: 00032 to 00037 do not exist and
     * are not a gap waiting to be tidied away. Closing it would invent six
     * stores nobody can staff.
     *
     * TCP's ids are not contiguous either, and not ordered by store number —
     * 00004, 00023, 00027 and 00042 were created in earlier batches and carry
     * much lower ids. Do not try to compute one from the other.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const STORES = [
        '00001' => ['9830400', 'OH'],
        '00002' => ['9830401', 'OH'],
        '00003' => ['9830402', 'OH'],
        '00004' => ['6127616', 'OH'],
        '00005' => ['9830403', 'OH'],
        '00006' => ['9830404', 'OH'],
        '00007' => ['9830405', 'OH'],
        '00008' => ['9830406', 'OH'],
        '00009' => ['9830407', 'OH'],
        '00010' => ['9830408', 'OH'],
        '00011' => ['9830409', 'OH'],
        '00012' => ['12189696', 'OH'],
        '00013' => ['12189697', 'OH'],
        '00014' => ['12189698', 'OH'],
        '00015' => ['12189699', 'OH'],
        '00016' => ['12189700', 'OH'],
        '00017' => ['12189701', 'OH'],
        '00018' => ['12189702', 'OH'],
        '00019' => ['12189703', 'OH'],
        '00020' => ['12189704', 'OH'],
        '00021' => ['12189705', 'OH'],
        '00022' => ['12189706', 'IN'],
        '00023' => ['3440640', 'KY'],
        '00024' => ['12189707', 'IA'],
        '00025' => ['12189708', 'MA'],
        '00026' => ['12189709', 'MA'],
        '00027' => ['6127617', 'CO'],
        '00028' => ['12189710', 'SD'],
        '00029' => ['12189711', 'MS'],
        '00030' => ['12189712', 'AL'],
        '00031' => ['12189713', 'WY'],
        '00038' => ['12189714', 'MI'],
        '00039' => ['12189715', 'MI'],
        '00040' => ['12189716', 'MI'],
        '00041' => ['12189717', 'MI'],
        '00042' => ['6127618', 'MI'],
        '00043' => ['12189718', 'MI'],
        '00044' => ['12189719', 'IA'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::STORES as $suffix => [$tcpLocationId, $state]) {
                $storeId = $this->storeId($suffix);

                Store::query()->updateOrCreate(
                    ['id' => $storeId],
                    ['store_number' => self::GROUP.'-'.$suffix],
                );

                // The row is created with the tunable defaults ONCE and never
                // reset — publish_lead_days and auto_publish are preferences,
                // and a re-run must not undo somebody's choice.
                $settings = StoreSetting::query()->firstOrCreate(
                    ['store_id' => $storeId],
                    [
                        'timezone' => $this->timezoneFor($state),
                        'day_close_cutoff_time' => null,
                        'publish_lead_days' => 14,
                        'auto_publish' => false,
                    ],
                );

                // timezone is the exception, and is corrected on every run.
                // It is DERIVED from the store's state in TCP rather than
                // chosen, so it belongs with the external ids below: leaving a
                // stale value would keep filing that store's overnight shifts
                // on the wrong business_date.
                $settings->forceFill(['timezone' => $this->timezoneFor($state)])->save();

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

    private function timezoneFor(string $state): string
    {
        return self::TIMEZONE_BY_STATE[$state] ?? self::FALLBACK_TIMEZONE;
    }
}
