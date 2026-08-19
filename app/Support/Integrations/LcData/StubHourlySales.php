<?php

namespace App\Support\Integrations\LcData;

use Carbon\CarbonImmutable;

/**
 * The warehouse's hourly sales, generated locally instead of fetched.
 *
 * LOCAL DEVELOPMENT ONLY. The environment check is in HourlySalesReader, which
 * is the only thing that may construct this; nothing here enforces it, so do
 * not call it from anywhere else.
 *
 * WHY IT EXISTS. Reading real hourly sales means a running LC_PIZZA_DATA, which
 * means MySQL, three warehouse databases, a set of monthly-partitioned
 * migrations and an auth service that can verify a forwarded token. That is a
 * great deal of estate to stand up in order to look at fourteen numbers, and
 * without it the column silently degrades and the feature cannot be worked on
 * at all.
 *
 * IT REPRODUCES HourlyStoreSalesSeeder'S ARITHMETIC, DELIBERATELY. Same curve,
 * same factors, same crc32 noise, same late-close stores. What you see here is
 * therefore what you would see once that seeder has run against a real
 * warehouse — the local preview is faithful rather than merely plausible.
 *
 * WHICH MEANS IT IS DUPLICATED ACROSS TWO REPOSITORIES, unavoidably: separate
 * services, separate databases, no shared package. If the seeder's numbers
 * change and these do not, the local preview quietly stops matching the thing
 * it is previewing. That is the cost of being able to develop this at all, and
 * it is why the grid labels the column SAMPLE rather than trusting anyone to
 * remember which mode they are in.
 */
class StubHourlySales
{
    /** @see \Database\Seeders\HourlyStoreSalesSeeder::DEMAND_CURVE */
    private const DEMAND_CURVE = [
        10 => 0.06, 11 => 0.42, 12 => 0.71, 13 => 0.38, 14 => 0.16, 15 => 0.14,
        16 => 0.29, 17 => 0.83, 18 => 1.00, 19 => 0.76, 20 => 0.51, 21 => 0.33,
        22 => 0.17, 23 => 0.07,
    ];

    /** Monday (1) through Sunday (7), ISO, matching Carbon's dayOfWeekIso. */
    private const WEEKDAY_FACTOR = [
        1 => 0.78, 2 => 0.82, 3 => 0.88, 4 => 0.95, 5 => 1.34, 6 => 1.41, 7 => 1.02,
    ];

    /** Stores that trade past midnight, by store_number suffix. */
    private const LATE_CLOSE_SUFFIXES = ['00001', '00012', '00025', '00038', '00044'];

    private const OPEN_HOUR = 10;

    private const CLOSE_HOUR = 23;

    /**
     * The same shape LcDataClient::hourlySales() returns, so the reader cannot
     * tell the two apart and there is no second code path behind this.
     *
     * NOTHING IN THE FUTURE. A store cannot have taken money tomorrow, and a
     * column of invented figures on next week's grid would be actively
     * misleading to somebody building that week's rota — which is precisely
     * when they would be looking at it.
     *
     * @return array<string, array{by_hour: array<string,float>, day_total: float}>
     */
    public function forRange(string $storeNumber, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        $today = CarbonImmutable::now()->startOfDay();

        $storeFactor = 0.55 + ($this->noise($storeNumber, 'store', 0) * 1.20);

        $days = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $date = $day->toDateString();

            if ($day->greaterThan($today)) {
                $days[$date] = ['by_hour' => [], 'day_total' => 0.0];

                continue;
            }

            $dayFactor = (self::WEEKDAY_FACTOR[$day->dayOfWeekIso] ?? 1.0)
                * (0.88 + ($this->noise($storeNumber, $date, 0) * 0.24));

            $byHour = [];
            $total = 0.0;

            foreach ($this->hoursFor($storeNumber) as $hour) {
                $weight = self::DEMAND_CURVE[$hour] ?? 0.04;
                $jitter = 0.82 + ($this->noise($storeNumber, $date, $hour) * 0.36);

                $amount = round(620.0 * $weight * $storeFactor * $dayFactor * $jitter, 2);

                if ($amount <= 0) {
                    continue;
                }

                $byHour[(string) $hour] = $amount;
                $total += $amount;
            }

            $days[$date] = ['by_hour' => $byHour, 'day_total' => round($total, 2)];
        }

        return $days;
    }

    /**
     * The hours this store trades.
     *
     * The late-close list is keyed by the SUFFIX of a grouped store number
     * (03795-00001). A store number in any other shape simply never matches,
     * which is the right outcome — it keeps ordinary trading hours rather than
     * guessing.
     *
     * @return array<int, int>
     */
    private function hoursFor(string $storeNumber): array
    {
        $hours = range(self::OPEN_HOUR, self::CLOSE_HOUR);

        $suffix = str_contains($storeNumber, '-')
            ? substr($storeNumber, strrpos($storeNumber, '-') + 1)
            : '';

        if (in_array($suffix, self::LATE_CLOSE_SUFFIXES, true)) {
            $hours[] = 0;
            $hours[] = 1;
        }

        return $hours;
    }

    /** @see \Database\Seeders\HourlyStoreSalesSeeder::noise() */
    private function noise(string $store, string $salt, int $hour): float
    {
        return (crc32($store.'|'.$salt.'|'.$hour) % 10000) / 10000;
    }
}
