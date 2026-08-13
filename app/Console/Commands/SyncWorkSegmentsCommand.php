<?php

namespace App\Console\Commands;

use App\Exceptions\IntegrationException;
use App\Services\Scheduling\WorkSegmentSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SyncWorkSegmentsCommand extends Command
{
    protected $signature = 'scheduling:sync-segments
        {--date= : Sync one business date (YYYY-MM-DD)}
        {--store= : With --date, limit the pull to one store (by its TCP location id, falling back to its employees)}
        {--minutes= : Sync everything TCP changed in the last N minutes}';

    protected $description = 'Pull work segments from TCP into work_segments, by date or by TCP updatedOn window.';

    public function handle(WorkSegmentSyncService $sync): int
    {
        $date = $this->option('date');
        $minutes = $this->option('minutes');

        // Exactly one, never both: they are two different questions and running
        // them together would double-fetch the overlap for no benefit.
        if (($date === null) === ($minutes === null)) {
            $this->error('Pass exactly one of --date=YYYY-MM-DD or --minutes=N.');

            return self::FAILURE;
        }

        if ($date !== null) {
            $date = $this->normaliseDate($date);

            if ($date === null) {
                $this->error("--date must be a date in YYYY-MM-DD form, got '{$this->option('date')}'.");

                return self::FAILURE;
            }
        }

        try {
            $report = $date !== null
                ? $sync->syncDate($date, $this->storeOption())
                : $sync->syncIncremental(max(1, (int) $minutes));
        } catch (IntegrationException $e) {
            // getMessage() carries no response body by design; the correlation
            // id in it is what lines this up with the vendor's own log.
            $this->error('TCP sync failed: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('TCP sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Fetched:   '.$report['fetched']);
        $this->line('Created:   '.$report['created']);
        $this->line('Updated:   '.$report['updated']);
        $this->line('Unchanged: '.$report['unchanged']);
        $this->line('Held:      '.$report['held'].' (local approval or correction kept)');

        if ($report['skipped'] !== []) {
            $this->newLine();
            $this->warn('Skipped ('.count($report['skipped']).'):');

            foreach ($report['skipped'] as $skipped) {
                $this->line('  '.($skipped['reason'] ?? 'unknown').': '.json_encode($skipped));
            }
        }

        $this->info('Work segment sync complete.');

        return self::SUCCESS;
    }

    private function normaliseDate(string $date): ?string
    {
        try {
            return CarbonImmutable::parse($date)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function storeOption(): ?int
    {
        $store = $this->option('store');

        return $store === null || $store === '' ? null : (int) $store;
    }
}
