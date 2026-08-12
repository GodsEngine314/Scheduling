<?php

namespace App\Services\Scheduling;

use App\Enums\ActivityAction;
use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSystem;
use App\Enums\PublishState;
use App\Exceptions\IntegrationException;
use App\Exceptions\SchedulingException;
use App\Models\IntegrationIdentity;
use App\Models\Shift;
use App\Services\ActivityLogger;
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
        private readonly ActivityLogger $activity,
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
            $response = $this->humanity->createShift($this->wireBody($state));
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

            // A brand new Humanity shift has nobody on it, so the delta is the
            // whole desired roster and there is no current roster to read.
            $this->applyStaffing($humanityShiftId, $state['staffing'], []);

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
            $was = $shift->publish_state;

            $shift->forceFill(['publish_state' => PublishState::Unlocked])->save();

            $this->activity->shift($shift, ActivityAction::Unpublished, [
                'publish_state' => ['from' => $was?->value, 'to' => PublishState::Unlocked->value],
            ], [
                'humanity_shift_id' => $shift->humanity_shift_id,
                'note' => 'Still live in Humanity; the next publish sends a PUT.',
            ]);

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
     * The fingerprint is taken over this whole thing, staffing included, even
     * though staffing does not travel in the request body: Humanity only
     * honours employee_id alongside copy_to and silently ignores it otherwise
     * (see HumanityClient::updateShiftStaffing), so a roster change is applied
     * as an add/remove delta. It still has to void the fingerprint, or
     * reassigning a shift would look like no change at all.
     *
     * GUESS: every field name here is inferred. The vendor's field tables are
     * images that could not be read.
     *
     * @return array<string, mixed>
     */
    private function desiredState(Shift $shift): array
    {
        $storeId = (int) $shift->store_id;

        $state = [
            // Store-local wall clock. Humanity schedules are per location and a
            // location has one clock on the wall; sending UTC would show every
            // shift at the wrong hour to the manager reading it.
            'start_time' => $this->businessDay->toLocal($storeId, $shift->start_at)->format('Y-m-d H:i:s'),
            'end_time' => $this->businessDay->toLocal($storeId, $shift->end_at)->format('Y-m-d H:i:s'),
            'notes' => $shift->notes,
            'location' => $this->externalId(IntegrationEntityType::Store, $storeId),
            'schedule' => $shift->position_id === null
                ? null
                : $this->externalId(IntegrationEntityType::Position, (int) $shift->position_id),
            'staffing' => $this->desiredStaffing($shift),
        ];

        // Stable key order, so a fingerprint depends on the values and not on
        // the order this method happened to build them in.
        ksort($state);

        return $state;
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
     * The request body: the canonical state minus the roster Humanity will not
     * accept in a body, minus the fields we have no mapping for.
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
        // Was this the first POST or a PUT over an existing shift? Read before
        // the write, because the state is about to become Published either way.
        $wasLive = (bool) $shift->publish_state?->isLive();

        DB::transaction(function () use ($shift, $humanityShiftId, $fingerprint, $wasLive): void {
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

            // The moment a shift went live, and by which verb. This is the line
            // someone reads when asking "when did the employee first see this?"
            $this->activity->shift($shift, ActivityAction::Published, [], [
                'humanity_shift_id' => $humanityShiftId,
                'method' => $wasLive ? 'PUT' : 'POST',
            ]);
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
