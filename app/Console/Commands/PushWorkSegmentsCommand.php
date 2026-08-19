<?php

namespace App\Console\Commands;

use App\Enums\TcpSyncState;
use App\Models\WorkSegment;
use App\Services\Scheduling\TcpWorkSegmentWriter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-push work segments TCP never accepted.
 *
 * THE OTHER DIRECTION FROM scheduling:sync-segments, which only ever PULLS.
 * Nothing existed to retry a push, so a segment that failed stayed failed until
 * somebody edited it — and the commonest cause was a fault in our payload, not
 * at the vendor, which means a whole backlog would come good the moment the
 * payload was fixed and nothing would notice.
 *
 * That is exactly what happened with jobCodeId: every hand-entered punch in the
 * estate failed, silently, and each one sat on the board looking recorded.
 *
 * SAFE TO RUN REPEATEDLY. The writer decides create-or-update from
 * tcp_segment_id, so a segment that did reach TCP is updated rather than
 * duplicated, and one that cannot be fixed just fails again with the same
 * message.
 */
class PushWorkSegmentsCommand extends Command
{
    protected $signature = 'scheduling:push-segments
        {--store= : Only this store id}
        {--limit=500 : Stop after this many, so a first run cannot spend an hour}
        {--pending : Include segments still queued (pending), not just failed ones}
        {--dry-run : Report what would be pushed and change nothing}';

    protected $description = 'Retry pushing failed work segments to TCP, and report the ones that cannot be fixed.';

    public function handle(TcpWorkSegmentWriter $writer): int
    {
        $states = $this->option('pending')
            ? [TcpSyncState::Failed, TcpSyncState::Pending]
            : [TcpSyncState::Failed];

        $query = WorkSegment::query()
            ->with(['employee', 'store', 'position'])
            ->whereIn('tcp_sync_state', $states)
            ->orderBy('id');

        if ($this->option('store') !== null) {
            $query->where('store_id', (int) $this->option('store'));
        }

        $segments = $query->limit(max(1, (int) $this->option('limit')))->get();

        if ($segments->isEmpty()) {
            $this->info('Nothing to push.');

            return self::SUCCESS;
        }

        $this->line($segments->count().' segment(s) to push.');

        $pushed = 0;
        $stuck = [];

        foreach ($segments as $segment) {
            $label = '#'.$segment->id.' '.($segment->employee?->fullName() ?? 'unknown')
                .' at '.($segment->store?->store_number ?? $segment->store_id);

            if ($this->option('dry-run')) {
                $this->line('  would push '.$label);

                continue;
            }

            try {
                $writer->push($segment);
            } catch (Throwable $e) {
                // A TRANSIENT failure rethrows out of the writer, by design, so
                // the queued job can retry it. Here there is no job to retry —
                // record it and keep going rather than abandoning the backlog
                // partway through because the vendor blinked.
                $stuck[$label] = $e->getMessage();

                continue;
            }

            $segment->refresh();

            if ($segment->tcp_sync_state === TcpSyncState::Synced) {
                $pushed++;

                continue;
            }

            $stuck[$label] = (string) $segment->tcp_sync_error;
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $this->info($pushed.' pushed.');

        if ($stuck === []) {
            return self::SUCCESS;
        }

        // NAMED, NOT COUNTED. These are the ones a person has to act on, and
        // "12 still failing" tells nobody which punch to open.
        $this->newLine();
        $this->warn(count($stuck).' still failing:');

        foreach ($stuck as $label => $reason) {
            $this->line('  '.$label);
            $this->line('    '.$reason);
        }

        return self::SUCCESS;
    }
}
