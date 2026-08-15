<?php

namespace Database\Seeders;

use App\DataTransferObjects\EmployeeFilter;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\Gender;
use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSyncState;
use App\Enums\IntegrationSystem;
use App\Models\Employee;
use App\Models\IntegrationIdentity;
use App\Models\Store;
use App\Support\Integrations\Tcp\TcpClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Every store's roster, read from TCP, so the board is usable before hiring is.
 *
 * The same standing-in-for-events job StoreSeeder does, and for the same reason:
 * `employees` is a PROJECTION of hiring.v1.employee.created|updated, and until
 * hiring is publishing there is nothing in it. StoreSeeder fills the stores half
 * so the picker has 39 entries; without this, picking any of them but 4821 shows
 * an empty roster, because the only employees in the table are DemoSeeder's four.
 *
 * WHY NOT TcpEmployeeReader. That class reads the same endpoint and deliberately
 * creates nothing — a TCP employee it cannot already account for is REPORTED,
 * never written. That is the right rule for a request-path convenience running
 * on every store change. This is a seeder: an explicit, out-of-band "populate
 * the projection" step somebody runs on purpose, which is exactly the licence
 * StoreSeeder already takes. Between the two, the reader stays honest and the
 * board gets rows.
 *
 * WHAT REPLAY DOES TO THESE ROWS, and why it is safe:
 *
 *   hiring_updated_at is left NULL, deliberately. EmployeeProjector's stale-event
 *   guard only skips when the EXISTING row has one, so a real hiring event always
 *   wins over anything seeded here. These rows yield; they never fight.
 *
 *   The primary key is derived from TCP's employee id plus LOCAL_ID_BASE, so it
 *   is deterministic (re-seeding lands on the same row, never a duplicate) and
 *   sits far above the sequential ids hiring assigns. A real employee.created
 *   cannot collide with one of these.
 *
 * The tcp_employee_id UNIQUE constraint is the one thing that could still bite:
 * when hiring finally sends a person we seeded here, its row carries the same
 * TCP id under a different primary key, and the projector would throw. See
 * prune() — running it before a first real replay clears the ground.
 *
 * IT NEVER DELETES EMPLOYEES. Idempotent and additive, like StoreSeeder, so a
 * re-run after a DemoSeeder wipe restores rosters without touching demo data.
 */
class EmployeeSeeder extends Seeder
{
    /**
     * Added to TCP's employee id to make the local primary key.
     *
     * Nine digits clear of anything hiring will assign, and reversible by hand:
     * local 906415816 is TCP 6415816. Chosen so a row's origin is obvious from
     * the id alone when one turns up in a log.
     */
    private const LOCAL_ID_BASE = 900_000_000;

    /**
     * TCP has no employment-type field, and the column is NOT NULL.
     *
     * W2 because that is what an hourly store employee on a punch clock is; the
     * value is only read by reporting, never by the scheduling rules. The moment
     * hiring sends the real one it overwrites this.
     */
    private const ASSUMED_EMPLOYMENT_TYPE = EmploymentType::W2;

    public function run(): void
    {
        $tcp = app(TcpClient::class);

        // store_number => store id. The filter takes numbers and each returned
        // record carries its own `location` holding the same string, so the
        // response can be grouped back onto stores without a second call.
        $storeIds = Store::query()
            ->whereNotNull('store_number')
            ->pluck('id', 'store_number');

        if ($storeIds->isEmpty()) {
            $this->command?->warn('EmployeeSeeder: no stores with a store_number. Run StoreSeeder first.');

            return;
        }

        // ONE filter, not one per store. EmployeeFilter::chunked() splits on the
        // 20-value cap internally, so 39 stores costs two round trips rather
        // than thirty-nine.
        $records = $tcp->employees(new EmployeeFilter(locations: $storeIds->keys()->all()));

        if ($records === []) {
            $this->command?->warn('EmployeeSeeder: TCP returned no employees for these locations.');

            return;
        }

        // Grouped by TCP employee id, because somebody covering two stores comes
        // back once per location and must end up as ONE employee holding two
        // store assignments, not two rows fighting over the UNIQUE tcp id.
        $byEmployee = [];

        foreach ($records as $record) {
            $fields = $this->lowered($record);
            $externalId = $this->string($fields['employeeid'] ?? null);

            if ($externalId === null) {
                continue;
            }

            $byEmployee[$externalId][] = $fields;
        }

        $seeded = 0;
        $left = 0;
        $stores = [];

        foreach ($byEmployee as $externalId => $rows) {
            $storeIdsForEmployee = collect($rows)
                ->map(fn (array $row): mixed => $storeIds[$this->string($row['location'] ?? null)] ?? null)
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($storeIdsForEmployee === []) {
                // At a location we have no store row for. Skipped rather than
                // parked on an arbitrary store — a person on the wrong board is
                // worse than a person on none.
                continue;
            }

            $outcome = $this->upsert((string) $externalId, $rows[0], $storeIdsForEmployee);

            $outcome === 'hiring_owned' ? $left++ : $seeded++;
            $stores = array_unique([...$stores, ...$storeIdsForEmployee]);
        }

        $this->command?->info(
            "EmployeeSeeder: {$seeded} employees across ".count($stores).' stores, from '
            .count($records).' TCP records.'
            .($left > 0 ? " {$left} left alone — hiring already owns them." : '')
        );
    }

    /**
     * The local employee a TCP id already belongs to, or null.
     *
     * Owned table first, projected column second — the SAME precedence
     * TcpEmployeeReader::resolveEmployee() and TcpEmployeeWriter::resolve() use,
     * so all three agree about who a TCP id points at.
     *
     * Not an optimisation. employees.tcp_employee_id is UNIQUE, so a person who
     * reached the table by any other route already holds their TCP id under a
     * primary key that is not the one derived here. Creating the derived row
     * instead of adopting theirs violates that constraint and aborts the seed —
     * which is exactly what happened before this existed.
     */
    private function resolveExisting(string $externalId): ?Employee
    {
        $entityId = IntegrationIdentity::query()
            ->forExternalId(IntegrationSystem::Tcp, IntegrationEntityType::Employee, $externalId)
            ->value('entity_id');

        if ($entityId !== null) {
            $employee = Employee::query()->find((int) $entityId);

            if ($employee !== null) {
                return $employee;
            }
        }

        return Employee::query()->where('tcp_employee_id', $externalId)->first();
    }

    /**
     * One employee and everything hanging off them, mirroring the fields
     * EmployeeProjector::upsertEmployee() writes from a hiring payload.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<int, int>  $storeIdsForEmployee
     * @return string 'seeded' | 'hiring_owned'
     */
    private function upsert(string $externalId, array $fields, array $storeIdsForEmployee): string
    {
        $existing = $this->resolveExisting($externalId);
        $recordId = $this->string($fields['employeerecordid'] ?? null);

        // HIRING'S ROW IS NOT OURS TO EDIT. A non-null hiring_updated_at means
        // this person arrived as a real hiring.v1.employee event, and every
        // field on that row is the projection of something upstream said. The
        // useful half of the work is still done: the TCP id mapping is written,
        // so the roster pull reports them linked instead of unmatched.
        if ($existing !== null && $existing->hiring_updated_at !== null) {
            $this->mapIdentity((int) $existing->id, $externalId, $recordId);

            return 'hiring_owned';
        }

        // An adopted row keeps its own primary key. Only a person nothing else
        // has ever written gets the derived one.
        $localId = (int) ($existing?->id ?? self::LOCAL_ID_BASE + (int) $externalId);
        $primaryStoreId = $storeIdsForEmployee[0];

        [$first, $last] = $this->names($fields);

        $terminatedOn = $this->date($fields['terminationdate'] ?? null);

        // hireDate and seniorityDate are frequently blank on these records, so
        // the created timestamp is the last resort. It matters beyond looking
        // tidy: LaborCostEstimator::rateOn() finds the pay row in effect ON the
        // board's date, and a rate effective later than the date being viewed
        // reads as "no rate".
        $since = $this->date($fields['hiredate'] ?? null)
            ?? $this->date($fields['senioritydate'] ?? null)
            ?? $this->date($fields['createdondatetime'] ?? null)
            ?? now()->subYear()->toDateString();

        DB::transaction(function () use (
            $localId, $externalId, $recordId, $fields, $first, $last, $primaryStoreId,
            $storeIdsForEmployee, $terminatedOn, $since
        ): void {
            Employee::query()->updateOrCreate(
                ['id' => $localId],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'birth_date' => $this->date($fields['birthdate'] ?? null),

                    // TCP's "unspecified" is not one of ours, and tryFrom giving
                    // null for it is the correct answer rather than a miss.
                    'gender' => Gender::tryFrom(mb_strtolower((string) ($fields['gender'] ?? ''))),
                    'employment_type' => self::ASSUMED_EMPLOYMENT_TYPE,

                    'primary_store_id' => $primaryStoreId,

                    // TCP's `department` / `roleId` are its own vocabulary and do
                    // not line up with our positions table. Left null rather than
                    // guessed — an invented position is a scheduling constraint
                    // nobody agreed to.
                    'primary_position_id' => null,

                    // Columns are varchar(40) and varchar(255); an over-long
                    // value here would abort the row.
                    'primary_phone' => $this->clip($this->phone($fields), 40),
                    'primary_email' => $this->clip($this->string($fields['email'] ?? null), 255),

                    'current_status' => $terminatedOn !== null
                        ? EmployeeStatus::Terminated
                        : EmployeeStatus::Hired,
                    'current_status_effective_date' => $terminatedOn ?? $since,

                    'tcp_employee_id' => $externalId,
                    'tcp_employee_record_id' => $this->clip($recordId, 64),

                    // NULL ON PURPOSE. See the class docblock: this is what lets
                    // a real hiring event overwrite the row instead of being
                    // discarded as stale.
                    'hiring_updated_at' => null,
                ]
            );

            // Replace, not upsert, matching EmployeeProjector: deleting first is
            // the only way a store this person no longer covers disappears.
            $employee = Employee::query()->findOrFail($localId);

            $employee->storeAssignments()->delete();

            foreach ($storeIdsForEmployee as $storeId) {
                $employee->storeAssignments()->create([
                    'store_id' => $storeId,
                    'effective_date' => $since,
                ]);
            }

            $this->pay($employee, $fields, $since);
            $this->mapIdentity($localId, $externalId, $recordId);
        });

        return 'seeded';
    }

    /**
     * Remove every row this seeder created, and nothing else.
     *
     * The one thing replay cannot resolve on its own: hiring's version of a
     * person we seeded arrives under a different primary key carrying the same
     * tcp_employee_id, and that column is UNIQUE — so the projector throws,
     * burns its five attempts and PARKS the event. Clearing the seeded rows
     * before the first real hiring replay is what prevents that.
     *
     * Safe to run at any time: the id range is this seeder's alone, so
     * DemoSeeder's people and anything hiring has already projected are
     * untouched. Shifts built against a seeded employee go with them — the FK
     * is what decides that, not this method.
     */
    public static function prune(): int
    {
        return DB::transaction(function (): int {
            $ids = Employee::query()
                ->where('id', '>=', self::LOCAL_ID_BASE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            IntegrationIdentity::query()
                ->where('entity_type', IntegrationEntityType::Employee)
                ->whereIn('entity_id', $ids)
                ->delete();

            return Employee::query()->whereIn('id', $ids)->delete();
        });
    }

    /**
     * defaultPayRate as the base rate, and nothing as performance pay.
     *
     * TCP carries one number, so splitting it would be inventing the division.
     * Zero performance pay is the honest reading of "we were told one rate".
     *
     * @param  array<string, mixed>  $fields
     */
    private function pay(Employee $employee, array $fields, string $since): void
    {
        $rate = $fields['defaultpayrate'] ?? null;

        if (! is_numeric($rate) || (float) $rate <= 0) {
            return;
        }

        $employee->payHistories()->delete();

        $employee->payHistories()->create([
            'base_pay' => (float) $rate,
            'performance_pay' => 0,
            'effective_date' => $since,
        ]);
    }

    /**
     * The scheduling-owned mapping, written Synced because these ids came back
     * from TCP itself — the same reasoning StoreSeeder gives for locations.
     *
     * This is what turns TcpEmployeeReader's "20 unmatched" into "20 already
     * linked" on the next board load.
     */
    private function mapIdentity(int $localId, string $externalId, ?string $externalRecordId): void
    {
        IntegrationIdentity::query()->updateOrCreate(
            [
                'entity_type' => IntegrationEntityType::Employee,
                'entity_id' => $localId,
                'system' => IntegrationSystem::Tcp,
            ],
            [
                'external_id' => $externalId,
                'external_record_id' => $externalRecordId,
                'sync_state' => IntegrationSyncState::Synced,
                'synced_at' => now(),
                'last_error' => null,
                'attempts' => 0,
            ],
        );
    }

    /**
     * firstName/lastName when TCP sends them, else the display name split once
     * on the first space. A person with no name at all still needs a row, so the
     * last resort is the TCP id rather than a skip.
     *
     * @param  array<string, mixed>  $fields
     * @return array{0: string, 1: string}
     */
    private function names(array $fields): array
    {
        $first = $this->string($fields['firstname'] ?? null);
        $last = $this->string($fields['lastname'] ?? null);

        if ($first !== null || $last !== null) {
            return [$this->clip($first ?? '', 100) ?? '', $this->clip($last ?? '', 100) ?? ''];
        }

        $whole = $this->string($fields['employeename'] ?? null);

        if ($whole === null) {
            return ['TCP', (string) ($fields['employeeid'] ?? 'unknown')];
        }

        $parts = explode(' ', $whole, 2);

        return [
            $this->clip($parts[0], 100) ?? '',
            $this->clip($parts[1] ?? '', 100) ?? '',
        ];
    }

    /**
     * Cell first: this column exists to tell somebody their shift moved, and the
     * office extension cannot do that.
     *
     * @param  array<string, mixed>  $fields
     */
    private function phone(array $fields): ?string
    {
        $phone = $fields['phone'] ?? null;

        if (! is_array($phone)) {
            return $this->string($phone);
        }

        foreach (['cellphone', 'homePhone', 'officePhone'] as $key) {
            $value = $this->string($phone[$key] ?? $phone[strtolower($key)] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** TCP sends "" for an unset date, and "2026-07-30T15:03:00" for a set one. */
    private function date(mixed $value): ?string
    {
        $string = $this->string($value);

        if ($string === null) {
            return null;
        }

        try {
            return now()->parse($string)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function lowered(array $record): array
    {
        $fields = [];

        foreach ($record as $key => $value) {
            if (is_string($key)) {
                $fields[strtolower($key)] = $value;
            }
        }

        return $fields;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function clip(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
