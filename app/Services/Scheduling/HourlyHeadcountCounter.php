<?php

namespace App\Services\Scheduling;

use App\Models\Shift;
use App\Models\WorkSegment;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * HOW MANY PEOPLE ARE IN THE STORE IN EACH HOUR — the other half of the sales
 * column.
 *
 * The sales row answers "what did this hour take". On its own that is only half
 * a question: $600 at 17:00 is fine with four people on the floor and a disaster
 * with one, and until now the grid made the reader count chips in fourteen
 * different cells to work out which it was. This counts them.
 *
 * IT COUNTS PEOPLE, NOT SHIFTS. Somebody working a split shift that touches
 * 17:00 twice is one person in the store at 17:00, so every hour holds a SET of
 * employee ids rather than a tally of rows. Open shifts are the exception and
 * are counted as rows, because an unfilled shift is not a person — see below.
 *
 * IT BUCKETS BY WALL CLOCK, NOT BY business_date. A shift running 21:00 → 01:00
 * on Tuesday puts bodies in the store at Tuesday 21:00–23:59 AND at Wednesday
 * 00:00–00:59, and the Wednesday column is where the second part belongs. Going
 * by business_date instead would draw somebody standing in the store at 01:00 on
 * the Tuesday, a shift that had not started yet.
 *
 * The consequence, stated because it is a real gap and not a rounding error:
 * work that STARTED the day before the grid and ran past midnight into the first
 * column's early hours is not counted, because those rows were never fetched.
 * The default window (10AM → midnight) ends where that spillover begins, so it
 * cannot show; widen `lc_data.window` past midnight and the first column of a
 * week will under-count its small hours.
 *
 * NOTHING IS QUERIED HERE. The caller already holds the week's shifts and
 * punches for the grid itself, and re-fetching them to count them would be a
 * second set of queries — and a second chance for the two to disagree about
 * what is on screen. Same reasoning as LaborCostEstimator::estimateFor().
 */
class HourlyHeadcountCounter
{
    /**
     * The most hours one row is allowed to contribute.
     *
     * A ceiling, not a rule: every honest shift and punch is well under a day,
     * and this only exists so a single corrupt instant — a clock-out in the
     * wrong year, say — cannot spin the loop below for a million iterations on
     * the render path of a page somebody is waiting for.
     */
    private const MAX_HOURS = 48;

    public function __construct(private readonly BusinessDay $businessDay) {}

    /**
     * Headcount per hour for every day on the board.
     *
     * $hours comes from the caller rather than from config so that this row and
     * the sales row above it can never disagree about which hours they are
     * showing — they are one list, read twice.
     *
     * @param  array<int,string>  $days  the business dates on screen, in order
     * @param  array<int,int>  $hours  the displayed window, low to high
     * @param  iterable<int,Shift>  $shifts  planned shifts for those days
     * @param  iterable<int,WorkSegment>  $segments  punches for those days
     * @return array{
     *     hours: array<int,int>,
     *     days: array<string, array{
     *         planned: array<int,int>,
     *         open: array<int,int>,
     *         actual: array<int,int>,
     *         planned_peak: int,
     *         open_peak: int,
     *         actual_peak: int,
     *         still_in: int,
     *         unknown_out: int,
     *     }>,
     *     peak: int,
     *     planned_peak: int,
     *     actual_peak: int,
     *     today: string,
     *     now_hour: int,
     * }
     */
    public function forRange(int $storeId, array $days, array $hours, iterable $shifts, iterable $segments): array
    {
        $now = $this->businessDay->toLocal($storeId, now());
        $today = $now->toDateString();

        // Every hour of every day exists up front, zeroed. An hour nobody was
        // rostered for is a REAL and important answer — "no cover at 10AM" is
        // the whole reason to put this row on the grid — and a missing key
        // would render as a gap, which reads as missing data instead.
        $planned = [];
        $open = [];
        $actual = [];

        foreach ($days as $date) {
            foreach ($hours as $hour) {
                $planned[$date][$hour] = [];
                $open[$date][$hour] = 0;
                $actual[$date][$hour] = [];
            }
        }

        $stillIn = array_fill_keys($days, 0);
        $unknownOut = array_fill_keys($days, 0);

        foreach ($shifts as $shift) {
            if ($shift->start_at === null || $shift->end_at === null) {
                continue;
            }

            $buckets = $this->buckets(
                $this->businessDay->toLocal($storeId, $shift->start_at),
                $this->businessDay->toLocal($storeId, $shift->end_at),
            );

            foreach ($buckets as [$date, $hour]) {
                if (! isset($planned[$date][$hour])) {
                    continue;
                }

                // AN OPEN SHIFT IS NOT A HEAD. Nobody is standing in the store
                // because a shift has no name on it, so it cannot be counted
                // alongside the people who are — a planned "3" that quietly
                // included an unfilled slot would be a rota reading as covered
                // while one of the three does not exist. Counted separately,
                // shown separately, as rows rather than as people: two unfilled
                // shifts in one hour are two bodies still to find.
                if ($shift->employee_id === null) {
                    $open[$date][$hour]++;

                    continue;
                }

                $planned[$date][$hour][(int) $shift->employee_id] = true;
            }
        }

        foreach ($segments as $segment) {
            if ($segment->time_in === null || $segment->employee_id === null) {
                continue;
            }

            $in = $this->businessDay->toLocal($storeId, $segment->time_in);
            $date = $this->dateString($segment->business_date);

            /**
             * WHERE A PUNCH WITH NO CLOCK-OUT ENDS, which is the only hard case
             * in this class. time_out IS NULL is two completely different facts
             * and only the store's date tells them apart — the same distinction
             * _segment-chip draws in colour:
             *
             *   still in    clocked in today and not out yet. They ARE in the
             *               store, from their clock-in until NOW, and counting
             *               that is the most useful thing this row does all
             *               day. Not one minute further: the rest of their
             *               shift has not happened.
             *
             *   no clock-out  the day has ended and nobody closed it. There is
             *               no honest end to use. Inventing one would invent
             *               coverage, so only the hour they clocked in is
             *               counted — that hour they were certainly here — and
             *               the day carries a count of how many punches are in
             *               this state so the grid can say the later hours are
             *               under-counted rather than quietly under-count them.
             */
            if ($segment->time_out !== null) {
                $out = $this->businessDay->toLocal($storeId, $segment->time_out);
            } elseif ($date >= $today) {
                if (isset($stillIn[$date])) {
                    $stillIn[$date]++;
                }

                $out = $now->greaterThan($in) ? $now : $in;
            } else {
                if (isset($unknownOut[$date])) {
                    $unknownOut[$date]++;
                }

                $out = $in->startOfHour()->addHour();
            }

            foreach ($this->buckets($in, $out) as [$bucketDate, $hour]) {
                if (! isset($actual[$bucketDate][$hour])) {
                    continue;
                }

                $actual[$bucketDate][$hour][(int) $segment->employee_id] = true;
            }
        }

        $byDay = [];
        $weekPlannedPeak = 0;
        $weekActualPeak = 0;
        $weekPeak = 0;

        foreach ($days as $date) {
            $plannedCounts = array_map('count', $planned[$date] ?? []);
            $actualCounts = array_map('count', $actual[$date] ?? []);
            $openCounts = $open[$date] ?? [];

            $plannedPeak = $plannedCounts === [] ? 0 : max($plannedCounts);
            $actualPeak = $actualCounts === [] ? 0 : max($actualCounts);
            $openPeak = $openCounts === [] ? 0 : max($openCounts);

            $byDay[$date] = [
                'planned' => $plannedCounts,
                'open' => $openCounts,
                'actual' => $actualCounts,
                'planned_peak' => $plannedPeak,
                'open_peak' => $openPeak,
                'actual_peak' => $actualPeak,
                'still_in' => $stillIn[$date] ?? 0,
                'unknown_out' => $unknownOut[$date] ?? 0,
            ];

            $weekPlannedPeak = max($weekPlannedPeak, $plannedPeak);
            $weekActualPeak = max($weekActualPeak, $actualPeak);
            $weekPeak = max($weekPeak, $plannedPeak, $actualPeak, $openPeak);
        }

        return [
            'hours' => array_values($hours),
            'days' => $byDay,
            /**
             * WHERE "NOW" IS, so the grid can tell an hour that came up short
             * from one that has not happened.
             *
             * Both look identical in the numbers — two planned, nobody clocked
             * in — and only one of them is a problem. Flagging next Thursday's
             * evening as understaffed because nobody has worked it yet would
             * paint half of every forward-looking rota amber, which is the
             * fastest way to teach somebody to ignore the colour.
             *
             * The STORE's clock, not the server's: a board read at 01:00 UTC is
             * still the previous evening in New York.
             */
            'today' => $today,
            'now_hour' => (int) $now->format('G'),
            // Whether there is anything to show at all. The row is drawn for the
            // sales in it OR for the people in it, and a week with neither —
            // an empty rota with the warehouse switched off — should not put
            // fourteen rows of nothing above the grid.
            'peak' => $weekPeak,
            'planned_peak' => $weekPlannedPeak,
            'actual_peak' => $weekActualPeak,
        ];
    }

    /**
     * The (date, hour) buckets a store-local interval touches, half-open on the
     * end: 17:00–21:00 is in the store for 17, 18, 19 and 20, and not for 21.
     *
     * Walked an hour at a time rather than computed, because an hour of wall
     * clock is not always an hour long. Adding real time and re-reading the
     * local hour each step gives the right answer on both DST boundaries: the
     * spring-forward day simply has no 2AM bucket, and the autumn one writes
     * its repeated hour twice — where the employee-id set absorbs it.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    private function buckets(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $buckets = [];
        $cursor = $start->startOfHour();

        for ($i = 0; $i < self::MAX_HOURS && $cursor->lessThan($end); $i++) {
            $buckets[] = [$cursor->toDateString(), (int) $cursor->format('G')];
            $cursor = $cursor->addHour();
        }

        // A zero-length interval is still somebody who was here: a punch
        // clocked in and out inside the same minute has no hours, and dropping
        // it would show an empty store that had a person in it.
        if ($buckets === []) {
            $buckets[] = [$start->toDateString(), (int) $start->format('G')];
        }

        return $buckets;
    }

    private function dateString(mixed $date): string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;
    }
}
