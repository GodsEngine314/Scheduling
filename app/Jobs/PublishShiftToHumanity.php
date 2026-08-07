<?php

namespace App\Jobs;

use App\Exceptions\IntegrationException;
use App\Models\Shift;
use App\Services\Scheduling\SchedulePublisher;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Publish one shift, off the request cycle.
 *
 * WHY WithoutOverlapping ON THE SHIFT ID. Humanity has no upsert. Two workers
 * that pick up the same shift both read humanity_shift_id as NULL, both POST,
 * and the employee ends up rostered twice for the same hours — and only one of
 * the two ids survives on the row, so the duplicate is invisible from here and
 * has to be found by hand in the vendor. The lock is per shift, not global: a
 * whole store's publish still runs in parallel.
 *
 * WHY THE RETRY POLICY IS SPLIT IN TWO. IntegrationException::isTransient()
 * already knows the difference between "Humanity is restarting" and "Humanity
 * rejected this payload". A 422 or one of our own guards — an employee with no
 * Humanity id — will be rejected identically in five minutes, so re-running it
 * eight times just delays the moment somebody reads the error. Those fail
 * immediately. A 429, a 503 or a dead connection gets ridden out for roughly
 * three quarters of an hour, which covers a vendor deploy without leaving a
 * store's schedule stuck behind a queue for the rest of the day.
 */
class PublishShiftToHumanity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** The ceiling; retryUntil() is what actually ends it. */
    public int $tries = 8;

    public function __construct(public readonly int $shiftId)
    {
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->shiftId))
                // Do not drop the second worker's copy — release it and let it
                // find the shift already published and skip it as unchanged.
                ->releaseAfter(30)
                // A worker killed mid-publish must not hold the shift forever.
                ->expireAfter(300),
        ];
    }

    /**
     * Escalating waits: a blip clears in a minute, an outage does not.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 120, 300, 600, 900];
    }

    /** Roughly 45 minutes of vendor outage, then the shift is left as failed. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(45);
    }

    /**
     * @throws Throwable
     */
    public function handle(SchedulePublisher $publisher): void
    {
        $shift = Shift::query()->find($this->shiftId);

        if ($shift === null) {
            // Deleted while queued. Nothing to publish and nothing to report.
            return;
        }

        try {
            // push() is idempotent: a shift Humanity already holds unchanged is
            // a no-op, which is what makes releasing an overlapping copy safe.
            $publisher->push($shift);
        } catch (IntegrationException $e) {
            if (! $e->isTransient()) {
                // The publisher has already written publish_state = failed and
                // the reason onto the row; fail() records it against the job
                // without spending the rest of the retry budget.
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }
}
