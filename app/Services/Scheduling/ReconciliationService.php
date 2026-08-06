<?php

namespace App\Services\Scheduling;

use App\Enums\MatchSource;
use App\Models\Shift;
use App\Models\WorkSegment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Ties an actual punch to the shift it was probably for.
 *
 * Not a 1:1 relation in either direction, and the matcher must not pretend it
 * is: one shift interrupted by a lunch break produces several segments, and a
 * split shift is several shifts each collecting their own. So a shift is never
 * "taken" — the best candidate wins however many segments already point at it.
 *
 * match_source = manual is a human's answer and is never overwritten. That is
 * the only thing here that is not a guess.
 */
class ReconciliationService
{
    /**
     * How far outside the planned block a punch may fall and still be counted
     * as that shift. Early arrivals and late clock-outs are the norm; two hours
     * adrift is a different shift.
     */
    public const TOLERANCE_MINUTES = 90;

    public function match(WorkSegment $segment): ?Shift
    {
        if ($segment->match_source === MatchSource::Manual) {
            return $segment->shift_id === null
                ? null
                : ($segment->shift ?? Shift::query()->find($segment->shift_id));
        }

        $shift = $this->bestCandidate($segment);

        $segment->shift_id = $shift?->id;
        $segment->match_source = $shift === null ? MatchSource::Unmatched : MatchSource::Auto;

        if ($segment->isDirty(['shift_id', 'match_source'])) {
            $segment->save();
        }

        return $shift;
    }

    private function bestCandidate(WorkSegment $segment): ?Shift
    {
        if ($segment->employee_id === null || $segment->time_in === null) {
            return null;
        }

        $punchStart = CarbonImmutable::instance($segment->time_in);

        // An open punch has no end yet, so it is a zero-length interval: it can
        // overlap nothing, and the tolerance on time_in is all there is to go on.
        $punchEnd = $segment->time_out !== null
            ? CarbonImmutable::instance($segment->time_out)
            : $punchStart;

        // Same employee, same business_date — the (employee_id, business_date)
        // index — narrowed to blocks within tolerance of the punch.
        $candidates = Shift::query()
            ->where('employee_id', $segment->employee_id)
            ->where('business_date', $this->dateString($segment->business_date))
            ->where('start_at', '<', $punchEnd->addMinutes(self::TOLERANCE_MINUTES))
            ->where('end_at', '>', $punchStart->subMinutes(self::TOLERANCE_MINUTES))
            ->get();

        $best = null;
        $bestScore = null;

        foreach ($candidates as $shift) {
            $score = [
                $this->overlapMinutes($shift, $punchStart, $punchEnd),
                (int) ($shift->store_id === $segment->store_id),
                -$this->startDriftMinutes($shift, $punchStart),
            ];

            if ($bestScore === null || $score > $bestScore) {
                $bestScore = $score;
                $best = $shift;
            }
        }

        return $best;
    }

    private function overlapMinutes(Shift $shift, CarbonImmutable $punchStart, CarbonImmutable $punchEnd): float
    {
        $start = $punchStart->greaterThan($shift->start_at)
            ? $punchStart
            : CarbonImmutable::instance($shift->start_at);

        $end = $punchEnd->lessThan($shift->end_at)
            ? $punchEnd
            : CarbonImmutable::instance($shift->end_at);

        return $end->greaterThan($start) ? abs($start->diffInMinutes($end)) : 0.0;
    }

    private function startDriftMinutes(Shift $shift, CarbonImmutable $punchStart): float
    {
        return abs($punchStart->diffInMinutes($shift->start_at));
    }

    private function dateString(CarbonInterface|string|null $date): string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;
    }
}
