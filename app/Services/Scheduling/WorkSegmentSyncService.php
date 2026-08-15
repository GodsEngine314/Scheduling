<?php

namespace App\Services\Scheduling;

use App\DataTransferObjects\EmployeeFilter;
use App\DataTransferObjects\WorkSegmentData;
use App\DataTransferObjects\WorkSegmentFilter;
use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSystem;
use App\Enums\MatchSource;
use App\Enums\SegmentOrigin;
use App\Enums\TcpSyncState;
use App\Models\Employee;
use App\Models\EmployeeStoreAssignment;
use App\Models\IntegrationIdentity;
use App\Models\Store;
use App\Models\TcpJobCodeRole;
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
    /** timesDecision(): TCP's version wins. */
    private const TIMES_ACCEPT = 'accept';

    /** A local claim on the row beat it. This is the one worth reporting. */
    private const TIMES_HELD = 'held';

    /** Nothing to apply — the inbound record is no newer than the stored one. */
    private const TIMES_STALE = 'stale';

    /** @var array<string, Employee|null> external TCP id => employee, per run */
    private array $employees = [];

    /** @var array<string, int|null> external TCP job code => position id, per run */
    private array $positions = [];

    /** @var array<string, int|null> store_number => store id, per run */
    private array $storesByNumber = [];

    /** @var array<int, array<int, string>> store id => TCP's roster for it, per run */
    private array $rosters = [];

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
        return $this->syncRange($date, $date, $storeId);
    }

    /**
     * A span of business dates, optionally narrowed to one store.
     *
     * The week view pulls seven days, and it pulls them in ONE call rather than
     * seven: the expensive part of a scoped sync is the employee-id filter,
     * which is the same list for every day of the week, and TCP's rate limit is
     * counted in requests rather than in days.
     *
     * @param  string  $from  Y-m-d
     * @param  string  $to  Y-m-d, inclusive
     * @return array<string, mixed>
     */
    public function syncRange(string $from, string $to, ?int $storeId = null): array
    {
        if ($storeId === null) {
            return $this->sync(
                new WorkSegmentFilter(startDate: $from, endDate: $to),
                null,
            );
        }

        // NAMING THE PEOPLE IS THE ONLY WAY. GET /worksegments has no location
        // filter — verified against the live API, where `locations`,
        // `locationIds` and a parameter invented on the spot all returned the
        // identical 615 records. An earlier version of this method filtered by
        // location and would have silently synced the whole estate into one
        // store.
        //
        // The cost is the one the employee list always had: it asks about the
        // people we think work here, so a cover shift from another store is
        // missed. That is a real gap, and it is not one this endpoint lets us
        // close.
        $employeeIds = $this->tcpEmployeeIdsForStore($storeId);

        if ($employeeIds === []) {
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
                startDate: $from,
                endDate: $to,
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
            ? $this->createSegment($data, $fields)
            : $this->updateSegment($existing, $data, $fields);
    }

    /** @return array<string, mixed> */
    private function createSegment(WorkSegmentData $data, array $fields = []): array
    {
        // ON CREATE ONLY. TCP's approvals[] carries ManagerApproval and
        // EmployeeApproval, so a punch that arrived already signed off there
        // arrives signed off here rather than reappearing on somebody's list.
        //
        // updateSegment() still refuses to touch either column, and that has NOT
        // changed: once this row exists, approval is ours. A later sweep must
        // never un-approve hours a manager has put their name to — which is the
        // whole reason those two are on its deliberate-exclusion list.
        $approvals = $this->approvals($fields);

        $segment = DB::transaction(fn (): WorkSegment => WorkSegment::query()->create(
            $data->toArray() + [
                'origin' => SegmentOrigin::TcpSync,
                'match_source' => MatchSource::Unmatched,
                'manager_approval' => $approvals['manager'],
                'employee_approval' => $approvals['employee'],
                'approved_at' => $approvals['manager'] ? ($approvals['processed_on'] ?? now()) : null,
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

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function updateSegment(WorkSegment $segment, WorkSegmentData $data, array $fields = []): array
    {
        $inboundUpdatedOn = $this->parseUtc($data->tcpUpdatedOn);
        $decision = $this->timesDecision($segment, $inboundUpdatedOn);
        $acceptTimes = $decision === self::TIMES_ACCEPT;

        // Bookkeeping is refreshed either way. It is how anyone works out later
        // that TCP moved and we chose not to follow it: tcp_updated_on past
        // approved_at, with the raw record sitting in tcp_payload.
        $attributes = [
            'tcp_payload' => $data->tcpPayload,
            'tcp_updated_on' => $data->tcpUpdatedOn,
            'tcp_synced_at' => now(),
        ] + $this->approvalFromTcp($segment, $fields);

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
            // HELD means we REFUSED an inbound change, not that there was none
            // to apply. Reporting the stale case as held made every re-pull of
            // an unchanged day announce a conflict with every row in it.
            'held' => $decision === self::TIMES_HELD,
            'work_segment_id' => (int) $segment->id,
            'tcp_segment_id' => $data->tcpSegmentId,
        ];
    }

    /**
     * TCP'S APPROVAL STATE, MIRRORED ONTO OURS.
     *
     * TCP owns approval. A punch approved there arrives approved here, a punch
     * approved here is pushed there, and a re-sync brings back whatever TCP now
     * says — which is the whole point: the two must not drift, because payroll
     * pays from TCP's answer.
     *
     * This method exists because the sync used to skip these two columns
     * entirely, on the reasoning that "approval is ours, TCP has no opinion on
     * it". Under this ownership that is exactly backwards, and the failure was
     * silent and permanent: a punch approved IN TCP after we first imported it
     * sat in this console reading "requires approval" forever, because nothing
     * ever revisited the flag.
     *
     * THE ONE THING IT WILL NOT DO is overwrite a local approval that has not
     * reached TCP yet. tcp_sync_state = pending means a PushWorkSegmentToTcp job
     * is still in flight with our answer; reading TCP's older "not approved"
     * back over it would undo the manager's click a few seconds after they made
     * it, and the push would then send a value we no longer hold.
     *
     * approved_by_user_id is left alone when TCP is the one approving: TCP's
     * approverId is a TCP user, and that column is a foreign key into our users
     * projection. It is cleared only when the approval itself goes away.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function approvalFromTcp(WorkSegment $segment, array $fields): array
    {
        if ($fields === [] || $segment->tcp_sync_state === TcpSyncState::Pending) {
            return [];
        }

        $approvals = $this->approvals($fields);

        $attributes = ['employee_approval' => $approvals['employee']];

        if ($approvals['manager'] === (bool) $segment->manager_approval) {
            // Agreed already. Leave approved_at and approved_by_user_id alone so
            // a local approval keeps its attribution.
            return $attributes;
        }

        if ($approvals['manager']) {
            return $attributes + [
                'manager_approval' => true,
                'approved_at' => $approvals['processed_on'] ?? now(),
            ];
        }

        // TCP no longer considers these hours signed off, so neither do we.
        return $attributes + [
            'manager_approval' => false,
            'approved_at' => null,
            'approved_by_user_id' => null,
        ];
    }

    /**
     * PRECEDENCE — who wins when TCP and this database disagree.
     *
     * TCP owns punches, so an inbound sync normally wins. The exception is a
     * human on THIS side whose change TCP has not seen yet, because that is the
     * one thing TCP cannot know about. Three rules, in order:
     *
     *   tcp_sync_state = pending — FROZEN. A local edit or approval is still in
     *       flight to TCP. Reading TCP's pre-change version back over it would
     *       undo the change that is at this moment being sent, and the push
     *       would then carry a value we no longer hold.
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
     *
     * AN APPROVED ROW IS NO LONGER FROZEN ON THAT GROUND ALONE, and that is a
     * deliberate consequence of TCP owning approval. It used to be, on the
     * reasoning that payroll may already hold the approved figure — but under
     * this ownership the approved figure IS TCP's, so refusing TCP's times while
     * keeping TCP's approval flag produced a row that agreed with nothing. A
     * local approval is still protected, by the pending rule above, until the
     * push lands.
     *
     * THE THIRD OUTCOME IS NOT A REFUSAL. An inbound record no newer than the
     * stored one has nothing to apply, which is the ordinary state of every row
     * on a day nobody has touched since the last sweep. Calling that "held"
     * — as this did while it returned a bare bool — made a re-pull of a quiet
     * week report a conflict against every row in it, and the flash message
     * says a conflict means TCP disagreed with a human. Three outcomes, so the
     * count means what it claims.
     *
     * @return self::TIMES_*
     */
    private function timesDecision(WorkSegment $segment, ?CarbonImmutable $inboundUpdatedOn): string
    {
        if ($segment->tcp_sync_state === TcpSyncState::Pending) {
            return self::TIMES_HELD;
        }

        if ($segment->times_corrected_at !== null) {
            return $inboundUpdatedOn !== null
                && $inboundUpdatedOn->greaterThan($segment->times_corrected_at)
                    ? self::TIMES_ACCEPT
                    : self::TIMES_HELD;
        }

        if ($inboundUpdatedOn !== null && $segment->tcp_updated_on !== null) {
            return $inboundUpdatedOn->greaterThan($segment->tcp_updated_on)
                ? self::TIMES_ACCEPT
                : self::TIMES_STALE;
        }

        return self::TIMES_ACCEPT;
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

        $breakMinutes = $this->breakMinutes($this->pick($fields, ['breakLength', 'break_length']));

        return new WorkSegmentData(
            employeeId: (int) $employee->id,
            storeId: $storeId,
            businessDate: $this->businessDay->businessDate($storeId, $timeIn),
            timeIn: $timeIn->format('Y-m-d H:i:s'),
            timeOut: $timeOut?->format('Y-m-d H:i:s'),
            tcpSegmentId: $tcpSegmentId,
            positionId: $this->resolvePositionId($fields),
            breakMinutes: $breakMinutes,
            // DERIVED, and it has to be. The response schema carries no hours
            // field of any spelling — the old comment here claimed this was
            // "TCP's own figure, never a subtraction of the two times", which
            // was true of the guess and is not true of the real payload. An
            // open punch stays null; a closed one is the block less the break.
            hours: $this->hoursBetween($timeIn, $timeOut, $breakMinutes),
            costCodeName: $this->string($this->pick($fields, ['costCode', 'cost_code'])),
            // laborCodes is a LIST. work_segments.labor_code is one string, so
            // a segment carrying several is joined rather than silently
            // truncated to the first — losing one would understate the split.
            laborCode: $this->joined($this->pick($fields, ['laborCodes', 'labor_codes'])),
            // shiftNotes is a LIST too, one note per line.
            notes: $this->joined($this->pick($fields, ['shiftNotes', 'shift_notes']), "\n"),
            tcpUpdatedOn: $this->parseUtc($this->string($this->pick($fields, [
                'updatedOnDateTime', 'updated_on_date_time',
            ])))?->format('Y-m-d H:i:s'),
            // The whole raw record, exactly as it arrived. Several fields have
            // no column yet — approvals[], tracked1-3, punchIn/OutInformation,
            // geoLocations[], scheduleOrg, missedIn/OutPunch, actualTimeIn/Out,
            // customFields[] — and this is where they stay readable until they
            // earn one.
            tcpPayload: $record,
        );
    }

    /**
     * TCP's breakLength as whole minutes.
     *
     * GUESS: the field is typed as a string in the response schema with no
     * example, so both a plain count of minutes ("30") and a clock duration
     * ("00:30" / "0:30:00") are accepted. Anything else is 0 rather than a
     * fatal — the raw value survives on tcp_payload either way.
     */
    private function breakMinutes(mixed $value): int
    {
        $raw = $this->string($value);

        if ($raw === null) {
            return 0;
        }

        if (str_contains($raw, ':')) {
            $parts = array_map('intval', explode(':', $raw));

            // h:m or h:m:s — seconds are dropped, not rounded. A break is
            // recorded to the minute everywhere else in this schema.
            return ($parts[0] * 60) + ($parts[1] ?? 0);
        }

        return is_numeric($raw) ? (int) round((float) $raw) : 0;
    }

    /**
     * A TCP list field as one string, or null when there is nothing in it.
     *
     * laborCodes and shiftNotes both arrive as arrays. Each maps onto a single
     * column here, so the whole list is kept rather than its first element.
     */
    private function joined(mixed $value, string $glue = ', '): ?string
    {
        if (! is_array($value)) {
            return $this->string($value);
        }

        $parts = array_values(array_filter(array_map(
            fn (mixed $item): ?string => $this->string($item),
            $value,
        )));

        return $parts === [] ? null : implode($glue, $parts);
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
    /**
     * The store a punch's own clock reports, from punchInInformation.
     *
     * The value is a terminal name — "03795-00042-0*" — whose first eleven
     * characters are the store number. Matched against stores.store_number,
     * which auth already gives us, so no id mapping is involved.
     *
     * Memoised per run: a day's punches at one store all carry the same string.
     *
     * @param  array<string, mixed>  $fields
     */
    private function storeIdFromPunchLocation(array $fields): ?int
    {
        foreach (['punchInInformation', 'punchOutInformation'] as $key) {
            $info = $fields[strtolower($key)] ?? null;

            if (! is_array($info)) {
                continue;
            }

            $location = $this->string($info['punchLocation'] ?? $info['punchlocation'] ?? null);

            if ($location === null || preg_match('/^(\d{4,6}-\d{4,6})/', $location, $m) !== 1) {
                continue;
            }

            $number = $m[1];

            if (array_key_exists($number, $this->storesByNumber)) {
                return $this->storesByNumber[$number];
            }

            $id = Store::query()->where('store_number', $number)->value('id');

            return $this->storesByNumber[$number] = $id === null ? null : (int) $id;
        }

        return null;
    }

    /**
     * TCP's approvals[] reduced to the two booleans this schema keeps.
     *
     * CONFIRMED from live records. The list carries one entry per approval
     * type, each with its own flag:
     *
     *   {"type":"ManagerApproval","approved":true,"approverId":"SMHARBOR","processedOn":"..."}
     *   {"type":"EmployeeApproval","approved":false}
     *   {"type":"OtherApproval","approved":false}
     *
     * OtherApproval is deliberately dropped — there is no column for it and
     * folding it into either of the other two would misreport who signed off.
     * approverId is TCP's own user, not one of ours, so it cannot go in
     * approved_by_user_id, which is a foreign key into our users projection.
     *
     * @param  array<string, mixed>  $fields
     * @return array{manager: bool, employee: bool, processed_on: ?string}
     */
    private function approvals(array $fields): array
    {
        $result = ['manager' => false, 'employee' => false, 'processed_on' => null];

        $approvals = $fields['approvals'] ?? null;

        if (! is_array($approvals)) {
            return $result;
        }

        foreach ($approvals as $approval) {
            if (! is_array($approval)) {
                continue;
            }

            $type = strtolower($this->string($approval['type'] ?? null) ?? '');
            $approved = ($approval['approved'] ?? false) === true;

            if ($type === 'managerapproval') {
                $result['manager'] = $approved;
                $result['processed_on'] = $approved
                    ? $this->parseUtc($this->string($approval['processedOn'] ?? null))?->format('Y-m-d H:i:s')
                    : null;
            }

            if ($type === 'employeeapproval') {
                $result['employee'] = $approved;
            }
        }

        return $result;
    }

    private function resolveStoreId(array $fields, Employee $employee, ?int $requestedStoreId): ?int
    {
        // WHERE THE PUNCH ACTUALLY HAPPENED, and it took a live payload to find
        // it. The documented schema suggested employeeDefaultLocationId, which
        // never appears in a real record — and would have been the employee's
        // HOME store anyway, not where they worked. The clock itself reports it:
        //
        //   punchInInformation.punchLocation = "03795-00042-0*"
        //
        // The store number is the leading NNNNN-NNNNN, and it matches
        // stores.store_number directly. This is the one source here that
        // survives somebody covering another store.
        $storeId = $this->storeIdFromPunchLocation($fields);

        if ($storeId !== null) {
            return $storeId;
        }

        $external = $this->string($this->pick($fields, [
            'employeeDefaultLocationId', 'employee_default_location_id',
            'locationId', 'location_id', 'location', 'siteId', 'site_id',
        ]));

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

        // THE ROLE SUFFIX, when the whole code is not mapped. A TCP job code is
        // franchise + store + role — 37954202 is role 02 at store 3795-42 — and
        // there are 237 of them for seven roles. PositionSeeder maps the role,
        // not the code, so this is the lookup that resolves in practice.
        //
        // It is also what makes a NEW STORE need no seeding: 37954902 decodes
        // through the same '02' the day that store opens.
        //
        // Company-wide codes are four digits (1000 Regular, 2000 Sick) and do
        // not decode, so they stay null rather than borrowing role '00' — they
        // are pay categories, and filing hours under a position nobody worked
        // corrupts the labour report more quietly than an empty column.
        $entityId ??= TcpJobCodeRole::positionIdFor($external);

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
     * The TCP ids of everyone who works at this store.
     *
     * THE ONLY WAY to scope a sync, because GET /worksegments has no location
     * filter — verified against the live API. So the store filter has to be
     * expressed as "these people", and the question becomes: whose list?
     *
     * BOTH, UNIONED, and each half covers the other's blind spot:
     *
     *   TCP'S OWN ROSTER — GET /employees?locations={store_number}. Every TCP
     *       employee record carries a `location` holding that same store number,
     *       so this is the authority on who TCP files at this store, and it is
     *       current. Our own table is a projection filled by hiring events and an
     *       out-of-band seeder; somebody TCP added this morning is not in it, and
     *       their punches would simply never be asked about.
     *
     *   OURS — primary store plus any explicit assignment. A cover shift arranged
     *       on this side does not change TCP's `location` field, so this is the
     *       half that knows about it.
     *
     * Widening the list cannot misfile anything: which store a punch lands on is
     * decided per-record by resolveStoreId(), from the punch's own clock. Asking
     * about somebody who did not work here costs one id in a filter and returns
     * nothing.
     *
     * FALLS BACK TO OURS ALONE, quietly, when TCP cannot be reached. Losing the
     * roster call must not lose the punches we could still have pulled.
     *
     * @return array<int, string>
     */
    private function tcpEmployeeIdsForStore(int $storeId): array
    {
        $assigned = EmployeeStoreAssignment::query()
            ->where('store_id', $storeId)
            ->pluck('employee_id');

        $ours = Employee::query()
            ->whereNotNull('tcp_employee_id')
            ->where(fn (Builder $query): Builder => $query
                ->where('primary_store_id', $storeId)
                ->orWhereIn('id', $assigned))
            ->pluck('tcp_employee_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return collect([...$this->tcpRosterForStore($storeId), ...$ours])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Who TCP currently files at this store, straight from GET /employees.
     *
     * Memoised per run: a week's sync asks once, not once per chunk.
     *
     * The filter is the STORE NUMBER, not the numeric TCP location id — see
     * TcpEmployeeReader, where `locationIds=9830400` was silently ignored and
     * returned the whole company while `locations=03795-00001` returned that
     * store's twenty.
     *
     * @return array<int, string>
     */
    private function tcpRosterForStore(int $storeId): array
    {
        if (array_key_exists($storeId, $this->rosters)) {
            return $this->rosters[$storeId];
        }

        $storeNumber = $this->string(Store::query()->whereKey($storeId)->value('store_number'));

        if ($storeNumber === null) {
            return $this->rosters[$storeId] = [];
        }

        try {
            $records = $this->tcp->employees(new EmployeeFilter(locations: [$storeNumber]));
        } catch (Throwable) {
            // Not configured, unreachable, rate-limited: all the same answer
            // here, which is "use what we already know" rather than no punches.
            return $this->rosters[$storeId] = [];
        }

        $ids = [];

        foreach ($records as $record) {
            $id = $this->string($this->pick($this->lowered($record), [
                'employeeId', 'employee_id', 'employeeID', 'employeeNumber', 'employee_number',
            ]));

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $this->rosters[$storeId] = array_values(array_unique($ids));
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

    /**
     * Paid hours for a punch: the block, less the break.
     *
     * Derived here because TCP does not send an hours field — deliberately the
     * same arithmetic as WorkSegmentService::hoursBetween(), so a punch that
     * arrives from the sync and one a manager typed are costed identically.
     * An open punch has no hours yet, which is what null means downstream.
     */
    private function hoursBetween(CarbonImmutable $timeIn, ?CarbonImmutable $timeOut, int $breakMinutes): ?float
    {
        if ($timeOut === null) {
            return null;
        }

        $minutes = abs($timeIn->diffInMinutes($timeOut)) - $breakMinutes;

        return round(max($minutes, 0) / 60, 2);
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
