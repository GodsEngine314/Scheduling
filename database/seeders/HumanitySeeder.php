<?php

namespace Database\Seeders;

use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSyncState;
use App\Enums\IntegrationSystem;
use App\Models\Employee;
use App\Models\IntegrationIdentity;
use App\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The Humanity id for every employee and every store.
 *
 * THE GAP THIS CLOSES is named in the integration_identities migration itself:
 * "the Humanity employee id (nothing populates this today — it is the known gap
 * that makes shift staffing fail)". A published shift has to say WHO works it
 * and WHERE, in Humanity's numbering, and until this runs there is no numbering
 * to say it in.
 *
 * FROM A FILE, NOT THE API, because there is no Humanity token configured — and
 * because the two id joins below were confirmed against a real export rather
 * than assumed:
 *
 *   EMPLOYEES join on Humanity's `eid`, which holds the TCP employee id. All 50
 *       TCP employees in the sample matched, none by name. `eid` is the join and
 *       nothing else is: names collide, emails are frequently blank.
 *
 *   LOCATIONS join on `name`, which holds our store_number verbatim
 *       ("03795-00001"). Humanity's own `location` field on an EMPLOYEE is
 *       useless for this — it reads "0" on 471 of 472 records — so the employee
 *       side is not scoped by store here at all.
 *
 * The files are read from storage/app/integrations, which is gitignored: they
 * carry names, emails, phone numbers and birthdays for the whole company.
 *
 * Idempotent and additive. It writes only integration_identities, which is
 * SCHEDULING-OWNED and survives a replay — the same rule TcpEmployeeReader and
 * StoreSeeder follow. It creates no employees and no stores: a Humanity record
 * we cannot already account for is REPORTED, because `employees` is a projection
 * and an invented row there is erased by the next replay.
 */
class HumanitySeeder extends Seeder
{
    private const EMPLOYEES_FILE = 'integrations/humanity-employees.json';

    private const LOCATIONS_FILE = 'integrations/humanity-locations.json';

    /**
     * Humanity's own office row, which is not a store.
     *
     * type 1 is a location; type 2 is the corporate address, and it is called
     * "New York" while sitting on a San Francisco street. Skipped by TYPE rather
     * than by name — the name is somebody's free text and will change.
     */
    private const LOCATION_TYPE_STORE = 1;

    public function run(): void
    {
        $this->seedEmployees();
        $this->seedLocations();
    }

    private function seedEmployees(): void
    {
        $records = $this->read(self::EMPLOYEES_FILE);

        if ($records === null) {
            return;
        }

        // tcp_employee_id => local id, in one query. 400-odd employees against
        // 472 Humanity records is not a lookup to run per row.
        $byTcpId = Employee::query()
            ->whereNotNull('tcp_employee_id')
            ->pluck('id', 'tcp_employee_id');

        $mapped = 0;
        $unmatched = 0;
        $noEid = 0;

        foreach ($records as $record) {
            $humanityId = $this->string($record['id'] ?? null);
            $eid = $this->string($record['eid'] ?? null);

            if ($humanityId === null) {
                continue;
            }

            if ($eid === null) {
                $noEid++;

                continue;
            }

            $localId = $byTcpId[$eid] ?? null;

            if ($localId === null) {
                // In Humanity, unknown to us. Reported, never created.
                $unmatched++;

                continue;
            }

            $this->map(IntegrationEntityType::Employee, (int) $localId, $humanityId);
            $mapped++;
        }

        $this->command?->info(
            "HumanitySeeder: {$mapped} employees mapped from ".count($records).' Humanity records'
            .($unmatched > 0 ? ", {$unmatched} not in our roster" : '')
            .($noEid > 0 ? ", {$noEid} with no eid to join on" : '').'.'
        );
    }

    private function seedLocations(): void
    {
        $records = $this->read(self::LOCATIONS_FILE);

        if ($records === null) {
            return;
        }

        $byNumber = Store::query()->whereNotNull('store_number')->pluck('id', 'store_number');

        $mapped = 0;
        $unmatched = [];

        foreach ($records as $record) {
            $humanityId = $this->string($record['id'] ?? null);
            $name = $this->string($record['name'] ?? null);

            if ($humanityId === null || $name === null) {
                continue;
            }

            if ((int) ($record['type'] ?? self::LOCATION_TYPE_STORE) !== self::LOCATION_TYPE_STORE) {
                continue;
            }

            if (($record['deleted'] ?? false) === true) {
                continue;
            }

            $storeId = $byNumber[$name] ?? null;

            if ($storeId === null) {
                $unmatched[] = $name;

                continue;
            }

            $this->map(IntegrationEntityType::Store, (int) $storeId, $humanityId);
            $mapped++;
        }

        $this->command?->info(
            "HumanitySeeder: {$mapped} locations mapped"
            .($unmatched === []
                ? '.'
                : ', '.count($unmatched).' with no store here ('.implode(', ', array_slice($unmatched, 0, 5)).').')
        );
    }

    /**
     * Written Synced because these ids came back from Humanity itself — the same
     * reasoning StoreSeeder and EmployeeSeeder give for theirs.
     *
     * updateOrCreate on (entity_type, entity_id, system): re-running corrects a
     * mapping rather than colliding with UNIQUE(entity_type, entity_id, system).
     */
    private function map(IntegrationEntityType $type, int $entityId, string $externalId): void
    {
        DB::transaction(static function () use ($type, $entityId, $externalId): void {
            IntegrationIdentity::query()->updateOrCreate(
                [
                    'entity_type' => $type,
                    'entity_id' => $entityId,
                    'system' => IntegrationSystem::Humanity,
                ],
                [
                    'external_id' => $externalId,
                    'sync_state' => IntegrationSyncState::Synced,
                    'synced_at' => now(),
                    'last_error' => null,
                    'attempts' => 0,
                ],
            );
        });
    }

    /**
     * The `data` array out of a Humanity export.
     *
     * Returns null — not an empty list — when the file is missing, so the caller
     * can say so once instead of reporting a successful zero.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function read(string $relativePath): ?array
    {
        $path = storage_path('app/'.$relativePath);

        if (! is_file($path)) {
            $this->command?->warn(
                "HumanitySeeder: {$relativePath} not found. Put the Humanity export at "
                .'storage/app/'.$relativePath.' and run this again.'
            );

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            $this->command?->warn("HumanitySeeder: {$relativePath} is not valid JSON.");

            return null;
        }

        // Humanity wraps in {status, data, metadata, token, error}; a bare list
        // is accepted too, because an export somebody unwrapped by hand is the
        // likelier shape of the next file to land here.
        $records = $decoded['data'] ?? $decoded;

        return is_array($records) ? array_values(array_filter($records, 'is_array')) : null;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
