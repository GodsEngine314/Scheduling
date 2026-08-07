<?php

namespace App\Console\Commands;

use App\Enums\PublishState;
use App\Jobs\PublishShiftToHumanity;
use App\Models\Shift;
use App\Models\StoreSetting;
use App\Services\Scheduling\SchedulePublisher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishScheduleCommand extends Command
{
    protected $signature = 'scheduling:publish
        {--store= : Store id (required)}
        {--from= : First business date (YYYY-MM-DD), defaults to today}
        {--to= : Last business date (YYYY-MM-DD), defaults to --from plus the store publish_lead_days}
        {--queue : Dispatch one PublishShiftToHumanity job per shift instead of publishing inline}
        {--dry-run : List what would be published without calling Humanity}';

    protected $description = 'Push draft, queued, failed and edited shifts for one store to Humanity.';

    public function handle(SchedulePublisher $publisher): int
    {
        $store = $this->option('store');

        if ($store === null || $store === '') {
            $this->error('--store is required: a publish run is always scoped to one store.');

            return self::FAILURE;
        }

        $storeId = (int) $store;
        $from = $this->normaliseDate($this->option('from')) ?? CarbonImmutable::now()->toDateString();
        $to = $this->normaliseDate($this->option('to')) ?? $this->defaultTo($storeId, $from);

        if ($to < $from) {
            $this->error("--to ({$to}) is before --from ({$from}).");

            return self::FAILURE;
        }

        $this->line('Store: '.$storeId);
        $this->line('Range: '.$from.' to '.$to);
        $this->newLine();

        if ($this->option('dry-run')) {
            return $this->listPending($publisher, $storeId, $from, $to);
        }

        if ($this->option('queue')) {
            return $this->queuePending($publisher, $storeId, $from, $to);
        }

        try {
            $report = $publisher->publishRange($storeId, $from, $to);
        } catch (Throwable $e) {
            // publishRange reports per-shift failures rather than throwing, so
            // reaching here means the run itself could not start.
            $this->error('Publish run failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Shifts:    '.$report['total']);
        $this->line('Created:   '.$report['created']);
        $this->line('Updated:   '.$report['updated']);
        $this->line('Unchanged: '.$report['unchanged']);
        $this->line('Failed:    '.$report['failed']);

        if ($report['failed'] > 0) {
            $this->newLine();
            $this->error('Failures ('.$report['failed'].'):');

            foreach ($report['results'] as $result) {
                if ($result['status'] === 'failed') {
                    $this->line('  Shift #'.$result['shift_id'].' ('.$result['business_date'].'): '.$result['error']);
                }
            }

            return self::FAILURE;
        }

        $this->info('Publish complete.');

        return self::SUCCESS;
    }

    private function listPending(SchedulePublisher $publisher, int $storeId, string $from, string $to): int
    {
        $pending = $publisher->pendingInRange($storeId, $from, $to);

        $this->warn('Dry-run — nothing was sent to Humanity.');
        $this->line('Would publish '.$pending->count().' shift(s):');

        foreach ($pending as $shift) {
            $this->line(sprintf(
                '  Shift #%d  %s  %s  employee %s  %s',
                $shift->id,
                $shift->business_date?->toDateString(),
                $shift->publish_state?->value,
                $shift->employee_id ?? 'open',
                $shift->humanity_shift_id === null ? 'create' : 'update '.$shift->humanity_shift_id,
            ));
        }

        return self::SUCCESS;
    }

    private function queuePending(SchedulePublisher $publisher, int $storeId, string $from, string $to): int
    {
        $pending = $publisher->pendingInRange($storeId, $from, $to);

        foreach ($pending as $shift) {
            // queued is what the board shows between "the manager hit publish"
            // and "Humanity acknowledged it". The job is dispatched after the
            // commit so a worker cannot pick it up before the state is visible.
            DB::transaction(static fn (): bool => $shift->forceFill([
                'publish_state' => PublishState::Queued,
            ])->save());

            PublishShiftToHumanity::dispatch((int) $shift->id);
        }

        $this->info('Queued '.$pending->count().' shift(s) for publishing.');

        return self::SUCCESS;
    }

    /**
     * How far ahead this store publishes. store_settings is scheduling-owned,
     * and a store with no settings row still has to be publishable.
     */
    private function defaultTo(int $storeId, string $from): string
    {
        $leadDays = StoreSetting::query()->where('store_id', $storeId)->value('publish_lead_days');

        return CarbonImmutable::parse($from)->addDays(max(0, (int) ($leadDays ?? 14)))->toDateString();
    }

    private function normaliseDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($date)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
