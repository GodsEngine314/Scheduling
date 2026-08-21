<?php

namespace App\Support\Integrations\Humanity;

use App\Exceptions\IntegrationException;
use App\Support\Integrations\AbstractApiClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Humanity (shiftplanning).
 *
 * A write target, not a source of truth. Published shifts land here and the
 * shift id that comes back is stored on shifts.humanity_shift_id — a
 * scheduling-owned column, never a projection, because a stream replay that
 * cleared it would make the next publish run duplicate every shift in the
 * vendor.
 *
 * PATHS AND FIELD NAMES ARE NOW CONFIRMED against the vendor's own reference
 * (platform.humanity.com/reference/post-shift, put-shift, delete-shift,
 * get-shift and docs/getting-started-with-authentication), which replaced the
 * guesses this class was originally written from. The corrections that mattered:
 *
 *   - Bodies are application/x-www-form-urlencoded, not JSON. See bodyFormat().
 *   - The token rides as ?access_token=, not ?_token=.
 *   - POST takes employee_id to staff a new shift. PUT does NOT — there,
 *     employee_id is honoured only alongside copy_to, and staffing moves through
 *     add/remove. That asymmetry is the whole reason this class exists rather
 *     than a handful of Http::post calls.
 */
class HumanityClient extends AbstractApiClient
{
    /** CONFIRMED: POST/PUT/DELETE /shifts, GET /shifts/{id}. */
    private const SHIFTS_PATH = '/shifts';

    /** CONFIRMED: GET /positions returns every position in the account. */
    private const POSITIONS_PATH = '/positions';

    /** What deleteShift will accept. 'all' wipes occurrences already in the past. */
    private const DELETE_RULES = ['following', 'all'];

    protected function integration(): string
    {
        return 'humanity';
    }

    /**
     * CONFIRMED: every documented Humanity endpoint is
     * application/x-www-form-urlencoded.
     *
     * This class sent JSON until it was checked, and that is a worse bug than it
     * looks. Humanity would not have rejected it — a PHP endpoint reading
     * request parameters simply finds none in a JSON body, so a create arrives
     * with no start_date, no schedule and no employee, and the only signal is a
     * vendor error about fields we did send.
     */
    protected function bodyFormat(): string
    {
        return 'form';
    }

    protected function authDescriptor(): array
    {
        $mode = (string) config('humanity.auth_mode', 'oauth');
        $transport = (string) config('humanity.auth_transport', '_token');
        $param = (string) config('humanity.token_query_param', '_token');

        if (! in_array($mode, ['oauth', 'static'], true)) {
            throw IntegrationException::configuration(
                'humanity',
                "Unknown humanity.auth_mode '{$mode}'; expected 'oauth' or 'static'.",
            );
        }

        if (! in_array($transport, ['_token', 'bearer'], true)) {
            throw IntegrationException::configuration(
                'humanity',
                "Unknown humanity.auth_transport '{$transport}'; expected '_token' or 'bearer'.",
            );
        }

        $staticToken = trim((string) (config('humanity.static_token') ?? ''));

        // Refused rather than sent empty. An Authorization header reading
        // "Bearer " is a 401 from the vendor that looks like a bad credential
        // instead of what it is — a missing line in the env.
        if ($mode === 'static' && $staticToken === '') {
            throw IntegrationException::configuration(
                'humanity',
                "humanity.auth_mode is 'static' but humanity.static_token is empty.",
            );
        }

        return [
            // MODE AND TRANSPORT ARE INDEPENDENT. mode says where the token
            // comes from — a token call, or the env. transport says how it
            // rides on the request — a query parameter, or a header. Humanity's
            // own examples use ?_token=, so that stays the default whichever
            // mode is in play.
            'mode' => $mode,
            'transport' => $transport === 'bearer' ? 'header' : 'query',
            'header' => 'Authorization',
            'prefix' => 'Bearer',
            'param' => $param,
            'token' => $mode === 'static' ? $staticToken : null,
        ];
    }

    /**
     * Every position in the account — what a shift names as its `schedule`.
     *
     * READ ONLY, and the only Humanity call in this service that creates
     * nothing. It is the source humanity_schedules wants: each record carries
     * {id, name, location: {id, name}}, so a position states its location as an
     * id instead of leaving it to be parsed out of a name like
     * "Crew Member - 3795-25".
     *
     * A position nobody is assigned to appears here and nowhere else, which is
     * the whole reason this exists — the employees export can only surface
     * schedules that already have people on them, and the schedule a manager
     * needs next is the one at the store they are still staffing up.
     *
     * @return array<int, array<string, mixed>>
     */
    public function positions(bool $includeDeleted = false): array
    {
        // include_deleted is documented as usable only WITH updated_at, so the
        // two travel together or not at all; the epoch means "everything".
        $query = $includeDeleted
            ? ['include_deleted' => 1, 'updated_at' => '1970-01-01T00:00:00+00:00']
            : [];

        return $this->records($this->get(self::POSITIONS_PATH, $query));
    }

    /**
     * The records out of a Humanity envelope: {status, data, token}.
     *
     * Tolerant of `data` arriving as a list or as a map keyed by id, which
     * different endpoints do differently — array_values collapses both to the
     * same thing.
     *
     * @param  array<mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function records(array $response): array
    {
        $data = $response['data'] ?? $response;

        return is_array($data)
            ? array_values(array_filter($data, 'is_array'))
            : [];
    }

    /**
     * A new shift, staffed in the same request.
     *
     * employee_id IS accepted here — "a comma-separated employee IDs which will
     * be assigned to a shift" — and passing it is not merely an optimisation.
     * Creating the shift bare and adding people in a second call leaves a shift
     * with nobody on it in Humanity for as long as the second call takes, and a
     * shift with nobody on it is what a store reads as an OPEN shift somebody
     * needs to cover. If the second call then fails, that is what stays on the
     * board.
     *
     * @param  array<string,mixed>  $shift
     * @return array<mixed>
     */
    public function createShift(array $shift): array
    {
        return $this->post(self::SHIFTS_PATH, $shift);
    }

    /**
     * @param  array<string,mixed>  $shift
     * @return array<mixed>
     */
    public function updateShift(int|string $id, array $shift): array
    {
        return $this->put(self::SHIFTS_PATH.'/'.rawurlencode((string) $id), $this->guardStaffingKeys($shift));
    }

    /**
     * @param  string|null  $rule  'following' or 'all'; null takes the configured default
     * @return array<mixed>
     */
    public function deleteShift(int|string $id, ?string $rule = null): array
    {
        $rule ??= (string) config('humanity.delete_rule', 'following');

        if (! in_array($rule, self::DELETE_RULES, true)) {
            // 'all' deletes occurrences that already happened and cannot be
            // undone from here, so a typo must not be allowed to become one of
            // the two rules by accident.
            throw IntegrationException::guard(
                'humanity',
                $this->endpoint(self::SHIFTS_PATH),
                "Unknown delete rule '{$rule}'; expected 'following' or 'all'.",
            );
        }

        // CONFIRMED as `rule`, and documented as a BODY parameter — which is
        // why it is sent as both. Humanity's API is PHP, and a PHP endpoint
        // reading request parameters sees a query string on a DELETE but not
        // necessarily a form body, so sending only what the spec says risks the
        // rule being dropped. Both, and the two always agree.
        //
        // Dropping it is survivable in one direction only: the configured
        // default is 'following', and if the rule is lost Humanity applies its
        // own. 'all' is the one that wipes occurrences already in the past, so
        // it is the one that must not be sent hopefully.
        return $this->delete(
            self::SHIFTS_PATH.'/'.rawurlencode((string) $id),
            ['rule' => $rule],
            ['rule' => $rule],
        );
    }

    /**
     * Who Humanity currently has on this shift.
     *
     * @return array<int,string> employee ids as strings
     */
    public function shiftStaffing(int|string $id): array
    {
        $response = $this->get(self::SHIFTS_PATH.'/'.rawurlencode((string) $id));

        // A shift that reports no roster at all has nobody on it. Here that
        // collapse is right — we asked the question directly and this is the
        // answer. staffingFrom() keeps the two apart because it is reading a
        // response to a different question.
        return $this->extractEmployeeIds($response) ?? [];
    }

    /**
     * The roster a create or update response volunteered, or null when it said
     * nothing about one.
     *
     * NULL AND [] ARE DIFFERENT ANSWERS and collapsing them is how a shift ends
     * up unstaffed. Empty means Humanity is telling us the shift has nobody on
     * it — worth acting on, because we just asked for somebody. Null means the
     * response simply does not carry a roster, and inventing "nobody" from
     * silence would have every create followed by a pointless second request.
     *
     * @param  array<mixed>  $response
     * @return array<int,string>|null
     */
    public function staffingFrom(array $response): ?array
    {
        return $this->extractEmployeeIds($response);
    }

    /**
     * Apply a staffing change to a shift that already exists.
     *
     * add/remove, never employee_id. CONFIRMED on PUT /shifts/{id}: add is
     * "comma-separated employee IDs which will be assigned to a shift", remove
     * is the same for taking them off, and employee_id "works only in
     * conjunction with parameter copy_to". Set on its own it is accepted and
     * ignored, which means a shift that looks reassigned here is still rostered
     * to the old employee there.
     *
     * This is the UPDATE path only. A brand new shift is staffed by
     * createShift(), where employee_id is the documented parameter.
     *
     * @param  array<int,int|string>  $add
     * @param  array<int,int|string>  $remove
     * @return array<mixed>
     */
    public function updateShiftStaffing(int|string $id, array $add, array $remove): array
    {
        $body = [];

        foreach (['add' => $add, 'remove' => $remove] as $key => $ids) {
            $normalised = $this->normaliseIds($ids);

            if ($normalised !== []) {
                // COMMA-SEPARATED STRING, not a list. The body is
                // form-encoded, so an array is sent as add[0]=42&add[1]=43 —
                // which is not the parameter Humanity documents and not one it
                // reads. The shift is then updated with its roster untouched,
                // and the only symptom is a person who never sees the shift.
                $body[$key] = implode(',', $normalised);
            }
        }

        if ($body === []) {
            return [];
        }

        return $this->updateShift($id, $body);
    }

    /**
     * The pure difference between two rosters.
     *
     * @param  array<int,int|string>  $currentIds
     * @param  array<int,int|string>  $desiredIds
     * @return array{add:array<int,string>,remove:array<int,string>}
     */
    public function staffingDelta(array $currentIds, array $desiredIds): array
    {
        $current = $this->normaliseIds($currentIds);
        $desired = $this->normaliseIds($desiredIds);

        return [
            'add' => array_values(array_diff($desired, $current)),
            'remove' => array_values(array_diff($current, $desired)),
        ];
    }

    /**
     * The same difference, against whatever Humanity says is on the shift now.
     *
     * A FAILED READ IS NOT AN EMPTY ROSTER. If we cannot see the current
     * staffing we add the people we know belong on the shift and remove
     * nobody, because the two mistakes are not the same size: an extra name on
     * a shift is a scheduling annoyance somebody notices and fixes, while
     * removing a person who really was rostered leaves a store short-staffed
     * and nobody finds out until the shift starts.
     *
     * @param  array<int,int|string>  $desiredIds
     * @return array{add:array<int,string>,remove:array<int,string>}
     */
    public function staffingDeltaForShift(int|string $shiftId, array $desiredIds): array
    {
        try {
            $current = $this->shiftStaffing($shiftId);
        } catch (IntegrationException $e) {
            $this->warnStaffingReadFailed($shiftId, $e);

            return ['add' => $this->normaliseIds($desiredIds), 'remove' => []];
        }

        return $this->staffingDelta($current, $desiredIds);
    }

    /**
     * What a shift swap means for this tenant.
     *
     * 'reassign' — one shift changes hands.
     * 'trade'    — two employees exchange their shifts.
     *
     * Read by the shift-swap workflow (employee_requests.request_type =
     * shift_swap), which decides whether approving a swap is one staffing
     * delta or two. It is not decided here because this class does not know
     * about the second shift.
     */
    public function swapStrategy(): string
    {
        return (string) config('humanity.swap_strategy', 'reassign');
    }

    /**
     * Refuse an UPDATE body that sets employee_id without copy_to — see
     * updateShiftStaffing(). Failing loudly beats a silent no-op that leaves a
     * published shift staffed to the wrong person.
     *
     * Deliberately not applied to createShift(): on POST, employee_id is the
     * documented way to staff a new shift and copy_to has nothing to do with it.
     *
     * @param  array<string,mixed>  $shift
     * @return array<string,mixed>
     */
    private function guardStaffingKeys(array $shift): array
    {
        if (array_key_exists('employee_id', $shift) && ! array_key_exists('copy_to', $shift)) {
            throw IntegrationException::guard(
                'humanity',
                $this->endpoint(self::SHIFTS_PATH),
                'employee_id is only honoured alongside copy_to; use add/remove to change staffing.',
            );
        }

        return $shift;
    }

    /**
     * Pull employee ids out of a shift response.
     *
     * CONFIRMED shape: GET /shifts/{id} answers {status, data, token} and the
     * shift's `employees` is a list of objects each carrying `id`. Still
     * tolerant of a bare list of ids and of an unwrapped shift, because those
     * cost one branch each and the alternative is an empty roster read — which
     * staffingDeltaForShift would act on by removing everybody.
     *
     * @param  array<mixed>  $response
     * @return array<int,string>|null null when the response carries no roster
     */
    private function extractEmployeeIds(array $response): ?array
    {
        $shift = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;

        $employees = $shift['employees'] ?? null;

        if (! is_array($employees)) {
            return null;
        }

        $ids = [];

        foreach ($employees as $employee) {
            if (is_scalar($employee)) {
                $ids[] = $employee;

                continue;
            }

            if (is_array($employee)) {
                $id = $employee['id'] ?? $employee['employee_id'] ?? $employee['employeeId'] ?? null;

                if (is_scalar($id)) {
                    $ids[] = $id;
                }
            }
        }

        return $this->normaliseIds($ids);
    }

    /**
     * Ids compared as strings, deduplicated, reindexed. Humanity's ids arrive
     * as both 42 and '42' depending on the endpoint, and array_diff on mixed
     * types is how a delta quietly gets an employee wrong.
     *
     * @param  array<int,mixed>  $ids
     * @return array<int,string>
     */
    private function normaliseIds(array $ids): array
    {
        $strings = [];

        foreach ($ids as $id) {
            if (! is_scalar($id)) {
                continue;
            }

            $value = trim((string) $id);

            if ($value !== '') {
                $strings[] = $value;
            }
        }

        return array_values(array_unique($strings));
    }

    private function warnStaffingReadFailed(int|string $shiftId, IntegrationException $e): void
    {
        try {
            Log::warning('humanity.staffing.read_failed', $e->context() + [
                'humanity_shift_id' => (string) $shiftId,
                'effect' => 'additions applied, removals skipped',
            ]);
        } catch (Throwable) {
            // Never worth failing a publish over.
        }
    }
}
