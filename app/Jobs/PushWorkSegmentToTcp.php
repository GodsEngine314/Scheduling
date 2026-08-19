<?php

namespace App\Jobs;

use App\Models\WorkSegment;
use App\Services\Scheduling\TcpWorkSegmentWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Push one work segment to TCP, out of band from the request that changed it.
 *
 * WithoutOverlapping on the segment id: two workers pushing the same row could
 * both find tcp_segment_id null and each POST, leaving two segments in TCP for
 * one block of worked time — which is a duplicate on somebody's paycheque.
 *
 * The backoff rides out a vendor outage for roughly forty-five minutes. It only
 * ever runs on a transient failure: TcpWorkSegmentWriter swallows 4xx and marks
 * the row failed rather than rethrowing, so a rejected payload does not spend
 * six attempts being rejected identically.
 */
class PushWorkSegmentToTcp implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @var array<int,int> seconds */
    public array $backoff = [30, 120, 300, 600, 1200];

    public function __construct(public readonly int $workSegmentId) {}

    /** @return array<int,object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->workSegmentId))->releaseAfter(30)];
    }

    public function handle(TcpWorkSegmentWriter $writer): void
    {
        // withTrashed: a segment deleted locally still has to be removed from
        // TCP, and the delete path dispatches this job too.
        // store and position come along because the job code is built from
        // both — see TcpWorkSegmentWriter::wireBody().
        $segment = WorkSegment::withTrashed()
            ->with(['employee', 'store', 'position'])
            ->find($this->workSegmentId);

        if ($segment === null) {
            return;
        }

        if ($segment->trashed()) {
            $writer->delete($segment);

            return;
        }

        $writer->push($segment);
    }
}
