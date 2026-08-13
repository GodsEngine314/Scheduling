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
 * EVERY PATH AND FIELD NAME IN THIS CLASS IS A GUESS: the source document's
 * field tables are images that could not be read. The staffing rules below are
 * the exception — those come from the prose and are the whole reason this
 * class exists rather than a handful of Http::post calls.
 */
class HumanityClient extends AbstractApiClient
{
    /** GUESS: resource path. */
    private const SHIFTS_PATH = '/shifts';

    /** What deleteShift will accept. 'all' wipes occurrences already in the past. */
    private const DELETE_RULES = ['following', 'all'];

    protected function integration(): string
    {
        return 'humanity';
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
     * @param  array<string,mixed>  $shift
     * @return array<mixed>
     */
    public function createShift(array $shift): array
    {
        return $this->post(self::SHIFTS_PATH, $this->guardStaffingKeys($shift));
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

        // GUESS: the parameter name for the delete rule.
        return $this->delete(self::SHIFTS_PATH.'/'.rawurlencode((string) $id), ['rule' => $rule]);
    }

    /**
     * Who Humanity currently has on this shift.
     *
     * @return array<int,string> employee ids as strings
     */
    public function shiftStaffing(int|string $id): array
    {
        $response = $this->get(self::SHIFTS_PATH.'/'.rawurlencode((string) $id));

        return $this->extractEmployeeIds($response);
    }

    /**
     * Apply a staffing change.
     *
     * add/remove, never employee_id. The document notes that employee_id is
     * only honoured alongside copy_to — set on its own it is accepted and
     * ignored, which means a shift that looks reassigned here is still
     * rostered to the old employee there.
     *
     * @param  array<int,int|string>  $add
     * @param  array<int,int|string>  $remove
     * @return array<mixed>
     */
    public function updateShiftStaffing(int|string $id, array $add, array $remove): array
    {
        $body = array_filter([
            'add' => $this->normaliseIds($add),
            'remove' => $this->normaliseIds($remove),
        ], static fn (array $ids): bool => $ids !== []);

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
     * Refuse a body that sets employee_id without copy_to — see
     * updateShiftStaffing(). Failing loudly beats a silent no-op that leaves a
     * published shift staffed to the wrong person.
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
     * GUESS, and tolerant on purpose: the roster may arrive as a list of ids
     * or as a list of objects, and the shift itself may or may not be wrapped
     * in a 'data' envelope.
     *
     * @param  array<mixed>  $response
     * @return array<int,string>
     */
    private function extractEmployeeIds(array $response): array
    {
        $shift = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;

        $employees = $shift['employees'] ?? null;

        if (! is_array($employees)) {
            return [];
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
