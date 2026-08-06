<?php

namespace App\Support;

use App\Models\StoreSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Throwable;

/**
 * The timezone seam.
 *
 * Every UTC instant in this service becomes a store-local wall clock here and
 * nowhere else. Calling Carbon::parse() with an implicit zone somewhere in a
 * service is how an overnight shift ends up on the wrong day in one code path
 * and the right one in another, so all of that lives behind these six methods.
 *
 * The zone comes from store_settings, which is SCHEDULING-OWNED — auth's store
 * events do not carry a timezone, so it cannot live on the stores projection.
 * A store with no settings row falls back to DEFAULT_TIMEZONE rather than
 * failing: a store that exists must still be schedulable on day one.
 */
class BusinessDay
{
    public const DEFAULT_TIMEZONE = 'America/New_York';

    /**
     * Per-process cache of store_id => timezone. Static because several
     * services each hold their own BusinessDay and would otherwise re-read the
     * same row for every shift on a board.
     *
     * @var array<int|string, string>
     */
    private static array $timezones = [];

    public function timezoneFor(?int $storeId): string
    {
        $key = $storeId ?? 'default';

        if (isset(self::$timezones[$key])) {
            return self::$timezones[$key];
        }

        $timezone = $storeId === null
            ? null
            : StoreSetting::query()->where('store_id', $storeId)->value('timezone');

        return self::$timezones[$key] = $this->normalise($timezone);
    }

    /**
     * A UTC instant as the store sees it on the wall.
     *
     * A bare string is read as UTC: that is what the database holds and what
     * config('app.timezone') is set to.
     */
    public function toLocal(?int $storeId, CarbonInterface|string $instantUtc): CarbonImmutable
    {
        $instant = $instantUtc instanceof CarbonInterface
            ? CarbonImmutable::instance($instantUtc)
            : CarbonImmutable::parse($instantUtc, 'UTC');

        return $instant->setTimezone($this->timezoneFor($storeId));
    }

    /**
     * A store-local wall clock as a UTC instant.
     *
     * A CarbonInterface argument is read for its WALL CLOCK, not its instant:
     * "2026-08-10 17:00" means five in the afternoon at that store whatever
     * zone the object happens to carry.
     */
    public function toUtc(?int $storeId, CarbonInterface|string $local): CarbonImmutable
    {
        $wallClock = $local instanceof CarbonInterface
            ? $local->format('Y-m-d H:i:s')
            : $local;

        return CarbonImmutable::parse($wallClock, $this->timezoneFor($storeId))->utc();
    }

    /** A local date plus a local time, e.g. ('2026-08-10', '17:00'), as UTC. */
    public function combine(?int $storeId, string $localDate, string $localTime): CarbonImmutable
    {
        return $this->toUtc($storeId, trim($localDate.' '.$localTime));
    }

    /**
     * The business_date an instant belongs to: the store-local calendar day it
     * falls on. An overnight shift belongs to the day it STARTED, which is why
     * callers pass start_at here and never end_at.
     */
    public function businessDate(?int $storeId, CarbonInterface|string $instantUtc): string
    {
        return $this->toLocal($storeId, $instantUtc)->toDateString();
    }

    /**
     * The half-open UTC window [start, end) covering one local business day.
     * Built by adding a calendar day to local midnight rather than 24 hours, so
     * a DST boundary gives a 23- or 25-hour window instead of a wrong one.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function dayWindowUtc(?int $storeId, string $businessDate): array
    {
        $localMidnight = CarbonImmutable::parse($businessDate, $this->timezoneFor($storeId))->startOfDay();

        return [$localMidnight->utc(), $localMidnight->addDay()->utc()];
    }

    /** Tests and long-running workers: drop the cached zones. */
    public static function flushTimezoneCache(): void
    {
        self::$timezones = [];
    }

    /** An unusable zone in the settings row must not take the schedule down. */
    private function normalise(?string $timezone): string
    {
        if ($timezone === null || trim($timezone) === '') {
            return self::DEFAULT_TIMEZONE;
        }

        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            return self::DEFAULT_TIMEZONE;
        }

        return $timezone;
    }
}
