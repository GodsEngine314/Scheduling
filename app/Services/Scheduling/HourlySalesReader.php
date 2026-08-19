<?php

namespace App\Services\Scheduling;

use App\Exceptions\IntegrationException;
use App\Models\Store;
use App\Support\BusinessDay;
use App\Support\Integrations\LcData\LcDataClient;
use App\Support\Integrations\LcData\StubHourlySales;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a store actually took, hour by hour, for the days on the board.
 *
 * THE POINT OF PUTTING SALES ON A SCHEDULE, said once: a week grid shows who is
 * working and what it costs, and until now it showed nothing at all about what
 * the store was doing while they worked. Two people on at 11:00 is either right
 * or badly wrong depending on whether 11:00 is a $90 hour or a $600 one, and
 * that number lived in a different system that nobody had open at the time.
 *
 * READ, NEVER STORED. Sales are LC_PIZZA_DATA's fact. Nothing here writes them
 * to scheduling's database — the only persistence is a cache entry with a TTL
 * on it, so the worst a stale read can do is expire.
 *
 * DEGRADING IS THE NORMAL CASE, NOT THE EXCEPTION. This sits on the render path
 * of a page whose actual job is scheduling people, and it depends on another
 * service being up, a session token being valid and a store number matching
 * something upstream. Any of those can be false on a perfectly ordinary
 * Tuesday. So every failure here returns `available => false` with a sentence
 * saying why, and the board draws without a sales column. A schedule that
 * cannot be read because the warehouse is down would be a much worse bug than
 * the one this feature fixes.
 */
class HourlySalesReader
{
    public function __construct(
        private readonly LcDataClient $client,
        private readonly BusinessDay $businessDay,
        private readonly StubHourlySales $stub,
    ) {}

    /**
     * The window and figures for every date from $from to $to inclusive.
     *
     * @return array{
     *     available: bool,
     *     message: ?string,
     *     stubbed: bool,
     *     hours: array<int,int>,
     *     days: array<string, array{by_hour: array<int,float>, window_total: float, day_total: float, peak: float}>,
     * }
     */
    public function forRange(int $storeId, string $from, string $to): array
    {
        $hours = $this->window();

        if (! (bool) config('lc_data.enabled', true)) {
            return $this->unavailable($hours, null);
        }

        $storeNumber = $this->storeNumber($storeId);

        if ($storeNumber === null) {
            return $this->unavailable($hours, 'This store has no store number, so its sales cannot be looked up.');
        }

        $dates = $this->dates($from, $to);

        // The local preview. Short-circuits the client entirely rather than
        // faking a response into it — there is no HTTP to fake when there is no
        // warehouse, and a stub that pretended otherwise would be one more
        // thing that behaves differently from production without saying so.
        if ($this->stubbing()) {
            $generated = $this->stub->forRange($storeNumber, $from, $to);
            $days = [];

            foreach ($dates as $date) {
                $days[$date] = $this->shape($generated[$date] ?? ['by_hour' => [], 'day_total' => 0.0], $hours);
            }

            return [
                'available' => true,
                'message' => null,
                'stubbed' => true,
                'hours' => $hours,
                'days' => $days,
            ];
        }

        try {
            $raw = $this->read($storeId, $storeNumber, $dates);
        } catch (Throwable $e) {
            // WARNING, not error, and no re-throw. The board is still correct
            // without this; what is worth a log line is that the warehouse
            // could not be reached, which the integration log already records
            // in more detail. This one names the store and the range so the two
            // can be lined up.
            Log::warning('lc_data.hourly_sales.unavailable', [
                'store_id' => $storeId,
                'store_number' => $storeNumber,
                'from' => $from,
                'to' => $to,
                'reason' => $e->getMessage(),
            ]);

            return $this->unavailable($hours, $this->explain($e));
        }

        $days = [];

        foreach ($dates as $date) {
            $days[$date] = $this->shape($raw[$date] ?? ['by_hour' => [], 'day_total' => 0.0], $hours);
        }

        return [
            'available' => true,
            'message' => null,
            'stubbed' => false,
            'hours' => $hours,
            'days' => $days,
        ];
    }

    /**
     * The hours the column shows, low to high.
     *
     * A window that wraps past midnight is NOT supported and is clamped rather
     * than wrapped: 22 → 2 would have to mean two different business dates, and
     * a column headed one date cannot show another date's hours without lying.
     *
     * @return array<int,int>
     */
    public function window(): array
    {
        $from = max(0, min(23, (int) config('lc_data.window.from_hour', 10)));
        $to = max(0, min(23, (int) config('lc_data.window.to_hour', 23)));

        return $to < $from ? [$from] : range($from, $to);
    }

    /**
     * Cache-first, then ONE request for whatever is left.
     *
     * Paging through weeks is the common way this page is used, and most of the
     * days on any week but the current one never change again. Reading the
     * cache per day rather than per range means last week's grid costs nothing
     * the second time somebody looks at it, while a week straddling today still
     * only ever costs one round trip.
     *
     * The request spans min(miss) → max(miss) rather than being split per gap.
     * A range query on an indexed date column does not care about the holes,
     * and one call beats three.
     *
     * @param  array<int,string>  $dates
     * @return array<string, array{by_hour: array<string,float>, day_total: float}>
     */
    private function read(int $storeId, string $storeNumber, array $dates): array
    {
        $today = $this->businessDay->toLocal($storeId, now())->toDateString();

        $found = [];
        $missing = [];

        foreach ($dates as $date) {
            $cached = $this->ttlFor($date, $today) > 0
                ? Cache::get($this->cacheKey($storeNumber, $date))
                : null;

            if (is_array($cached)) {
                $found[$date] = $cached;

                continue;
            }

            $missing[] = $date;
        }

        if ($missing === []) {
            return $found;
        }

        $fetched = $this->client->hourlySales($storeNumber, $missing[0], $missing[count($missing) - 1]);

        foreach ($missing as $date) {
            // Absent from the response means the store took nothing that day,
            // and that is a real answer worth caching. Re-asking for it every
            // render would put the heaviest load on the quietest days.
            $day = $fetched[$date] ?? ['by_hour' => [], 'day_total' => 0.0];

            $found[$date] = $day;

            $ttl = $this->ttlFor($date, $today);

            if ($ttl > 0) {
                Cache::put($this->cacheKey($storeNumber, $date), $day, $ttl);
            }
        }

        return $found;
    }

    /**
     * A finished day is cached for hours; today and anything ahead of it for
     * minutes.
     *
     * The comparison is against the STORE's date, not the server's. A board
     * read at 01:00 UTC is still the previous evening in New York, and treating
     * that evening as finished would freeze the numbers while the store was
     * still taking orders.
     */
    private function ttlFor(string $date, string $today): int
    {
        return $date < $today
            ? max(0, (int) config('lc_data.cache.closed_day_ttl', 21600))
            : max(0, (int) config('lc_data.cache.open_day_ttl', 300));
    }

    /**
     * Keyed by STORE NUMBER, not store id.
     *
     * The figures belong to the franchise store the warehouse knows about. If
     * two scheduling stores ever pointed at one store number they would be
     * reading the same sales, and they should share the entry rather than keep
     * two copies that expire at different times.
     */
    private function cacheKey(string $storeNumber, string $date): string
    {
        return "lc_data:hourly_sales:{$storeNumber}:{$date}";
    }

    /**
     * One day's figures, cut down to the window and measured against itself.
     *
     * window_total is summed over the DISPLAYED hours, and day_total is the
     * warehouse's figure for all twenty-four. They differ whenever a store
     * takes money outside the window, and the column shows both so the
     * difference is visible rather than a subtraction that does not work out.
     *
     * @param  array{by_hour: array<string,float>, day_total: float}  $day
     * @param  array<int,int>  $hours
     * @return array{by_hour: array<int,float>, window_total: float, day_total: float, peak: float}
     */
    private function shape(array $day, array $hours): array
    {
        $byHour = [];
        $windowTotal = 0.0;
        $peak = 0.0;

        foreach ($hours as $hour) {
            $amount = round((float) ($day['by_hour'][(string) $hour] ?? 0), 2);

            $byHour[$hour] = $amount;
            $windowTotal += $amount;
            $peak = max($peak, $amount);
        }

        return [
            'by_hour' => $byHour,
            'window_total' => round($windowTotal, 2),
            'day_total' => round((float) ($day['day_total'] ?? 0), 2),
            // The busiest hour ON SCREEN, which is what the bars in the column
            // are drawn relative to. Scaling to the whole day instead would
            // flatten every bar whenever the real peak fell outside the window.
            'peak' => $peak,
        ];
    }

    /**
     * Whether the column is drawn locally instead of read.
     *
     * THE ENVIRONMENT CHECK IS HERE, IN CODE, not in the config file — same
     * reasoning DevBypass gives for its own: a config value travels in a .env,
     * and a .env is the thing people copy to a server by accident. Setting
     * LC_DATA_STUB on a production box does nothing at all.
     */
    private function stubbing(): bool
    {
        return (bool) config('lc_data.stub', false) && app()->environment('local', 'testing');
    }

    /**
     * What to tell the person looking at the grid.
     *
     * A CONFIGURATION FAULT IS ABOUT OUR OWN SETUP — a missing env line, a
     * session that cannot be used to read sales — so its message is actionable,
     * names no vendor data, and is shown as it is. Collapsing those into the
     * generic sentence sent people to check a warehouse that was perfectly
     * healthy while the real answer was a line in their .env.
     *
     * Everything else is a network or vendor failure whose message carries an
     * endpoint, a status and a correlation id. That belongs in the log, which
     * already has it, and not on a scheduling screen.
     */
    private function explain(Throwable $e): string
    {
        if ($e instanceof IntegrationException && $e->method === 'CONFIG') {
            return 'Sales are not readable because '.$e->getMessage();
        }

        return 'Sales could not be read from LC_PIZZA_DATA just now.';
    }

    /** @return array<int,string> */
    private function dates(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        if ($end->lessThan($start)) {
            return [$start->toDateString()];
        }

        $dates = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    private function storeNumber(int $storeId): ?string
    {
        $number = trim((string) (Store::query()->find($storeId)?->store_number ?? ''));

        return $number === '' ? null : $number;
    }

    /**
     * @param  array<int,int>  $hours
     * @return array{available: bool, message: ?string, stubbed: bool, hours: array<int,int>, days: array<string,mixed>}
     */
    private function unavailable(array $hours, ?string $message): array
    {
        return [
            'available' => false,
            'message' => $message,
            'stubbed' => false,
            'hours' => $hours,
            'days' => [],
        ];
    }
}
