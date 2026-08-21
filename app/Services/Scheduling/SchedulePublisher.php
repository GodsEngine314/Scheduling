<?php

namespace App\Services\Scheduling;

use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSystem;
use App\Enums\PublishState;
use App\Exceptions\IntegrationException;
use App\Exceptions\SchedulingException;
use App\Models\HumanitySchedule;
use App\Models\IntegrationIdentity;
use App\Models\Shift;
use App\Models\Store;
use App\Models\TcpJobCodeRole;
use App\Support\BusinessDay;
use App\Support\Integrations\Humanity\HumanityClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The publish seam: local shifts become Humanity shifts.
 *
 * IDEMPOTENCE IS THE WHOLE POINT. Humanity has no upsert — a second POST is a
 * second shift on somebody's roster — so every row carries
 * payload_fingerprint, the SHA-256 of the state Humanity last accepted. A
 * publish run re-sends only the rows whose fingerprint no longer matches. Two
 * things void a fingerprint and neither is recomputed here:
 *
 *   - ShiftService::update() nulls it when a Humanity-visible field changes.
 *   - unpublish() nulls it because the shift it described is gone.
 *
 * SPLIT SHIFTS NEED NO SPECIAL CASE, and nothing below looks at
 * split_group_id. One shifts row is one continuous block of work and maps 1:1
 * onto one Humanity shift, so each part of a split publishes as ITS OWN
 * Humanity shift with its own humanity_shift_id and its own fingerprint. That
 * alignment is why the shifts table stores a split as two rows rather than one
 * row with a hole in it.
 *
 * The HTTP call is always OUTSIDE the transaction. A vendor round trip inside
 * an open transaction holds row locks for the length of somebody else's
 * outage.
 */
class SchedulePublisher
{
    /**
     * States a publish sweep picks up. 'published' is not one of them — except
     * where the fingerprint is null, see pendingInRange().
     *
     * @var array<int, PublishState>
     */
    private const PUBLISHABLE = [
        PublishState::Draft,
        PublishState::Queued,
        PublishState::Failed,
        // Live in Humanity but unlocked for editing. It keeps its
        // humanity_shift_id, so push() routes it to PUT rather than POST — and
        // if nothing was actually changed the fingerprint still matches and it
        // is reported 'unchanged' instead of costing a pointless request.
        PublishState::Unlocked,
    ];

    /** CONFIRMED: a shift's `type` is 0 Standard, 1 Open. */
    private const TYPE_STANDARD = 0;

    private const TYPE_OPEN = 1;

    /**
     * Per-run memo of "{entity_type}:{entity_id}" => Humanity id (or null).
     * A sweep over a fortnight asks the same twenty questions hundreds of
     * times. Per-instance, not static: one publisher is one run.
     *
     * @var array<string, string|null>
     */
    private array $externalIds = [];

    public function __construct(
        private readonly HumanityClient $humanity,
        private readonly BusinessDay $businessDay,
    ) {}

    /**
     * Publish everything outstanding for one store between two business dates.
     *
     * @param  string  $from  Y-m-d, inclusive
     * @param  string  $to  Y-m-d, inclusive
     * @return array{
     *     store_id: int,
     *     from: string,
     *     to: string,
     *     total: int,
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     failed: int,
     *     results: array<int, array<string, mixed>>
     * }
     */
    public function publishRange(int $storeId, string $from, string $to): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
        $results = [];

        foreach ($this->pendingInRange($storeId, $from, $to) as $shift) {
            $result = $this->publishShift($shift);
            $counts[$result['status']]++;
            $results[] = $result;
        }

        return array_merge([
            'store_id' => $storeId,
            'from' => $from,
            'to' => $to,
            'total' => count($results),
        ], $counts, ['results' => $results]);
    }

    /**
     * The rows a publish sweep would touch.
     *
     * Shared with the command so "what would be published" and "what gets
     * published" cannot drift apart.
     *
     * A published shift whose payload_fingerprint is NULL is included on
     * purpose: an edit voided the fingerprint but left publish_state alone, so
     * Humanity is holding a shift that no longer matches ours. Selecting only
     * draft/queued/failed would leave that edit invisible to the vendor
     * forever.
     *
     * @return Collection<int, Shift>
     */
    public function pendingInRange(int $storeId, string $from, string $to): Collection
    {
        return Shift::query()
            ->forStoreBetween($storeId, $from, $to)
            // A shift somebody has already punched against is not a plan any
            // more, it is history, and history does not get pushed to a roster
            // people read to find out when to come in.
            //
            // EDITING IS STILL ALLOWED — only publishing is refused, which is
            // the asymmetry that was asked for. The consequence to know: an
            // edit to a worked shift that Humanity already holds will now never
            // be sent, so the two diverge permanently. That is the intended
            // trade, not an oversight.
            ->whereDoesntHave('workSegments')
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('publish_state', array_map(
                        static fn (PublishState $state): string => $state->value,
                        self::PUBLISHABLE,
                    ))
                    ->orWhere(fn (Builder $edited): Builder => $edited
                        ->where('publish_state', PublishState::Published->value)
                        ->whereNull('payload_fingerprint'));
            })
            ->get();
    }

    /**
     * One shift, reported rather than thrown: a sweep must not stop at the
     * first store that has an unmapped employee.
     *
     * @return array<string, mixed>
     */
    public function publishShift(Shift $shift): array
    {
        try {
            return $this->result($shift, $this->push($shift));
        } catch (Throwable $e) {
            // push() has already recorded the failure on the row.
            return $this->result($shift, 'failed', $e->getMessage());
        }
    }

    /**
     * Create or update, whichever this shift needs, or nothing at all when
     * Humanity already has exactly this state.
     *
     * @return string 'created', 'updated' or 'unchanged'
     *
     * @throws Throwable
     */
    public function push(Shift $shift): string
    {
        try {
            $state = $this->desiredState($shift);
        } catch (Throwable $e) {
            // The staffing guard trips here. Record it so the shift shows as
            // failed with the reason on it, then let the caller decide.
            $this->recordFailure($shift, $e);

            throw $e;
        }

        if ($this->isUnchanged($shift, $this->fingerprint($state))) {
            // Settle an unlocked-but-unedited shift back to published. Humanity
            // is not touched — it already holds exactly this — but the row must
            // stop being reported as pending, or pendingInRange() keeps
            // returning it and the publish button's count never reaches zero.
            if ($shift->publish_state === PublishState::Unlocked) {
                $shift->forceFill(['publish_state' => PublishState::Published])->save();
            }

            return 'unchanged';
        }

        if ($shift->humanity_shift_id === null) {
            $this->pushCreate($shift);

            return 'created';
        }

        $this->pushUpdate($shift);

        return 'updated';
    }

    /**
     * A shift Humanity has never seen.
     *
     * @throws Throwable
     */
    public function pushCreate(Shift $shift): Shift
    {
        try {
            $state = $this->desiredState($shift);
            $response = $this->humanity->createShift($this->createBody($state));
            $humanityShiftId = $this->shiftIdFrom($response);

            if ($humanityShiftId === null) {
                // Accepted but untrackable. Storing nothing would have the next
                // run create it a second time, so this has to be a failure.
                throw IntegrationException::guard(
                    'humanity',
                    $this->shiftsEndpoint(),
                    "Shift #{$shift->id} was accepted by Humanity but the response carried no shift id, "
                    .'so it cannot be updated or de-duplicated later.',
                );
            }

            // The create already carried employee_id, so in the normal case the
            // shift lands staffed in one request and there is nothing to do
            // here. This is the repair for when it does not: if the response
            // reports a roster and somebody we asked for is missing from it,
            // add them.
            //
            // SILENCE IS NOT AN EMPTY ROSTER. A response that says nothing about
            // employees leaves this alone — the parameter is documented to
            // assign them, and inventing a disagreement out of a field the
            // vendor simply did not send would put a second request behind every
            // single create.
            $rostered = $this->humanity->staffingFrom($response);

            if ($rostered !== null) {
                $this->applyStaffing(
                    $humanityShiftId,
                    array_values(array_diff($state['staffing'], $rostered)),
                    [],
                );
            }

            $this->recordSuccess($shift, $humanityShiftId, $this->fingerprint($state));
        } catch (Throwable $e) {
            $this->recordFailure($shift, $e);

            throw $e;
        }

        return $shift;
    }

    /**
     * A shift Humanity already holds, whose state has moved.
     *
     * @throws Throwable
     */
    public function pushUpdate(Shift $shift): Shift
    {
        $humanityShiftId = (string) $shift->humanity_shift_id;

        try {
            $state = $this->desiredState($shift);

            $this->humanity->updateShift($humanityShiftId, $this->wireBody($state));

            // staffingDeltaForShift, not staffingDelta: who Humanity thinks is
            // on the shift may have changed there since we last looked, and a
            // failed read adds without removing rather than emptying a roster.
            $delta = $this->humanity->staffingDeltaForShift($humanityShiftId, $state['staffing']);

            $this->applyStaffing($humanityShiftId, $delta['add'], $delta['remove']);

            $this->recordSuccess($shift, $humanityShiftId, $this->fingerprint($state));
        } catch (Throwable $e) {
            $this->recordFailure($shift, $e);

            throw $e;
        }

        return $shift;
    }

    /**
     * Take a shift back out of Humanity.
     *
     * @param  string|null  $rule  'following' or 'all'; null takes the configured default
     *
     * @throws Throwable
     */
    /**
     * Unlock a published shift so it can be edited. Humanity is NOT touched.
     *
     * This is the gate the editing workflow turns on: a published shift is
     * locked, and every edit path refuses until this has been called. What it
     * does is deliberately small — flip the state and leave everything else
     * alone:
     *
     *   humanity_shift_id  KEPT. It is what makes the next publish a
     *                      PUT /shifts/{id} instead of a second POST, which
     *                      would leave the employee with two shifts.
     *   payload_fingerprint KEPT. Unlocking is not itself a change. If the
     *                      manager thinks better of it and edits nothing, the
     *                      next publish matches the fingerprint and reports
     *                      'unchanged' rather than spending a request. The
     *                      first real edit nulls it (ShiftService::update).
     *
     * Employees keep seeing the last published version until the edit is
     * re-published. A shift briefly out of date is better than one that
     * disappears from somebody's week while a manager is mid-thought.
     */
    public function unpublish(Shift $shift): Shift
    {
        if (! $shift->publish_state?->isLive()) {
            throw new SchedulingException(
                "Shift #{$shift->id} is not published, so there is nothing to unpublish.",
                ['shift_id' => $shift->id, 'publish_state' => $shift->publish_state?->value],
            );
        }

        return DB::transaction(function () use ($shift): Shift {
            $shift->forceFill(['publish_state' => PublishState::Unlocked])->save();

            return $shift;
        });
    }

    public function withdraw(Shift $shift, ?string $rule = null): Shift
    {
        $humanityShiftId = $shift->humanity_shift_id;

        if ($humanityShiftId !== null) {
            try {
                $this->humanity->deleteShift($humanityShiftId, $rule);
            } catch (Throwable $e) {
                $this->recordFailure($shift, $e);

                throw $e;
            }
        }

        return DB::transaction(function () use ($shift): Shift {
            $shift->forceFill([
                'publish_state' => PublishState::Unpublished,
                // Both columns described a shift that no longer exists. Keeping
                // the id would have the next run PUT to a deleted shift — and
                // humanity_shift_id is UNIQUE, so it would also block whatever
                // row replaces this one. Keeping the fingerprint would have the
                // next run skip the shift as unchanged and leave the store with
                // nothing in Humanity at all.
                'humanity_shift_id' => null,
                'payload_fingerprint' => null,
                'published_at' => null,
                'last_publish_error' => null,
            ])->save();

            return $shift;
        });
    }

    /**
     * The complete desired state of this shift in Humanity, as ONE canonical
     * array.
     *
     * THE FIELD NAMES AND FORMATS ARE CONFIRMED against
     * platform.humanity.com/reference/post-shift, and what was here before was
     * not merely differently spelled — it could not have worked:
     *
     *   start_date / end_date  were MISSING. Humanity takes the date and the
     *       time as four separate required fields, not two timestamps, so every
     *       create was short two required parameters.
     *   start_time / end_time  were 'Y-m-d H:i:s'. The documented format is
     *       'g:ia' — "5:00pm".
     *   schedule  is REQUIRED, and nothing populated the mapping it reads, so
     *       wireBody()'s null filter quietly dropped it from every request.
     *
     * TWO DATES, NOT ONE, and that is what carries an overnight shift: a
     * 21:00–01:00 block ends on the following calendar day, and end_date says so
     * while business_date still files the shift under the day it started.
     *
     * The fingerprint is taken over this whole thing, staffing included, even
     * though staffing travels differently on a create and an update. It still
     * has to void the fingerprint, or reassigning a shift would look like no
     * change at all.
     *
     * @return array<string, mixed>
     */
    private function desiredState(Shift $shift): array
    {
        $storeId = (int) $shift->store_id;

        // Store-local wall clock. Humanity schedules are per location and a
        // location has one clock on the wall; sending UTC would show every
        // shift at the wrong hour to the manager reading it.
        $start = $this->businessDay->toLocal($storeId, $shift->start_at);
        $end = $this->businessDay->toLocal($storeId, $shift->end_at);

        $isOpen = $shift->employee_id === null;

        $state = [
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            // CONFIRMED format, and note it carries no seconds: Humanity's shift
            // clock is minutes. A shift edited only below the minute therefore
            // fingerprints the same and is reported unchanged, which is correct
            // — there is nothing to send.
            'start_time' => $start->format('g:ia'),
            'end_time' => $end->format('g:ia'),
            'schedule' => $this->scheduleFor($shift),
            'notes' => $shift->notes,
            // 0 Standard, 1 Open. An unassigned shift on our board is one nobody
            // has been given yet, which is what Open means; publishing it as
            // Standard-with-nobody-on-it hides it from the people who could take
            // it.
            'type' => $isOpen && (bool) config('humanity.publish_open_shifts_as_open', true)
                ? self::TYPE_OPEN
                : self::TYPE_STANDARD,
            /**
             * needed ONLY FOR AN OPEN SHIFT, and this is what a live GET /shifts
             * corrected. Every real shift in this account reads:
             *
             *     type = 0    needed = 0    location = 0
             *
             * So a staffed standard shift carries needed 0, not 1 — `needed`
             * counts slots to FILL, and a shift with its person on it has none.
             * Sending 1 would have every published shift ask the store for one
             * more body than it wants.
             */
            'needed' => $isOpen ? 1 : 0,
            'staffing' => $this->desiredStaffing($shift),
        ];

        // Off by default: `location` is Humanity's REMOTE location override, and
        // the shift's real location comes from its schedule. See
        // config/humanity.php.
        if ((bool) config('humanity.send_shift_location', false)) {
            $state['location'] = $this->externalId(IntegrationEntityType::Store, $storeId);
        }

        // Stable key order, so a fingerprint depends on the values and not on
        // the order this method happened to build them in.
        ksort($state);

        return $state;
    }

    /**
     * The Humanity schedule this shift belongs to. REQUIRED on POST /shifts.
     *
     * Refused rather than omitted, and loudly, for the same reason the staffing
     * guard below refuses: a shift Humanity rejects for a missing required field
     * costs a round trip and reports a vendor error about a concept no manager
     * has heard of, where this says which store and which position to go and fix.
     *
     * The two failures are told apart on purpose. An EMPTY catalogue is a setup
     * step somebody has not run; a catalogue that simply has no row for this
     * store and position is a decision somebody has to make in Humanity.
     *
     * @throws IntegrationException
     */
    private function scheduleFor(Shift $shift): string
    {
        $storeId = (int) $shift->store_id;

        if ($shift->position_id === null) {
            throw IntegrationException::guard(
                'humanity',
                $this->shiftsEndpoint(),
                sprintf(
                    'Shift #%d has no position, and Humanity requires a schedule (its name for a position) on '
                    .'every shift. A shift with somebody on it takes its role from their profile, so this means '
                    .'neither hiring nor TCP has a role for %s: set one on their profile in the hiring system, '
                    .'or assign them a job code at this store in TCP. The board no longer offers a position to '
                    .'pick here, because a role picked in scheduling was wrong in the two places nobody looks — '
                    .'the labour cost and the published rota.',
                    $shift->id,
                    $shift->employee?->fullName() ?? 'the open slot (set its position when creating it)',
                ),
            );
        }

        $positionId = (int) $shift->position_id;
        $scheduleId = HumanitySchedule::scheduleFor($storeId, $positionId);

        if ($scheduleId !== null) {
            return $scheduleId;
        }

        if (! HumanitySchedule::isPopulated()) {
            throw IntegrationException::guard(
                'humanity',
                $this->shiftsEndpoint(),
                sprintf(
                    'Shift #%d cannot be published: the Humanity schedule catalogue is empty, so there is no '
                    .'schedule id to name on it. Export GET /positions to '
                    .'storage/app/integrations/humanity-positions.json and run '
                    .'`php artisan db:seed --class=HumanitySeeder`.',
                    $shift->id,
                ),
            );
        }

        /**
         * TWO DIFFERENT FAILURES, and only one of them is fixable in Humanity.
         *
         * The catalogue is keyed by the TCP JOB CODE Humanity carries on each
         * position, so the first question is whether TCP has a code for this
         * store and role at all. If it does not, Humanity was never going to
         * have a matching position and there is nothing to create there — the
         * role does not exist in either system. If it does, the code is simply
         * not on any Humanity position yet, which IS a five-minute fix.
         *
         * Told apart here rather than in one vague message, because the two send
         * a manager to different places.
         */
        $storeNumber = Store::query()->whereKey($storeId)->value('store_number');
        $jobCode = TcpJobCodeRole::jobCodeIdFor(
            $storeNumber === null ? null : (string) $storeNumber,
            $positionId,
        );
        $label = $shift->position?->label ?? 'unknown';

        throw IntegrationException::guard(
            'humanity',
            $this->shiftsEndpoint(),
            $jobCode === null
                ? sprintf(
                    'Shift #%d is for position "%s" at store #%d, and TCP has no job code for that store and '
                    .'role — so Humanity has no schedule for it either, and creating one there would not help. '
                    .'The board only offers roles TCP has a code for, so this shift predates that rule: move '
                    .'it to a role the store actually staffs. Refusing to publish, because a shift with no '
                    .'schedule is rejected by Humanity outright.',
                    $shift->id,
                    $label,
                    $storeId,
                )
                : sprintf(
                    'Shift #%d is for position "%s" at store #%d, which is TCP job code %s, and no Humanity '
                    .'position carries that code. Set the job code on the store\'s "%s" position in Humanity '
                    .'(or create it), then run `php artisan humanity:export-positions` and '
                    .'`php artisan db:seed --class=HumanitySeeder`. Refusing to publish, because a shift with '
                    .'no schedule is rejected by Humanity outright.',
                    $shift->id,
                    $label,
                    $storeId,
                    $jobCode,
                    $label,
                ),
        );
    }

    /**
     * Who should be on this shift in Humanity.
     *
     * @return array<int, string>
     */
    private function desiredStaffing(Shift $shift): array
    {
        if ($shift->employee_id === null) {
            // An open shift is MEANT to be unstaffed — it is placed on the board
            // before anyone is assigned — so an empty roster here is the
            // correct answer, not a missing mapping.
            return [];
        }

        $employeeId = (int) $shift->employee_id;
        $humanityEmployeeId = $this->externalId(IntegrationEntityType::Employee, $employeeId);

        if ($humanityEmployeeId === null) {
            throw IntegrationException::guard(
                'humanity',
                $this->shiftsEndpoint(),
                sprintf(
                    'Shift #%d is assigned to employee #%d, who has no Humanity employee id in '
                    .'integration_identities. NOTHING POPULATES THE HUMANITY EMPLOYEE ID YET — that is the '
                    .'known gap — so this shift cannot be staffed. Refusing to publish, because publishing '
                    .'it anyway would create a shift in Humanity with nobody on it and the store would read '
                    .'that as an open shift nobody is covering.',
                    $shift->id,
                    $employeeId,
                ),
            );
        }

        return [$humanityEmployeeId];
    }

    /**
     * @param  array<int, string>  $add
     * @param  array<int, string>  $remove
     */
    private function applyStaffing(string $humanityShiftId, array $add, array $remove): void
    {
        if ($add === [] && $remove === []) {
            return;
        }

        $this->humanity->updateShiftStaffing($humanityShiftId, $add, $remove);
    }

    /**
     * The body for a CREATE: the canonical state, with the roster travelling as
     * the employee_id parameter POST /shifts documents for exactly this.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function createBody(array $state): array
    {
        /** @var array<int, string> $staffing */
        $staffing = $state['staffing'] ?? [];
        $body = $this->wireBody($state);

        if ($staffing !== []) {
            // "A comma-separated employee IDs which will be assigned to a
            // shift". One string, not an array: the body is form-encoded, and an
            // array would be sent as employee_id[0]=…
            $body['employee_id'] = implode(',', $staffing);
        }

        return $body;
    }

    /**
     * The request body: the canonical state minus the roster, which travels
     * differently on a create and an update, minus anything with no value.
     *
     * The null filter is why `schedule` had to become a hard failure rather than
     * a null: a required field that quietly disappears here produces a request
     * that looks well-formed and is rejected for something it does not say.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function wireBody(array $state): array
    {
        unset($state['staffing']);

        return array_filter($state, static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $state */
    private function fingerprint(array $state): string
    {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * A fingerprint alone does not mean Humanity has the shift. Without a
     * humanity_shift_id there is nothing there to have matched, whatever the
     * column says — so all three conditions have to hold.
     */
    private function isUnchanged(Shift $shift, string $fingerprint): bool
    {
        return $shift->humanity_shift_id !== null
            && $shift->payload_fingerprint === $fingerprint
            // isLive(), not === Published. An UNLOCKED shift is also live in
            // Humanity, and unlocking is not itself a change: a manager who
            // unpublishes, thinks better of it and re-publishes must cost
            // Humanity nothing. Requiring Published here sent a pointless PUT
            // every time somebody changed their mind.
            && (bool) $shift->publish_state?->isLive();
    }

    /**
     * The Humanity id for one of our entities, or null when it is unmapped.
     *
     * isSynced() rather than a bare external_id read: a row that carries an id
     * but is still marked pending or failed is a mapping nobody has confirmed.
     */
    private function externalId(IntegrationEntityType $entityType, int $entityId): ?string
    {
        $key = $entityType->value.':'.$entityId;

        if (array_key_exists($key, $this->externalIds)) {
            return $this->externalIds[$key];
        }

        $identity = IntegrationIdentity::query()
            ->forEntity($entityType, $entityId, IntegrationSystem::Humanity)
            ->first();

        return $this->externalIds[$key] = $identity?->isSynced() === true
            ? (string) $identity->external_id
            : null;
    }

    /**
     * Pull the new shift's id out of whatever shape the create came back in.
     *
     * GUESS, and tolerant on purpose: the response may be the shift, the shift
     * inside a 'data' envelope, or a single-element list echoing what was
     * accepted.
     *
     * @param  array<mixed>  $response
     */
    private function shiftIdFrom(array $response): ?string
    {
        $shift = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;

        if (array_is_list($shift) && isset($shift[0]) && is_array($shift[0])) {
            $shift = $shift[0];
        }

        foreach (['id', 'shift_id', 'shiftId'] as $key) {
            $value = $shift[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function recordSuccess(Shift $shift, string $humanityShiftId, string $fingerprint): void
    {
        DB::transaction(function () use ($shift, $humanityShiftId, $fingerprint): void {
            $shift->forceFill([
                'humanity_shift_id' => $humanityShiftId,
                'payload_fingerprint' => $fingerprint,
                'published_at' => now(),
                'publish_state' => PublishState::Published,
                'last_publish_error' => null,
                // Consecutive failures, not lifetime attempts. A run that
                // succeeded says there is nothing wrong with this shift, and an
                // operator scanning for trouble should not see a number that
                // only ever grows.
                'publish_attempts' => 0,
            ])->save();
        });
    }

    private function recordFailure(Shift $shift, Throwable $e): void
    {
        DB::transaction(function () use ($shift, $e): void {
            $shift->forceFill([
                'publish_state' => PublishState::Failed,
                // getMessage() only. IntegrationException keeps the vendor's
                // response body out of the message on purpose — error bodies
                // echo the record we sent, and that record has people in it.
                'last_publish_error' => $e->getMessage(),
                'publish_attempts' => (int) $shift->publish_attempts + 1,
            ])->save();
        });
    }

    /** @return array<string, mixed> */
    private function result(Shift $shift, string $status, ?string $error = null): array
    {
        return [
            'shift_id' => (int) $shift->id,
            'status' => $status,
            'employee_id' => $shift->employee_id === null ? null : (int) $shift->employee_id,
            'business_date' => $shift->business_date?->toDateString(),
            'humanity_shift_id' => $shift->humanity_shift_id,
            'error' => $error,
        ];
    }

    /** Only for the guard exceptions raised here; the client builds its own. */
    private function shiftsEndpoint(): string
    {
        return rtrim((string) config('humanity.base_uri', ''), '/').'/shifts';
    }
}
