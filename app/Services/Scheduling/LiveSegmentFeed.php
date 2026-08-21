<?php

namespace App\Services\Scheduling;

use App\Models\WorkSegment;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Keeps the board somebody is LOOKING AT up to date, without a button.
 *
 * WHY THIS EXISTS. Actual hours only arrived when a manager found "Pull the
 * week's actual hours" and pressed it. That is the wrong shape for the thing:
 * a punch is an event at the timeclock, and the board is a window onto it, so
 * the window should keep itself current. Worse, a button that has to be pressed
 * makes an out-of-date grid indistinguishable from a settled one — the numbers
 * look just as authoritative either way.
 *
 * THERE IS NO PUSH FROM TCP. No webhook, no subscription, nothing in the
 * vendor's surface that can tell us a punch happened; GET /worksegments is the
 * whole of it. So "live" here means polled, and the honest question is not
 * whether to poll but how to poll often enough to be useful without asking the
 * vendor thousands of times an hour. Three things make that work:
 *
 *   WATCHED, NOT SWEPT. The refresh is driven by an open board. A store nobody
 *   is looking at costs nothing here; it is covered by the slower estate-wide
 *   updatedOn sweep in routes/console.php.
 *
 *   ONE LOCK PER RANGE. Four managers with nine tabs on the same week are one
 *   request every refresh_seconds, not thirty-six. Whoever takes the lock does
 *   the fetch; everybody else is answered from what it wrote, immediately.
 *
 *   ONE SET OF BOOKS. The render path calls refresh() and the heartbeat calls
 *   poll(), and both consult the same "when was this range last read" entry.
 *   Opening a week therefore costs one vendor call, not the two it cost when the
 *   render kept a session key and the poll a cache key and neither knew about
 *   the other.
 *
 * Synchronous, not queued, throughout. A queue worker that is not running would
 * leave a board silently frozen on yesterday's numbers, and there is nothing to
 * be gained by deferring work that nobody is blocked on: the heartbeat's fetch
 * happens inside a background XHR, where a slow vendor costs a spinner in a
 * status pill rather than a delayed page.
 *
 * The fingerprint is what the browser actually compares. It has to move for
 * every change the grid can show — a new punch, a corrected time, an approval,
 * a deletion — and it must NOT move for anything else, or the page reloads
 * itself for no reason while somebody is reading it.
 */
class LiveSegmentFeed
{
    public function __construct(private readonly WorkSegmentSyncService $sync) {}

    /**
     * One poll: refresh from TCP if the range is stale, then report where the
     * range stands.
     *
     * NOTHING HERE MAY THROW. This is the heartbeat of a screen that is already
     * rendered and already correct as of its last load. A vendor outage has to
     * degrade to "we could not reach TCP" in the status pill, never to a failed
     * request that stops the polling loop.
     *
     * @return array<string,mixed>
     */
    public function poll(int $storeId, string $from, string $to): array
    {
        [$state] = $this->refreshIfStale($storeId, $from, $to, $this->refreshSeconds());

        return $this->report($storeId, $from, $to, $state);
    }

    /**
     * Refresh the range if it is stale, and say what came back.
     *
     * THE RENDER PATH USES THIS. Opening a week still fetches it synchronously —
     * the first paint of a grid has to show the punches it just pulled, not the
     * ones it happened to have — but it now shares one notion of "when was this
     * range last read" with the poll. Before, the two kept separate books: a
     * navigation pulled, and the poll half a second later pulled the same range
     * again because it had never heard of it.
     *
     * Shared across USERS too, not just tabs, which the session key it replaced
     * could not be. The second manager to open Tuesday's week does not pay for a
     * vendor round trip the first one already made.
     *
     * @return array<string,mixed>|null the sync report, or null when the range
     *                                  was already fresh or somebody else holds
     *                                  the lock — in both cases there is nothing
     *                                  for the caller to report
     */
    public function refresh(int $storeId, string $from, string $to): ?array
    {
        /*
         * A LAXER THRESHOLD THAN THE HEARTBEAT'S, and the difference is the
         * point.
         *
         * These two callers want different things. The heartbeat wants the range
         * as current as the vendor allows, and it pays for that in a background
         * XHR nobody is watching. The render wants only "has this range ever
         * been read, recently enough that the first paint is not a lie" — and it
         * pays in latency a person is sitting through.
         *
         * On the heartbeat's own 20 seconds, a manager working down a week of
         * approvals would hit a vendor round trip every third redirect, because
         * every approve redirects back here. At five minutes they hit one when
         * they arrive and then none, while the heartbeat goes on keeping the
         * grid current underneath them.
         */
        [, $result] = $this->refreshIfStale(
            $storeId,
            $from,
            $to,
            max(5, (int) config('tcp.live.render_max_age_seconds', 300)),
        );

        return $result;
    }

    /**
     * What the range looks like now, with no vendor call at all.
     *
     * The initial render uses this so the pill opens with a truthful reading
     * instead of an empty one it has to correct a second later.
     *
     * @return array<string,mixed>
     */
    public function snapshot(int $storeId, string $from, string $to): array
    {
        return $this->report($storeId, $from, $to, $this->state($storeId, $from, $to));
    }

    /**
     * Fetch from TCP when the last successful read of this range is older than
     * refresh_seconds — and only for the one caller that wins the lock.
     *
     * The lock is taken WITHOUT waiting. A poll that loses it is not delayed:
     * it returns the current fingerprint straight away, which is the whole
     * point of separating the two jobs this class does. Somebody else's fetch
     * will be visible to it on its next tick a few seconds later.
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>|null}
     */
    private function refreshIfStale(int $storeId, string $from, string $to, int $maxAgeSeconds): array
    {
        $state = $this->state($storeId, $from, $to);

        if (! $this->isStale($state, $maxAgeSeconds)) {
            return [$state, null];
        }

        $lock = Cache::lock(
            $this->cacheKey($storeId, $from, $to).':lock',
            max(5, (int) config('tcp.live.lock_seconds', 45)),
        );

        if (! $lock->get()) {
            // Somebody else is mid-fetch. Say so, so the pill can show that a
            // check is in flight rather than that the range is stale.
            return [$state + ['checking' => true], null];
        }

        $report = null;

        try {
            $report = $this->sync->syncRange($from, $to, $storeId);

            $state = [
                'synced_at' => time(),
                'fetched' => (int) ($report['fetched'] ?? 0),
                'created' => (int) ($report['created'] ?? 0),
                'updated' => (int) ($report['updated'] ?? 0),
                'held' => (int) ($report['held'] ?? 0),
                'skipped' => $this->summariseSkipped($report['skipped'] ?? []),
                'error' => null,
            ];
        } catch (Throwable $e) {
            // attempted_at, NOT synced_at: a failure must not read as a
            // successful read of the range, but it does have to hold the
            // interval off, or a vendor that is down gets hammered by every
            // poll from every open tab.
            $state = [
                'synced_at' => $state['synced_at'] ?? null,
                'attempted_at' => time(),
                'error' => class_basename($e).': '.$e->getMessage(),
            ] + $state;
        } finally {
            $lock->release();
        }

        Cache::put($this->cacheKey($storeId, $from, $to), $state, now()->addDay());

        return [$state, $report];
    }

    /**
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>
     */
    private function report(int $storeId, string $from, string $to, array $state): array
    {
        $counts = $this->counts($storeId, $from, $to);
        $lastTouch = $state['synced_at'] ?? $state['attempted_at'] ?? null;

        return [
            'store_id' => $storeId,
            'from' => $from,
            'to' => $to,

            // THE ONLY FIELD THE BROWSER COMPARES. Everything else on this
            // payload is for the human reading the pill.
            'fingerprint' => $this->fingerprint($counts),

            'punches' => $counts['total'],
            'unapproved' => $counts['unapproved'],
            'open' => $counts['open'],

            // Seconds since TCP was last read for this range, so the pill can
            // count up on its own between polls instead of showing a timestamp
            // that means nothing to anybody.
            'checked_seconds_ago' => $lastTouch === null ? null : max(0, time() - (int) $lastTouch),
            'checking' => (bool) ($state['checking'] ?? false),
            'error' => $state['error'] ?? null,
            'skipped' => $state['skipped'] ?? null,

            // What the browser should do next. Served rather than hard-coded in
            // the page so the cadence is one config change, not a redeploy of
            // a Blade template.
            'poll_seconds' => max(2, (int) config('tcp.live.poll_seconds', 10)),
        ];
    }

    /**
     * EVERYTHING THE GRID CAN SHOW, IN ONE INDEXED QUERY.
     *
     * count and max(updated_at) alone are not enough: a correction that moved a
     * punch's times leaves both unchanged when it lands in the same second, and
     * an approval is invisible to a count. The sums are here so an approval and
     * a clock-out each move the fingerprint on their own.
     *
     * @return array{total:int,unapproved:int,open:int,latest:?string,newest:int,hours:string}
     */
    private function counts(int $storeId, string $from, string $to): array
    {
        $row = WorkSegment::query()
            ->where('store_id', $storeId)
            ->whereBetween('business_date', [$from, $to])
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when manager_approval = 0 and time_out is not null then 1 else 0 end) as unapproved')
            ->selectRaw('sum(case when time_out is null then 1 else 0 end) as open')
            ->selectRaw('max(updated_at) as latest')
            ->selectRaw('coalesce(max(id), 0) as newest')
            // A time correction changes the hours without changing any count.
            ->selectRaw('coalesce(sum(hours), 0) as hours')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'unapproved' => (int) ($row->unapproved ?? 0),
            'open' => (int) ($row->open ?? 0),
            'latest' => $row->latest ?? null,
            'newest' => (int) ($row->newest ?? 0),
            // A string, not a float: this only ever gets hashed, and rounding
            // differences between drivers must not make a fingerprint flap.
            'hours' => (string) ($row->hours ?? '0'),
        ];
    }

    /** @param  array<string,mixed>  $counts */
    private function fingerprint(array $counts): string
    {
        return substr(hash('xxh128', implode('|', [
            $counts['total'],
            $counts['unapproved'],
            $counts['open'],
            $counts['latest'] ?? '',
            $counts['newest'],
            $counts['hours'],
        ])), 0, 16);
    }

    /** @param  array<string,mixed>  $state */
    private function isStale(array $state, int $maxAgeSeconds): bool
    {
        $last = $state['synced_at'] ?? $state['attempted_at'] ?? null;

        // Never read at all. Both callers refresh in this case, whatever their
        // threshold — a range with no reading behind it is the one case where an
        // empty grid really could be a lie.
        if ($last === null) {
            return true;
        }

        return (time() - (int) $last) >= $maxAgeSeconds;
    }

    private function refreshSeconds(): int
    {
        return max(5, (int) config('tcp.live.refresh_seconds', 20));
    }

    /** @return array<string,mixed> */
    private function state(int $storeId, string $from, string $to): array
    {
        $state = Cache::get($this->cacheKey($storeId, $from, $to));

        return is_array($state) ? $state : [];
    }

    private function cacheKey(int $storeId, string $from, string $to): string
    {
        return "tcp:live_segments:{$storeId}:{$from}:{$to}";
    }

    /**
     * Rows TCP sent that we refused, counted by reason.
     *
     * Not silence, and not a wall of json either: these are hours that exist at
     * the vendor and are not on the screen, and the reason is the actionable
     * part. Kept on the payload so the pill can say it out loud.
     *
     * @param  array<int,mixed>  $skipped
     */
    private function summariseSkipped(array $skipped): ?string
    {
        if ($skipped === []) {
            return null;
        }

        return collect($skipped)
            ->map(fn (mixed $row): string => is_array($row) ? (string) ($row['reason'] ?? 'unknown') : (string) $row)
            ->countBy()
            ->map(fn (int $n, string $reason): string => "{$reason} ×{$n}")
            ->join(', ');
    }
}
