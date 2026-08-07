<?php

namespace App\Services\Scheduling;

use App\DataTransferObjects\WorkSegmentData;
use App\DataTransferObjects\WorkSegmentFilter;
use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSystem;
use App\Enums\MatchSource;
use App\Enums\SegmentOrigin;
use App\Models\Employee;
use App\Models\EmployeeStoreAssignment;
use App\Models\IntegrationIdentity;
use App\Models\WorkSegment;
use App\Support\BusinessDay;
use App\Support\Integrations\Tcp\TcpClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pull TCP's punches into work_segments.
 *
 * TCP owns punches, so the default direction of travel is inbound and this
 * service never pushes. What it is careful about is the small set of rows a
 * human has already touched — see shouldAcceptTimes(), which is the only
 * genuinely load-bearing decision in the file.
 *
 * THE FIELD MAPPING IS A GUESS. The source document's field tables are images
 * that could not be read, so every key name below is inferred and read
 * case-insensitively across the spellings vendors actually use. The safety net
 * is work_segments.tcp_payload: the whole raw record is stored on every row, so
 * a wrong guess costs one corrected method and a re-sync rather than data
 * nobody kept.
 */
class WorkSegmentSyncService
{
    /** @var array<string, Employee|null> external TCP id => employee, per run */
    private array $employees = [];

    /** @var array<string, int|null> external TCP job code => position id, per run */
    private array $positions = [];

    public function __construct(
        private readonly TcpClient $tcp,
        private readonly BusinessDay $businessDay,
        private readonly ReconciliationService $reconciliation,
    ) {}

    /**
     * One business date, optionally narrowed to one store.
     *
     * @param  string  $date  Y-m-d
     * @return array<string, mixed>
     */
    public function syncDate(string $date, ?int $storeId = null): array
    {
        $employeeIds = $storeId === null ? [] : $this->tcpEmployeeIdsForStore($storeId);

        if ($storeId !== null && $employeeIds === []) {
            // An empty employeeIds list is "no filter on employees", not "no
            // employees" — sending it would quietly widen a one-store sync into
            // every store's punches for the day.
            return $this->report(0, 0, 0, 0, 0, [[
                'reason' => 'store_has_no_tcp_employees',
                'store_id' => $storeId,
            ]]);
        }

        return $this->sync(
            new WorkSegmentFilter(
                employeeIds: $employeeIds,
                startDate: $date,
                endDate: $date,
            ),
            $storeId,
        );
    }

    /**
     * Everything TCP changed in the last N minutes, across every store.
     *
     * updatedOn, not the punch date: a punch entered today for last Tuesday is
     * exactly the correction a date-scoped sync would never see again.
     *
     * @return array<string, mixed>
     */
    public function syncIncremental(int $minutes): array
    {
        $minutes = max(1, $minutes);
        $now = CarbonImmutable::now('UTC');

        return $this->sync(
            new WorkSegmentFilter(
                updatedOnFrom: $now->subMinutes($minutes)->toIso8601String(),
                updatedOnTo: $now->toIso8601String(),
            ),
            null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(WorkSegmentFilter $filter, ?int $requestedStoreId): array
    {
        $records = $this->fetch($filter);

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $held = 0;
        $skipped = [];

        foreach ($records as $record) {
            $outcome = $this->apply($record, $requestedStoreId);

            match ($outcome['outcome']) {
                'created' => $created++,
                'updated' => $updated++,
                'unchanged' => $unchanged++,
                default => $skipped[] = $outcome,
            };

            if (($outcome['held'] ?? false) === true) {
                $held++;
            }
        }

        return $this->report(count($records), $created, $updated, $unchanged, $held, $skipped);
    }

    /**
     * Every record the filter matches, deduplicated by tcp_segment_id.
     *
     * chunked() is called HERE and each chunk handed to the client one at a
     * time, rather than handing over the whole filter: a store's employee list
     * routinely passes the vendor's 20-value cap, and a filter over the cap is
     * not rejected — the extra values are silently dropped and the response
     * looks perfectly normal. Nothing is fetched twice by doing it here: a
     * chunk is already inside the cap, so the client's own chunking yields it
     * unchanged.
     *
     * The dedupe is for the union of chunks and for repeated pages, not for the
     * cross product — those chunks are disjoint by construction.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetch(WorkSegmentFilter $filter): array
    {
        $byId = [];
        $unidentified = [];

        foreach ($filter->chunked() as $chunk) {
            foreach ($this->tcp->workSegments($chunk) as $record) {
                $id = $this->segmentId($this->lowered($record));

                if ($id === null) {
                    // Nothing to deduplicate on and nothing to upsert against.
                    // Kept so apply() can report it rather than losing it in a
                    // count that does not add up.
                    $unidentified[] = $record;

                    continue;
                }

                // Last read wins within one run: a later page is the fresher one.
                $byId[$id] = $record;
            }
        }

        return array_merge(array_values($byId), $unidentified);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function apply(array $record, ?int $requestedStoreId): array
    {
        $fields = $this->lowered($record);
        $tcpSegmentId = $this->segmentId($fields);

        if ($tcpSegmentId === null) {
            return ['outcome' => 'skipped', 'reason' => 'no_tcp_segment_id'];
        }

        $employee = $this->resolveEmployee($fields);

        if ($employee === null) {
            return [
                'outcome' => 'skipped',
                'reason' => 'unknown_employee',
                'tcp_segment_id' => $tcpSegmentId,
            ];
        }

        $storeId = $this->resolveStoreId($fields, $employee, $requestedStoreId);

        if ($storeId === null) {
            return [
                'outcome' => 'skipped',
                'reason' => 'no_store_for_employee',
                'tcp_segment_id' => $tcpSegmentId,
                'employee_id' => (int) $employee->id,
            ];
        }

        // withTrashed: tcp_segment_id is UNIQUE and a soft-deleted row still
        // holds it, so looking only at live rows would turn a re-sync into a
        // duplicate-key error.
        $existing = WorkSegment::query()
            ->withTrashed()
            ->where('tcp_segment_id', $tcpSegmentId)
            ->first();

        if ($existing !== null && $existing->trashed()) {
            // Somebody deleted this locally. Resurrecting it on the next sweep
            // would undo a deliberate act every ten minutes.
            return [
                'outcome' => 'skipped',
                'reason' => 'soft_deleted_locally',
                'tcp_segment_id' => $tcpSegmentId,
                'work_segment_id' => (int) $existing->id,
            ];
        }

        $data = $this->toWorkSegmentData($record, $fields, $employee, $storeId, $tcpSegmentId);

        if ($data === null) {
            return [
                'outcome' => 'skipped',
                'reason' => 'no_time_in',
                'tcp_segment_id' => $tcpSegmentId,
            ];
        }

        return $existing === null
            ? $this->createSegment($data)
            : $this->updateSegment($existing, $data);
    }

    /** @return array<string, mixed> */
    private function createSegment(WorkSegmentData $data): array
    {
        $segment = DB::transaction(fn (): WorkSegment => WorkSegment::query()->create(
            $data->toArray() + [
                'origin' => SegmentOrigin::TcpSync,
                'match_source' => MatchSource::Unmatched,
                'manager_approval' => false,
                'tcp_synced_at' => now(),
            ],
        ));

        $this->reconciliation->match($segment);

        return [
            'outcome' => 'created',
            'work_segment_id' => (int) $segment->id,
            'tcp_segment_id' => $data->tcpSegmentId,
        ];
    }

    /** @return array<string, mixed> */
    private function updateSegment(WorkSegment $segment, WorkSegmentData $data): array
    {
        $inboundUpdatedOn = $this->parseUtc($data->tcpUpdatedOn);
        $acceptTimes = $this->shouldAcceptTimes($segment, $inboundUpdatedOn);

        // Bookkeeping is refreshed either way. It is how anyone works out later
        // that TCP moved and we chose not to follow it: tcp_updated_on past
        // approved_at, with the raw record sitting in tcp_payload.
        $attributes = [
            'tcp_payload' => $data->tcpPayload,
            'tcp_updated_on' => $data->tcpUpdatedOn,
            'tcp_synced_at' => now(),
        ];

        if ($acceptTimes) {
            $attributes += [
                'store_id' => $data->storeId,
                'position_id' => $data->positionId ?? $segment->position_id,
                'business_date' => $data->businessDate,
                'time_in' => $data->timeIn,
                'time_out' => $data->timeOut,
                'break_minutes' => $data->breakMinutes,
                'hours' => $data->hours,
                'cost_code_name' => $data->costCodeName,
                'labor_code' => $data->laborCode,
            ];
        }

        // Deliberately absent from $attributes in every branch:
        //   origin              — a manual_create row that later gains a TCP id
        //                         still records how it came to exist.
        //   manager_approval    — approval is ours, TCP has no opinion on it.
        //   employee_approval   —   "
        //   shift_id            — reconciliation's, and manual matches are a
        //   match_source        —   human's answer.
        $segment->forceFill($attributes);

        $changed = $segment->isDirty([
            'store_id', 'position_id', 'business_date', 'time_in', 'time_out',
            'break_minutes', 'hours', 'cost_code_name', 'labor_code',
        ]);

        DB::transaction(static fn (): bool => $segment->save());

        // Run on every upsert, not only on a change: the shift a punch belongs
        // to can move under a segment that did not move at all.
        $this->reconciliation->match($segment);

        return [
            'outcome' => $changed ? 'updated' : 'unchanged',
            'held' => ! $acceptTimes,
            'work_segment_id' => (int) $segment->id,
            'tcp_segment_id' => $data->tcpSegmentId,
        ];
    }

    /**
     * PRECEDENCE — who wins when TCP and this database disagree.
     *
     * TCP owns punches, so an inbound sync normally wins. The exception is a
     * human who has already acted on the row, because that is the one thing TCP
     * cannot know about. Three rules, in order:
     *
     *   manager_approval = true — FROZEN. Somebody signed these hours off and
     *       payroll may already have them. Moving approved times under an
     *       approval is the worst outcome available here: the row still says
     *       "approved" and now says something different from what was approved.
     *       The bookkeeping columns are still refreshed, so a tcp_updated_on
     *       later than approved_at is a findable "TCP disagrees with what you
     *       signed".
     *
     *   times_corrected_at set — a manager fixed the times by hand. TCP wins
     *       back only if TCP'S OWN RECORD changed after that correction
     *       (tcp_updated_on > times_corrected_at), which is a fresh fact from
     *       the source of truth rather than the same stale record being re-read.
     *       This test is exactly what tcp_updated_on is for: without it, every
     *       ten-minute sweep would undo the correction again, and the manager
     *       would watch their fix evaporate twice an hour.
     *
     *   otherwise — TCP wins, unless the inbound record is provably not newer
     *       than the one already stored, in which case there is nothing to
     *       apply. "Provably" matters: if either side has no timestamp we
     *       cannot show the stored row is fresher, and TCP is the source of
     *       truth, so we take the inbound one.
     */
    private function shouldAcceptTimes(WorkSegment $segment, ?CarbonImmutable $inboundUpdatedOn): bool
    {
        if ($segment->manager_approval) {
            return false;
        }

        if ($segment->times_corrected_at !== null) {
            return $inboundUpdatedOn !== null
                && $inboundUpdatedOn->greaterThan($segment->times_corrected_at);
        }

        if ($inboundUpdatedOn !== null && $segment->tcp_updated_on !== null) {
            return $inboundUpdatedOn->greaterThan($segment->tcp_updated_on);
        }

        return true;
    }

    /**
     * One TCP record in our field names.
     *
     * @param  array<string, mixed>  $record  the raw record, kept whole
     * @param  array<string, mixed>  $fields  the same record, keys lowercased
     */
    private function toWorkSegmentData(
        array $record,
        array $fields,
        Employee $employee,
        int $storeId,
        string $tcpSegmentId,
    ): ?WorkSegmentData {
        $timeIn = $this->instant($fields, $storeId, ['timeIn', 'time_in', 'clockIn', 'clock_in', 'startTime', 'start_time', 'in']);

        if ($timeIn === null) {
            // time_in is NOT NULL and is what business_date is derived from. A
            // record without one is not an open punch, it is unmappable.
            return null;
        }

        $timeOut = $this->instant($fields, $storeId, ['timeOut', 'time_out', 'clockOut', 'clock_out', 'endTime', 'end_time', 'out']);

        // time_out <= time_in is rejected by a MySQL CHECK, and an inbound
        // record that says so is more likely a mapping error than a real punch.
        // Treat it as an open punch rather than losing the whole row.
        if ($timeOut !== null && $timeOut->lessThanOrEqualTo($timeIn)) {
            $timeOut = null;
        }

        return new WorkSegmentData(
            employeeId: (int) $employee->id,
            storeId: $storeId,
            businessDate: $this->businessDay->businessDate($storeId, $timeIn),
            timeIn: $timeIn->format('Y-m-d H:i:s'),
            timeOut: $timeOut?->format('Y-m-d H:i:s'),
            tcpSegmentId: $tcpSegmentId,
            positionId: $this->resolvePositionId($fields),
            breakMinutes: (int) ($this->pick($fields, ['breakMinutes', 'break_minutes', 'breakTime', 'break_time', 'break']) ?? 0),
            // TCP's own figure, never a subtraction of the two times. When the
            // two disagree, payroll needs TCP's.
            hours: $this->numeric($this->pick($fields, ['hours', 'totalHours', 'total_hours', 'hoursWorked', 'hours_worked'])),
            costCodeName: $this->string($this->pick($fields, ['costCodeName', 'cost_code_name', 'costCode', 'cost_code'])),
            laborCode: $this->string($this->pick($fields, ['laborCode', 'labor_code', 'laborCodeName', 'labor_code_name'])),
            notes: $this->string($this->pick($fields, ['notes', 'note', 'comment', 'comments'])),
            tcpUpdatedOn: $this->parseUtc($this->string($this->pick($fields, [
                'updatedOn', 'updated_on', 'lastModified', 'last_modified', 'modifiedOn', 'modified_on', 'updatedAt', 'updated_at',
            ])))?->format('Y-m-d H:i:s'),
            // The whole raw record, exactly as it arrived. The mapping above is
            // unconfirmed; this is what makes a wrong guess recoverable.
            tcpPayload: $record,
        );
    }

    /**
     * Where this punch happened.
     *
     * The record's own location is evidence; the employee's primary store is a
     * reasonable inference about a person; the store the run was scoped to is
     * neither — it only says which employees were asked about, not where any of
     * them worked — so it comes last.
     *
     * @param  array<string, mixed>  $fields
     */
    private function resolveStoreId(array $fields, Employee $employee, ?int $requestedStoreId): ?int
    {
        $external = $this->string($this->pick($fields, ['locationId', 'location_id', 'location', 'siteId', 'site_id']));

        if ($external !== null) {
            $entityId = IntegrationIdentity::query()
                ->forExternalId(IntegrationSystem::Tcp, IntegrationEntityType::Store, $external)
                ->value('entity_id');

            if ($entityId !== null) {
                return (int) $entityId;
            }
        }

        if ($employee->primary_store_id !== null) {
            return (int) $employee->primary_store_id;
        }

        return $requestedStoreId;
    }

    /**
     * TCP's job code as one of our positions, or null when unmapped.
     *
     * Deliberately no fallback to the employee's primary position: filing hours
     * under a position nobody worked would corrupt the labour cost report more
     * quietly than leaving the column null.
     *
     * @param  array<string, mixed>  $fields
     */
    private function resolvePositionId(array $fields): ?int
    {
        $external = $this->string($this->pick($fields, ['jobCodeId', 'job_code_id', 'jobCode', 'job_code', 'positionId', 'position_id']));

        if ($external === null) {
            return null;
        }

        if (array_key_exists($external, $this->positions)) {
            return $this->positions[$external];
        }

        $entityId = IntegrationIdentity::query()
            ->forExternalId(IntegrationSystem::Tcp, IntegrationEntityType::Position, $external)
            ->value('entity_id');

        return $this->positions[$external] = $entityId === null ? null : (int) $entityId;
    }

    /**
     * The employee a TCP record belongs to.
     *
     * employees.tcp_employee_id first: it is the projected mapping, it is
     * UNIQUE, and it is what hiring already knows. integration_identities is
     * the fallback for anyone scheduling provisioned itself, which is
     * scheduling-owned and survives a replay.
     *
     * @param  array<string, mixed>  $fields
     */
    private function resolveEmployee(array $fields): ?Employee
    {
        $external = $this->string($this->pick($fields, [
            'employeeId', 'employee_id', 'employeeID', 'employeeNumber', 'employee_number', 'employee',
        ]));

        if ($external === null) {
            return null;
        }

        if (array_key_exists($external, $this->employees)) {
            return $this->employees[$external];
        }

        $employee = Employee::query()->where('tcp_employee_id', $external)->first();

        if ($employee === null) {
            $entityId = IntegrationIdentity::query()
                ->forExternalId(IntegrationSystem::Tcp, IntegrationEntityType::Employee, $external)
                ->value('entity_id');

            $employee = $entityId === null ? null : Employee::query()->find((int) $entityId);
        }

        return $this->employees[$external] = $employee;
    }

    /**
     * The TCP ids of everyone who works at this store: primary store plus any
     * explicit assignment, because a person can be scheduled somewhere that is
     * not their primary store.
     *
     * GET /worksegments has no store filter, so scoping a date sync to one
     * store means naming its people — which is precisely the list that passes
     * the vendor's 20-value cap and needs chunking.
     *
     * @return array<int, string>
     */
    private function tcpEmployeeIdsForStore(int $storeId): array
    {
        $assigned = EmployeeStoreAssignment::query()
            ->where('store_id', $storeId)
            ->pluck('employee_id');

        return Employee::query()
            ->whereNotNull('tcp_employee_id')
            ->where(fn (Builder $query): Builder => $query
                ->where('primary_store_id', $storeId)
                ->orWhereIn('id', $assigned))
            ->pluck('tcp_employee_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $fields */
    private function segmentId(array $fields): ?string
    {
        return $this->string($this->pick($fields, [
            'id', 'segmentId', 'segment_id', 'workSegmentId', 'work_segment_id',
        ]));
    }

    /**
     * A timestamp from the record as a UTC instant.
     *
     * An explicit offset or a trailing Z is an instant and is taken at face
     * value. Anything else is a bare wall clock, and a punch clock reports the
     * time on the wall AT THE STORE — reading it as UTC would move every punch
     * by the store's offset and drop late-evening ones onto the wrong
     * business_date. GUESS: the first live payload should settle this.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $keys
     */
    private function instant(array $fields, int $storeId, array $keys): ?CarbonImmutable
    {
        $raw = $this->string($this->pick($fields, $keys));

        if ($raw === null) {
            return null;
        }

        try {
            return $this->hasOffset($raw)
                ? CarbonImmutable::parse($raw)->utc()
                : $this->businessDay->toUtc($storeId, $raw);
        } catch (Throwable) {
            // An unparseable time must not take the whole sweep down; the raw
            // value is still on the row in tcp_payload.
            return null;
        }
    }

    /** A vendor system timestamp. Bare values are read as UTC — it is TCP's clock, not a store's. */
    private function parseUtc(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return $this->hasOffset($value)
                ? CarbonImmutable::parse($value)->utc()
                : CarbonImmutable::parse($value, 'UTC');
        } catch (Throwable) {
            return null;
        }
    }

    private function hasOffset(string $value): bool
    {
        return preg_match('/(Z|[+-]\d{2}:?\d{2})$/', trim($value)) === 1;
    }

    /**
     * The record with its keys lowercased, so the spelling guesses only have to
     * be right about the word and not about the capitalisation.
     *
     * @param  array<mixed>  $record
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

    /**
     * The first of these keys the record actually carries.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $keys
     */
    private function pick(array $fields, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = $fields[strtolower($key)] ?? null;

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $skipped
     * @return array{
     *     fetched: int,
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     held: int,
     *     skipped: array<int, array<string, mixed>>
     * }
     */
    private function report(int $fetched, int $created, int $updated, int $unchanged, int $held, array $skipped): array
    {
        return [
            'fetched' => $fetched,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            // Rows where an inbound change was deliberately not applied because
            // a human had already touched them.
            'held' => $held,
            'skipped' => $skipped,
        ];
    }
}
