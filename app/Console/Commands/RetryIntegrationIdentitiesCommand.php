<?php

namespace App\Console\Commands;

use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSyncState;
use App\Enums\IntegrationSystem;
use App\Models\Employee;
use App\Models\IntegrationIdentity;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Unstick the identity map.
 *
 * integration_identities is SCHEDULING-OWNED and it is the reason a replay of
 * the NATS stream does not duplicate every shift in Humanity. A row stuck at
 * sync_state = 'failed' is therefore not cosmetic: SchedulePublisher reads it
 * through IntegrationIdentity::isSynced(), so a failed employee row means every
 * shift that employee works refuses to publish.
 *
 * This command does two separate things, and it is worth being clear about
 * which is which:
 *
 *   RESOLVE — a tcp employee row can be filled in from local data alone.
 *       employees.tcp_employee_id is projected from the stream and is UNIQUE,
 *       so when it is populated we already hold the mapping the failed row was
 *       trying to obtain; copying it into the scheduling-owned table is the
 *       whole point of that table. Nothing is invented and no vendor is called.
 *
 *   REQUEUE — everything else goes back to 'pending' so the next provisioning
 *       pass picks it up. For Humanity employee ids that pass DOES NOT EXIST
 *       YET — that is the known gap — so requeueing them is honest bookkeeping
 *       rather than a fix, and the summary says so.
 *
 * It deliberately calls no API. A retry sweep that fanned out to a vendor would
 * turn one bad config into a rate-limit incident, and there is no client method
 * to look an employee up by name in either system anyway.
 */
class RetryIntegrationIdentitiesCommand extends Command
{
    protected $signature = 'scheduling:retry-identities
        {--system= : Limit to one remote system (tcp|humanity)}
        {--entity-type= : Limit to one entity type (employee|store|position)}
        {--max-attempts=10 : Leave rows that have already burnt this many attempts parked}
        {--reset-attempts : Also zero the attempt counter, for use once the underlying cause is fixed}
        {--dry-run : Show what would change without writing anything}';

    protected $description = 'Retry integration_identities rows stuck at sync_state=failed.';

    public function handle(): int
    {
        $system = $this->enumOption('system', IntegrationSystem::class);
        $entityType = $this->enumOption('entity-type', IntegrationEntityType::class);

        if ($system === false || $entityType === false) {
            return self::FAILURE;
        }

        $maxAttempts = max(1, (int) $this->option('max-attempts'));
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry-run — no rows will be written.');
        }

        $rows = IntegrationIdentity::query()
            ->where('sync_state', IntegrationSyncState::Failed->value)
            ->when($system, fn ($query) => $query->where('system', $system))
            ->when($entityType, fn ($query) => $query->where('entity_type', $entityType))
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No failed integration identities.');

            return self::SUCCESS;
        }

        $counts = ['resolved' => 0, 'requeued' => 0, 'parked' => 0, 'conflicted' => 0];
        $notes = [];

        foreach ($rows as $identity) {
            if ((int) $identity->attempts >= $maxAttempts && ! $this->option('reset-attempts')) {
                // A row that has failed ten times will fail an eleventh. Leave
                // it visible as failed instead of cycling it through pending
                // every night and hiding it from anyone scanning for trouble.
                $counts['parked']++;
                $notes[] = $this->describe($identity).' parked after '.$identity->attempts.' attempts';

                continue;
            }

            $outcome = $dryRun
                ? $this->planFor($identity)
                : $this->retry($identity);

            $counts[$outcome['outcome']]++;

            if (isset($outcome['note'])) {
                $notes[] = $this->describe($identity).' '.$outcome['note'];
            }
        }

        $this->line('Failed rows: '.$rows->count());
        $this->line('Resolved:    '.$counts['resolved'].' (external id filled in from local data)');
        $this->line('Requeued:    '.$counts['requeued'].' (back to pending)');
        $this->line('Parked:      '.$counts['parked'].' (over --max-attempts)');
        $this->line('Conflicted:  '.$counts['conflicted'].' (remote id already claimed by another row)');

        if ($notes !== []) {
            $this->newLine();

            foreach ($notes as $note) {
                $this->line('  '.$note);
            }
        }

        $this->warnAboutTheGap($rows);

        // Conflicts are the only outcome nobody can clear without a human, so
        // they are the only one that makes the run non-zero.
        if ($counts['conflicted'] > 0) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Identity retry complete.');

        return self::SUCCESS;
    }

    /**
     * @return array{outcome: string, note?: string}
     */
    private function retry(IntegrationIdentity $identity): array
    {
        $resolved = $this->resolveLocally($identity);

        if ($resolved === null) {
            DB::transaction(function () use ($identity): void {
                $identity->forceFill([
                    'sync_state' => IntegrationSyncState::Pending,
                    // Cleared so a stale message cannot be mistaken for the
                    // result of the next attempt. attempts is NOT cleared
                    // unless asked: it is the record of chronic failure and
                    // --max-attempts reads it.
                    'last_error' => null,
                ] + $this->attemptReset())->save();
            });

            return ['outcome' => 'requeued'];
        }

        if ($this->isClaimedByAnother($identity, $resolved)) {
            // UNIQUE(system, entity_type, external_id) would reject this
            // anyway. Report it rather than letting a nightly command die on a
            // duplicate-key error — two of our entities pointing at one remote
            // record is a data question, not a retry question.
            return [
                'outcome' => 'conflicted',
                'note' => "cannot take external id {$resolved}: another identity row already holds it",
            ];
        }

        DB::transaction(function () use ($identity, $resolved): void {
            $identity->forceFill([
                'external_id' => $resolved,
                'sync_state' => IntegrationSyncState::Synced,
                'synced_at' => now(),
                'last_error' => null,
                // Resolved means there is nothing left to count.
                'attempts' => 0,
            ])->save();
        });

        return ['outcome' => 'resolved', 'note' => "resolved to external id {$resolved}"];
    }

    /**
     * The same decision as retry(), without the writes.
     *
     * @return array{outcome: string, note?: string}
     */
    private function planFor(IntegrationIdentity $identity): array
    {
        $resolved = $this->resolveLocally($identity);

        if ($resolved === null) {
            return ['outcome' => 'requeued'];
        }

        if ($this->isClaimedByAnother($identity, $resolved)) {
            return [
                'outcome' => 'conflicted',
                'note' => "cannot take external id {$resolved}: another identity row already holds it",
            ];
        }

        return ['outcome' => 'resolved', 'note' => "would resolve to external id {$resolved}"];
    }

    /**
     * An external id we can supply without asking a vendor, or null.
     *
     * Only one case qualifies today: a TCP employee whose tcp_employee_id the
     * employees projection already carries. There is no equivalent local source
     * for Humanity ids, for stores, or for positions — inventing one would put
     * a guessed remote id into a UNIQUE column and the wrong shifts would be
     * created against it.
     */
    private function resolveLocally(IntegrationIdentity $identity): ?string
    {
        if ($identity->external_id !== null) {
            // The remote create landed and something afterwards did not. We
            // still requeue rather than promote straight to synced: sync_state
            // is the only record of a CONFIRMED mapping, and a cron job must
            // not quietly declare an unverified one good.
            return null;
        }

        if ($identity->system !== IntegrationSystem::Tcp
            || $identity->entity_type !== IntegrationEntityType::Employee) {
            return null;
        }

        $tcpEmployeeId = Employee::query()
            ->whereKey($identity->entity_id)
            ->value('tcp_employee_id');

        if ($tcpEmployeeId === null || trim((string) $tcpEmployeeId) === '') {
            return null;
        }

        return trim((string) $tcpEmployeeId);
    }

    /** Would writing this external id collide with UNIQUE(system, entity_type, external_id)? */
    private function isClaimedByAnother(IntegrationIdentity $identity, string $externalId): bool
    {
        return IntegrationIdentity::query()
            ->forExternalId($identity->system, $identity->entity_type, $externalId)
            ->whereKeyNot($identity->getKey())
            ->exists();
    }

    /** @return array<string, int> */
    private function attemptReset(): array
    {
        return $this->option('reset-attempts') ? ['attempts' => 0] : [];
    }

    /**
     * Say out loud that requeued Humanity employee rows are not actually going
     * anywhere, so nobody reads "Requeued: 12" as "fixed 12".
     *
     * @param  Collection<int, IntegrationIdentity>  $rows
     */
    private function warnAboutTheGap(Collection $rows): void
    {
        $stuck = $rows->filter(
            fn (IntegrationIdentity $identity): bool => $identity->system === IntegrationSystem::Humanity
                && $identity->entity_type === IntegrationEntityType::Employee
                && $identity->external_id === null,
        )->count();

        if ($stuck === 0) {
            return;
        }

        $this->newLine();
        $this->warn(
            $stuck.' Humanity employee identity row(s) have no external id. NOTHING POPULATES THE HUMANITY '
            .'EMPLOYEE ID YET — that is the known gap — so these are back at pending but no provisioning pass '
            .'will pick them up. Every shift assigned to these employees will fail to publish until the id is '
            .'set, by hand or by whatever fills this in later.'
        );
    }

    /**
     * Read an option as a backed enum case.
     *
     * @param  class-string<IntegrationSystem|IntegrationEntityType>  $enum
     * @return IntegrationSystem|IntegrationEntityType|null|false  false on a bad value
     */
    private function enumOption(string $option, string $enum): IntegrationSystem|IntegrationEntityType|null|false
    {
        $value = $this->option($option);

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $case = $enum::tryFrom(trim((string) $value));

        if ($case === null) {
            $valid = implode('|', array_column($enum::cases(), 'value'));
            $this->error("--{$option} must be one of {$valid}, got '{$value}'.");

            return false;
        }

        return $case;
    }

    private function describe(IntegrationIdentity $identity): string
    {
        return sprintf(
            '%s %s #%d (%s):',
            $identity->system->value,
            $identity->entity_type->value,
            (int) $identity->entity_id,
            'identity #'.$identity->id,
        );
    }
}
