<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| withoutOverlapping() on both, for different reasons. The segment sync is
| slower than its own interval on a busy day and two copies would race the
| same upsert; the outbox publisher would send the same event to NATS twice.
|
*/

/*
 * THE SAFETY NET, not the main event.
 *
 * A board somebody has open keeps ITSELF current — see LiveSegmentFeed, which
 * refreshes the visible range from inside the page's own poll. This sweep is for
 * everything nobody is looking at: a store with no manager on the board, a
 * correction filed last week against a month-old date, the small hours.
 *
 * updatedOn, not the punch date, which is what lets one query cover the whole
 * estate: a punch entered today for last Tuesday is exactly the correction a
 * date-scoped sync would never see again.
 *
 * --minutes=3 against a one-minute cadence is a deliberate 3x overlap. A run
 * that starts late, a vendor that is briefly slow, or a punch that lands on the
 * boundary would otherwise fall through the gap between two windows and never
 * be seen again, because the window only moves forward. Re-reading a record
 * costs nothing: the upsert is keyed on tcp_segment_id and an unchanged row is
 * counted as unchanged, not rewritten.
 *
 * Every minute rather than every ten, because ten minutes was the whole reason
 * the button still existed — a manager who wanted a punch NOW could not wait for
 * the sweep, so they pressed a button, and the button is what made a stale board
 * possible at all. withoutOverlapping keeps a slow run from stacking.
 */
Schedule::command('scheduling:sync-segments --minutes=3')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * WHICH JOB CODE EACH PERSON HOLDS AT TCP — what the position dropdown used to
 * ask a manager for.
 *
 * Opening a board already refreshes the store you land on, so this is the
 * estate-wide backstop: somebody hired into a new role, or moved between
 * stores, at a store nobody has looked at today. Hourly is ample — an
 * assignment changes when HR changes it, not when somebody clocks in — and a
 * punch for a person with no code is refused with a message naming them rather
 * than filed under a guess.
 */
Schedule::command('scheduling:sync-employee-job-codes')
    ->hourly()
    ->withoutOverlapping();

// Every minute: the outbox is how scheduling tells the rest of the estate a
// shift moved, and a minute of lag is the most anyone downstream should inherit
// from us.
Schedule::command('outbox:publish-pending')
    ->everyMinute()
    ->withoutOverlapping();
