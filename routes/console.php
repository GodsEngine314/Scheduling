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

// --minutes=20 against a ten-minute cadence is a deliberate 2x overlap. A run
// that starts late, a vendor that is briefly slow, or a punch that lands on the
// boundary would otherwise fall through the gap between two windows and never
// be seen again, because the window is TCP's updatedOn and it only moves
// forward. Re-reading a record costs nothing: the upsert is keyed on
// tcp_segment_id and an unchanged row is counted as unchanged, not rewritten.
Schedule::command('scheduling:sync-segments --minutes=20')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Every minute: the outbox is how scheduling tells the rest of the estate a
// shift moved, and a minute of lag is the most anyone downstream should inherit
// from us.
Schedule::command('outbox:publish-pending')
    ->everyMinute()
    ->withoutOverlapping();
