<?php

namespace App\Services\Scheduling;

use App\Exceptions\SchedulingException;
use App\Models\WorkSegment;
use Illuminate\Support\Collection;

/**
 * "They cannot close the day until they approve all the hours of the day."
 *
 * TWO blocker categories, kept apart on purpose:
 *
 *   unapproved  — finished hours nobody has signed off. There is something to
 *                 approve; go approve it.
 *   open_punch  — still clocked in. There are no hours to approve at all, so
 *                 folding these into "unapproved" would let a manager clear the
 *                 list without ever seeing that somebody's time is missing.
 *
 * Both name the employees, because "3 unapproved segments" is not something a
 * manager can act on and "Dana Okafor, still clocked in since 13:02" is.
 *
 * There is no day_closes table, and emitting the close through the outbox
 * belongs to whoever owns the outbox. close() validates and reports, and
 * PERSISTS NOTHING — a closed day leaves no trace in the database at all, so it
 * neither locks the date against later edits nor records that anyone closed it.
 */
class DayCloseService
{
    /**
     * @return array{
     *     store_id: int,
     *     business_date: string,
     *     closable: bool,
     *     blockers: array<int, array<string, mixed>>
     * }
     */
    public function check(int $storeId, string $businessDate): array
    {
        // Two queries, each on (store_id, business_date, ...) — the indexes the
        // work_segments migration added for exactly this gate.
        $unapproved = WorkSegment::query()
            ->with('employee')
            ->forBoard($storeId, $businessDate)
            ->unapproved()
            ->get();

        $openPunches = WorkSegment::query()
            ->with('employee')
            ->forBoard($storeId, $businessDate)
            ->openPunches()
            ->get();

        $blockers = [];

        if ($unapproved->isNotEmpty()) {
            $blockers[] = $this->blocker(
                'unapproved',
                $unapproved,
                'hours finished but not approved',
            );
        }

        if ($openPunches->isNotEmpty()) {
            $blockers[] = $this->blocker(
                'open_punch',
                $openPunches,
                'still clocked in, no hours to approve yet',
            );
        }

        return [
            'store_id' => $storeId,
            'business_date' => $businessDate,
            'closable' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array<string, mixed> the check result, plus who closed it and when
     *
     * @throws SchedulingException when the day is not closable
     */
    public function close(int $storeId, string $businessDate, ?int $userId = null): array
    {
        $result = $this->check($storeId, $businessDate);

        if (! $result['closable']) {
            throw SchedulingException::dayNotClosable($storeId, $businessDate, $result['blockers']);
        }

        return array_merge($result, [
            'closed_at' => now()->toIso8601String(),
            'closed_by_user_id' => $userId,
        ]);
    }

    /**
     * @param  Collection<int, WorkSegment>  $segments
     * @return array<string, mixed>
     */
    private function blocker(string $type, Collection $segments, string $why): array
    {
        $rows = $segments->map(fn (WorkSegment $segment): array => [
            'work_segment_id' => $segment->id,
            'employee_id' => $segment->employee_id,
            'employee_name' => $segment->employee?->fullName(),
            'time_in' => $segment->time_in?->toIso8601String(),
            'time_out' => $segment->time_out?->toIso8601String(),
            'hours' => $segment->hours === null ? null : (float) $segment->hours,
        ])->values();

        $names = $rows->pluck('employee_name')->filter()->unique()->values();

        return [
            'type' => $type,
            'count' => $rows->count(),
            'employees' => $names->all(),
            'segments' => $rows->all(),
            'message' => sprintf(
                '%d segment(s) %s: %s.',
                $rows->count(),
                $why,
                $names->isEmpty() ? 'unnamed employees' : $names->join(', '),
            ),
        ];
    }
}
