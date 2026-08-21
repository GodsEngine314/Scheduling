<?php

namespace App\Console\Commands;

use App\Exceptions\IntegrationException;
use App\Services\Scheduling\TcpEmployeeJobCodeReader;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pull TCP's employee-to-job-code assignments.
 *
 * THIS IS WHAT THE POSITION DROPDOWN USED TO BE. A punch needs a jobCodeId; it
 * used to be assembled from a position a manager picked, on the hope TCP had
 * that franchise+store+role combination. Now it is looked up, because TCP has
 * been assigning codes to people all along and its own timeclock files hours
 * against those assignments.
 *
 * Safe to run repeatedly: the write is an upsert on (employee, job code), and
 * assignments TCP no longer reports for a store are pruned so a code we would
 * still be sending cannot linger.
 */
class SyncEmployeeJobCodesCommand extends Command
{
    protected $signature = 'scheduling:sync-employee-job-codes
        {--store= : Only this store id}';

    protected $description = "Pull each employee's TCP job code assignments into tcp_employee_job_codes.";

    public function handle(TcpEmployeeJobCodeReader $reader): int
    {
        $store = $this->option('store');

        try {
            $report = $store === null || $store === ''
                ? $reader->syncAll()
                : $reader->syncStore((int) $store);
        } catch (IntegrationException $e) {
            // getMessage() carries no response body by design; the correlation
            // id in it is what lines this up with the vendor's own log.
            $this->error('TCP job code sync failed: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('TCP job code sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Fetched:  '.$report['fetched']);
        $this->line('Written:  '.$report['written']);
        $this->line('Roles:    '.$report['roles'].' (the rest are company-wide pay categories)');
        $this->line('Pruned:   '.$report['pruned'].' assignment(s) TCP no longer reports');

        /*
         * NAMED, NOT COUNTED. An assignment for somebody who is not in our
         * roster is the actionable case: they exist at TCP and cannot be
         * scheduled here, and the fix is upstream in hiring rather than here.
         * Never created locally — see the reader's docblock.
         */
        if ($report['unmatched'] !== []) {
            $this->newLine();
            $this->warn(count($report['unmatched']).' assignment(s) for people not in our roster:');

            foreach (array_slice($report['unmatched'], 0, 20) as $row) {
                $this->line('  TCP employee '.($row['tcp_employee_id'] ?? '?').' → job code '.($row['job_code_id'] ?? '?'));
            }

            if (count($report['unmatched']) > 20) {
                $this->line('  … and '.(count($report['unmatched']) - 20).' more');
            }

            $this->line('  They arrive from hiring over NATS; nothing is created from TCP.');
        }

        if ($report['skipped'] !== []) {
            $this->newLine();

            // Counted BY REASON. An estate-wide run legitimately skips every
            // store TCP has never heard of, and thirty identical lines say less
            // than one line with a number on it.
            $byReason = collect($report['skipped'])
                ->map(fn (mixed $row): string => is_array($row) ? (string) ($row['reason'] ?? 'unknown') : (string) $row)
                ->countBy();

            $this->warn('Skipped:');

            foreach ($byReason as $reason => $count) {
                $this->line('  '.$reason.' ×'.$count);
            }
        }

        $this->info('Employee job code sync complete.');

        return self::SUCCESS;
    }
}
