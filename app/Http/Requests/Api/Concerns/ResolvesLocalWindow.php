<?php

namespace App\Http\Requests\Api\Concerns;

use Carbon\CarbonImmutable;

/**
 * Turns "this local date, 22:00 to 06:00" into the wall-clock datetime strings
 * the scheduling services accept as *_local.
 *
 * AN END BEFORE A START IS NOT AN ERROR. It is how a caller says the block
 * crosses midnight, and rejecting it would reject every overnight shift and
 * every closing punch. The end rolls onto the next calendar day; the start's
 * date is left alone, because business_date is always the day the block
 * STARTED — the same convention BusinessDay and the availability windows use.
 *
 * NO TIMEZONE IS APPLIED HERE, on purpose. These are wall-clock strings, and
 * BusinessDay is the one place in this service allowed to decide what a wall
 * clock means in UTC. The Carbon objects below exist only to do calendar
 * arithmetic on the date part; adding a day to a formatted string cannot shift
 * an hour the way adding 24 hours to an instant can.
 */
trait ResolvesLocalWindow
{
    /**
     * @return array{0: string, 1: string} local start, local end, both 'Y-m-d H:i'
     */
    protected function localWindow(string $localDate, string $startTime, string $endTime): array
    {
        $start = CarbonImmutable::parse($localDate.' '.$startTime);
        $end = CarbonImmutable::parse($localDate.' '.$endTime);

        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        return [$start->format('Y-m-d H:i'), $end->format('Y-m-d H:i')];
    }

    /** One local date plus one H:i clock time, as a wall-clock datetime string. */
    protected function localMoment(string $localDate, string $time): string
    {
        return CarbonImmutable::parse($localDate.' '.$time)->format('Y-m-d H:i');
    }

    /** The calendar day after a Y-m-d, for the far side of a midnight crossing. */
    protected function nextLocalDate(string $localDate): string
    {
        return CarbonImmutable::parse($localDate)->addDay()->toDateString();
    }
}
