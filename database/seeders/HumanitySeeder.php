<?php

namespace Database\Seeders;

use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSyncState;
use App\Enums\IntegrationSystem;
use App\Models\Employee;
use App\Models\HumanitySchedule;
use App\Models\IntegrationIdentity;
use App\Models\Position;
use App\Models\Store;
use App\Models\TcpJobCode;
use App\Models\TcpJobCodeRole;
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
 * FROM A FILE, NOT THE API — now a choice rather than a limitation. A token is
 * configured and `humanity:export-positions` fetches GET /positions with it, but
 * the seeder still reads what that command wrote, so it stays runnable with no
 * credentials: on a developer's machine, in a test, and in whatever environment
 * comes next.
 *
 * Every join below was confirmed against a real export rather than assumed:
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
 *   SCHEDULES join on the TCP JOB CODE Humanity carries on each position —
 *       "Crew Member - 3795-10" is job_code 37951001 — which names the store and
 *       the role in one value the vendor set itself. 65 of the 67 codes present
 *       are real tcp_job_codes rows; the two that are not are "Bonus" (a
 *       company-wide pay category, correctly excluded there) and "test". The name
 *       is a FALLBACK only, because it is ambiguous — a bare "Assistant Manager"
 *       with no location collides with "Assistant Manager - 3795-23" — and
 *       because renaming a position in Humanity used to break publishing for a
 *       whole store. `schedule` is REQUIRED on POST /shifts, and it is per
 *       position PER STORE, which is why these land in humanity_schedules rather
 *       than integration_identities; that migration says why.
 *
 * The files are read from storage/app/integrations, which is gitignored: they
 * carry names, emails, phone numbers and birthdays for the whole company.
 *
 * Idempotent. The id maps are additive; the schedule CATALOGUE is replaced
 * wholesale, so a position retired at Humanity stops being an id we would send
 * — the same rule PositionSeeder follows for tcp_job_codes. Everything written
 * is SCHEDULING-OWNED and survives a replay, which is why the seeder creates no
 * employees and no stores: a Humanity record we cannot already account for is
 * REPORTED, because `employees` is a projection and an invented row there is
 * erased by the next replay.
 */
class HumanitySeeder extends Seeder
{
    private const EMPLOYEES_FILE = 'integrations/humanity-employees.json';

    private const LOCATIONS_FILE = 'integrations/humanity-locations.json';

    /**
     * A GET /positions export: [{id, name, location: {id, name}}, …].
     *
     * OPTIONAL, and the fallback is not a lesser answer by accident. Every
     * employee record already carries a `schedules` map of exactly the ids we
     * need — {"4091683":"Crew Member - 3795-25"} — so the catalogue can be built
     * from the employees export alone. What that misses is any schedule NOBODY
     * is assigned to, which is precisely the schedule a manager is about to need
     * for a store they are still staffing up. Export this file when there is a
     * token to export it with.
     */
    private const POSITIONS_FILE = 'integrations/humanity-positions.json';

    /**
     * Humanity's own office row, which is not a store.
     *
     * type 1 is a location; type 2 is the corporate address, and it is called
     * "New York" while sitting on a San Francisco street. Skipped by TYPE rather
     * than by name — the name is somebody's free text and will change.
     */
    private const LOCATION_TYPE_STORE = 1;

    /**
     * Locations BEFORE schedules, and the order is load-bearing: a GET /positions
     * export identifies its location by id, and turning that id into one of our
     * stores needs the mapping seedLocations() has just written.
     */
    public function run(): void
    {
        $this->seedEmployees();
        $this->seedLocations();
        $this->seedSchedules();
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
     * The schedule catalogue: every Humanity position id, against the store and
     * position of ours it covers.
     *
     * This is what makes publishing possible at all. `schedule` is REQUIRED on
     * POST /shifts, and until this table holds ids the publisher has nothing to
     * put there.
     */
    private function seedSchedules(): void
    {
        [$records, $source] = $this->scheduleRecords();

        if ($records === null) {
            return;
        }

        if ($records === []) {
            // NOT a rebuild. Replacing a good catalogue with nothing because an
            // export came back empty would take the whole estate's publishing
            // down, and an empty export is far likelier to be a bad token or a
            // truncated file than a company with no positions.
            $this->command?->warn(
                "HumanitySeeder: {$source} carried no schedules; the existing catalogue was left alone."
            );

            return;
        }

        $storeByLocationId = IntegrationIdentity::query()
            ->where('system', IntegrationSystem::Humanity)
            ->where('entity_type', IntegrationEntityType::Store)
            ->whereNotNull('external_id')
            ->pluck('entity_id', 'external_id');

        // store_number normalised to the form a schedule name spells it in:
        // "03795-00025" and "3795-25" both become "3795-25".
        $storeByKey = [];

        foreach (Store::query()->whereNotNull('store_number')->get(['id', 'store_number']) as $store) {
            $key = $this->storeKey((string) $store->store_number);

            if ($key !== null) {
                $storeByKey[$key] = (int) $store->id;
            }
        }

        $positionByLabel = [];

        foreach (Position::query()->get(['id', 'label']) as $position) {
            $positionByLabel[$this->fold((string) $position->label)] = (int) $position->id;
        }

        /**
         * THE PRIMARY JOIN: the TCP job code Humanity carries on each position.
         *
         * Three lookups, all preloaded — 70 positions against a 230-row
         * catalogue is not a query per record:
         *
         *   $tcpByCode        job code    => the store key and role suffix TCP
         *                                   files it under. Membership here is
         *                                   also the validity test: a code TCP
         *                                   does not have is not a code.
         *   $storeByTcpKey    store key   => our store id, built through the
         *                                   SAME TcpJobCodeRole::storeKeyFor()
         *                                   the punch path uses, so the two
         *                                   cannot disagree about a store.
         *   $positionBySuffix role suffix => our position id.
         */
        $tcpByCode = TcpJobCode::query()
            ->where('active', true)
            ->get(['job_code_id', 'store_key', 'role_suffix'])
            ->keyBy('job_code_id');

        $storeByTcpKey = [];

        foreach (Store::query()->whereNotNull('store_number')->get(['id', 'store_number']) as $store) {
            $tcpKey = TcpJobCodeRole::storeKeyFor((string) $store->store_number);

            if ($tcpKey !== null) {
                $storeByTcpKey[$tcpKey] = (int) $store->id;
            }
        }

        $positionBySuffix = TcpJobCodeRole::query()
            ->whereNotNull('position_id')
            ->pluck('position_id', 'role_suffix');

        $rows = [];
        $pairs = [];
        $duplicates = [];
        $noStore = [];
        $noPosition = [];
        $companyWide = 0;
        $byJobCode = 0;
        $byName = 0;
        $unknownCodes = [];
        $now = now();

        foreach ($records as $record) {
            [$label, $storeKey] = $this->splitScheduleName($record['name']);

            $jobCode = $record['job_code'];
            $storeId = null;
            $positionId = null;
            $matchedBy = 'none';

            /**
             * JOB CODE FIRST, and it answers BOTH halves at once — which store
             * and which role — from one value the vendor set itself.
             *
             * A code TCP does not have is not usable, and that is not always a
             * defect: "Bonus" (1003) is one of the company-wide PAY CATEGORIES
             * tcp_job_codes deliberately excludes, so falling through to the name
             * join is the right answer for it.
             */
            if ($jobCode !== null && $tcpByCode->has($jobCode)) {
                $tcp = $tcpByCode->get($jobCode);
                $storeId = $storeByTcpKey[$tcp->store_key] ?? null;
                $positionId = $positionBySuffix[$tcp->role_suffix] ?? null;

                if ($storeId !== null && $positionId !== null) {
                    $matchedBy = 'job_code';
                    $byJobCode++;
                }
            } elseif ($jobCode !== null) {
                $unknownCodes[$jobCode] = ($record['name'] ?? '?').' ('.$jobCode.')';
            }

            /**
             * NAME AND LOCATION, only where the code could not answer.
             *
             * Kept rather than deleted for two reasons: the employees-export
             * fallback carries no job codes at all, and an account that has not
             * filled its job codes in still has to be publishable. Recorded as
             * 'name' so a later reader can see how much rests on it.
             */
            if ($matchedBy === 'none') {
                // The location id is the better of these two — Humanity's own
                // answer rather than a name somebody typed — so it goes first.
                $storeId = $record['location_id'] === null
                    ? null
                    : ($storeByLocationId[$record['location_id']] ?? null);

                $storeId ??= $storeKey === null ? null : ($storeByKey[$storeKey] ?? null);
                $positionId = $positionByLabel[$this->fold($label)] ?? null;

                if ($storeId !== null && $positionId !== null) {
                    $matchedBy = 'name';
                    $byName++;
                }
            }

            if ($storeId === null) {
                // A schedule with no store token in its name is company-wide by
                // design ("Bonus"), which is a different thing from a store
                // token that matched nothing here.
                $storeKey === null && $record['location_id'] === null
                    ? $companyWide++
                    : $noStore[] = $record['name'];
            }

            if ($positionId === null && $label !== '') {
                $noPosition[$this->fold($label)] = $label;
            }

            if ($storeId !== null && $positionId !== null) {
                // Every match for the pair, tagged with how it was made. A
                // job-coded row beating a bare one is the DESIGN — see
                // HumanitySchedule::scheduleFor() — so it must not be reported as
                // an ambiguity. Only a tie within the same tier is one.
                $pairs[$storeId.':'.$positionId][$matchedBy][] = $record['name'];
            }

            $rows[] = [
                'schedule_id' => $record['id'],
                'store_id' => $storeId === null ? null : (int) $storeId,
                'position_id' => $positionId === null ? null : (int) $positionId,
                'name' => $record['name'],
                // Stored, never rendered. It is a join key and an audit trail;
                // the board goes on showing position labels.
                'job_code' => $jobCode,
                'matched_by' => $matchedBy,
                'location_external_id' => $record['location_id'],
                'active' => ! $record['deleted'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // REPLACED, not merged, and inside one transaction so a failed insert
        // cannot leave the estate with no catalogue at all. Merging would keep
        // ids for positions Humanity has since retired, and the publisher would
        // go on naming them.
        DB::transaction(static function () use ($rows): void {
            HumanitySchedule::query()->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                HumanitySchedule::query()->insert($chunk);
            }
        });

        $usable = count($pairs);

        /**
         * A pair is ambiguous only when its BEST tier has more than one
         * candidate. Two job-coded schedules for one store and role is a real
         * problem nobody here can resolve; one job-coded and one bare is not,
         * because the lookup prefers the coded one and the vendor's own shifts
         * confirm that is the right choice.
         */
        foreach ($pairs as $matches) {
            $best = $matches['job_code'] ?? $matches['name'] ?? [];

            if (count($best) > 1) {
                $duplicates[] = implode(' / ', $best);
            }
        }

        $this->command?->info(
            "HumanitySeeder: {$usable} store/position schedule(s) mapped from ".count($rows)
            ." Humanity schedule(s) in {$source}"
            ." — {$byJobCode} by TCP job code, {$byName} by name"
            .($companyWide > 0 ? ", {$companyWide} company-wide" : '')
            .'.'
        );

        if ($unknownCodes !== []) {
            // Expected for the company-wide pay categories, which are not
            // positions. Anything else here is a code TCP has not got.
            $this->command?->warn(
                'HumanitySeeder: '.count($unknownCodes).' schedule(s) carry a job code TCP does not have ('
                .implode(', ', array_slice(array_values($unknownCodes), 0, 5)).'); matched by name instead.'
            );
        }

        if ($noStore !== []) {
            $this->command?->warn(
                'HumanitySeeder: '.count($noStore).' schedule(s) name a store that is not here ('
                .implode(', ', array_slice($noStore, 0, 5)).').'
            );
        }

        if ($noPosition !== []) {
            // The actionable half of the report: Humanity staffs something we
            // have no position for, so no shift will ever be published against
            // it. Either the position is missing here or it is obsolete there,
            // and only a human can say which.
            $this->command?->warn(
                'HumanitySeeder: no local position matches '.count($noPosition).' Humanity schedule name(s) ('
                .implode(', ', array_slice(array_values($noPosition), 0, 8)).').'
            );
        }

        if ($duplicates !== []) {
            // Two Humanity schedules for one store and position. The lookup
            // picks the lowest id deterministically, but which one is right is
            // not ours to decide.
            $this->command?->warn(
                'HumanitySeeder: '.count($duplicates).' store/position pair(s) have more than one EQUALLY '
                .'GOOD Humanity schedule ('.implode(', ', array_slice($duplicates, 0, 3)).'). The lowest id '
                .'wins, deterministically, but which is right is not ours to decide — retire the duplicate '
                .'in Humanity to be sure.'
            );
        }
    }

    /**
     * Schedules from the best source available, normalised to one shape.
     *
     * GET /positions first — it is the whole catalogue and it states each
     * schedule's location as an id. Failing that, the employees export, where
     * every record carries a `schedules` map of the same ids keyed to their
     * names; that covers only schedules somebody is actually assigned to, which
     * is said out loud rather than quietly accepted.
     *
     * @return array{0: array<int, array{id: string, name: string, location_id: string|null, deleted: bool}>|null, 1: string}
     */
    private function scheduleRecords(): array
    {
        $positions = $this->read(self::POSITIONS_FILE, warn: false);

        if ($positions !== null) {
            $records = [];

            foreach ($positions as $record) {
                $id = $this->string($record['id'] ?? null);
                $name = $this->string($record['name'] ?? null);

                if ($id === null || $name === null) {
                    continue;
                }

                $location = $record['location'] ?? null;

                $records[] = [
                    'id' => $id,
                    'name' => $name,
                    // The TCP job code, and the reason this export is preferred
                    // over the employees one: it is the join key. See the
                    // add_job_code migration for why it beats the name.
                    'job_code' => $this->string($record['job_code'] ?? null),
                    // {id, name}, per GET /positions. A bare scalar is accepted
                    // too: an export somebody flattened by hand is the likelier
                    // shape of the next file to land here.
                    'location_id' => $this->string(is_array($location) ? ($location['id'] ?? null) : $location),
                    'deleted' => ($record['deleted'] ?? false) === true,
                ];
            }

            return [$records, self::POSITIONS_FILE];
        }

        $employees = $this->read(self::EMPLOYEES_FILE);

        if ($employees === null) {
            return [null, self::EMPLOYEES_FILE];
        }

        $this->command?->warn(
            'HumanitySeeder: '.self::POSITIONS_FILE.' not found, so the schedule catalogue is being built from '
            .'the employees export. It will hold only schedules somebody is assigned to — a store still being '
            .'staffed up will be missing, and shifts for it cannot be published until GET /positions is exported.'
        );

        $seen = [];

        foreach ($employees as $employee) {
            $schedules = $employee['schedules'] ?? null;

            if (! is_array($schedules)) {
                continue;
            }

            foreach ($schedules as $id => $name) {
                $id = $this->string($id);
                $name = $this->string($name);

                if ($id === null || $name === null) {
                    continue;
                }

                // Keyed, not appended: 417 employees name the same 62 schedules
                // between them, and schedule_id is UNIQUE.
                $seen[$id] = [
                    'id' => $id,
                    'name' => $name,
                    // An employee's schedules map is {id: name} and nothing more,
                    // so there is no job code here and no location either — both
                    // have to be read out of the name. This is the cost of the
                    // fallback, and why GET /positions is preferred.
                    'job_code' => null,
                    'location_id' => null,
                    // An employee cannot be assigned to a deleted schedule, so
                    // everything reachable this way is live.
                    'deleted' => false,
                ];
            }
        }

        return [array_values($seen), self::EMPLOYEES_FILE];
    }

    /**
     * A schedule name split into the position it names and the store it belongs
     * to: "Crew Member - 3795-25" => ['Crew Member', '3795-25'].
     *
     * Anchored on the STORE TOKEN at the end rather than on the first dash. A
     * position called "Crew Member - Night" would otherwise be read as a store
     * called "Night", and the trailing digits are the only part of this
     * convention the vendor is consistent about.
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitScheduleName(string $name): array
    {
        if (preg_match('/^(.*?)\s*[-–]\s*(\d{3,6}-\d{1,6})\s*$/u', $name, $matches) === 1) {
            return [trim($matches[1]), $this->storeKey($matches[2])];
        }

        return [trim($name), null];
    }

    /**
     * A store number in the form a schedule name spells it.
     *
     * "03795-00025" and "3795-25" both become "3795-25", which is what lets one
     * function normalise both sides of the join. Null for anything that is not
     * franchise-store shaped — store 4821 is in the estate and is not.
     */
    private function storeKey(string $storeNumber): ?string
    {
        if (preg_match('/^(\d+)-(\d+)$/', trim($storeNumber), $matches) !== 1) {
            return null;
        }

        $franchise = ltrim($matches[1], '0');
        $store = ltrim($matches[2], '0');

        if ($franchise === '' || $store === '') {
            return null;
        }

        // Two digits, so 3795-5 and 3795-05 are the same store.
        return $franchise.'-'.str_pad($store, 2, '0', STR_PAD_LEFT);
    }

    /** Case and whitespace folded, so "Crew  Member" matches "Crew Member". */
    private function fold(string $value): string
    {
        return strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));
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
     * $warn false for a file that is genuinely optional: the schedule catalogue
     * prefers GET /positions and falls back to the employees export, and warning
     * about a missing preference before trying the fallback reads as a failure.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function read(string $relativePath, bool $warn = true): ?array
    {
        $path = storage_path('app/'.$relativePath);

        if (! is_file($path)) {
            $warn && $this->command?->warn(
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
