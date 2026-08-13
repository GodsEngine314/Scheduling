<?php

namespace App\Services\Scheduling;

use App\DataTransferObjects\EmployeeFilter;
use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSyncState;
use App\Enums\IntegrationSystem;
use App\Models\Employee;
use App\Models\IntegrationIdentity;
use App\Support\Integrations\Tcp\TcpClient;
use Illuminate\Support\Facades\DB;

/**
 * Ask TCP who it thinks works at a store, and record the id mapping.
 *
 * The mirror image of TcpEmployeeWriter: that one pushes our people out, this
 * one reads TCP's roster back. It exists because integration_identities is the
 * only durable record of "this local employee is that TCP employee", and until
 * something fills it in, a punch arriving from TCP can only be attributed
 * through employees.tcp_employee_id — a PROJECTION column that hiring may never
 * populate.
 *
 * IT NEVER WRITES TO THE employees TABLE, and that is the whole design
 * constraint rather than an oversight. employees is a projection of
 * hiring.v1.employee.*; its own migration says "It is DERIVED, never
 * hand-edited: any write here is overwritten by the next event". An employee
 * invented here from a TCP record would be erased by the next replay, and
 * because employees.tcp_employee_id is UNIQUE it could also collide with the
 * real row when hiring finally sent it — which makes the projector throw, burn
 * its five attempts and PARK the event. So a TCP employee we cannot already
 * account for is REPORTED, never created.
 *
 * What it does write is integration_identities, which is scheduling-owned and
 * survives a replay. That is the same rule TcpEmployeeWriter follows.
 *
 * FIELD NAMES ARE UNCONFIRMED. Every key is read case-insensitively across the
 * spellings vendors actually use, the same way WorkSegmentSyncService reads
 * punches.
 */
class TcpEmployeeReader
{
    public function __construct(private readonly TcpClient $tcp) {}

    /**
     * TCP's roster for one store, reconciled against ours.
     *
     * @return array{
     *     fetched: int,
     *     mapped: int,
     *     already_mapped: int,
     *     unmatched: array<int, array<string, mixed>>,
     *     skipped: array<int, array<string, mixed>>
     * }
     */
    public function forStore(int $storeId): array
    {
        // Checked before anything else because this runs during a page render.
        // Without credentials the client throws inside TokenProvider on every
        // single board load — an exception per request, in the log, forever.
        // Saying "not configured" once is the honest answer.
        if (! $this->tcpIsConfigured()) {
            return $this->report(0, 0, 0, [], [['reason' => 'tcp_not_configured']]);
        }

        $locationId = $this->tcpLocationIdForStore($storeId);

        if ($locationId === null) {
            // Without a location id the only filter available is "none", and an
            // unfiltered GET /employees returns the entire company — every one
            // of whom would then look like they work at this store.
            return $this->report(0, 0, 0, [], [[
                'reason' => 'store_has_no_tcp_location',
                'store_id' => $storeId,
            ]]);
        }

        $records = $this->tcp->employees(new EmployeeFilter(locationIds: [$locationId]));

        $mapped = 0;
        $alreadyMapped = 0;
        $unmatched = [];
        $skipped = [];

        foreach ($records as $record) {
            $fields = $this->lowered($record);

            $externalId = $this->string($this->pick($fields, [
                'employeeId', 'employee_id', 'employeeID', 'id', 'employeeNumber', 'employee_number',
            ]));

            if ($externalId === null) {
                $skipped[] = ['reason' => 'no_tcp_employee_id'];

                continue;
            }

            $employee = $this->resolveEmployee($externalId);

            if ($employee === null) {
                // Reported, never created — see the class docblock.
                $unmatched[] = [
                    'tcp_employee_id' => $externalId,
                    'name' => $this->displayName($fields),
                ];

                continue;
            }

            $outcome = $this->mapIdentity(
                $employee,
                $externalId,
                $this->string($this->pick($fields, ['employeeRecordId', 'employee_record_id', 'recordId'])),
            );

            match ($outcome) {
                'mapped' => $mapped++,
                default => $alreadyMapped++,
            };
        }

        return $this->report(count($records), $mapped, $alreadyMapped, $unmatched, $skipped);
    }

    /**
     * Write the confirmed mapping.
     *
     * There is deliberately no guard here against UNIQUE(system, entity_type,
     * external_id). It cannot be tripped from this path: resolveEmployee()
     * looks the TCP id up in that same table FIRST, so a id already claimed by
     * somebody resolves to that somebody, and this only ever rewrites the row
     * that already held it. A guard would be a branch no input can reach.
     *
     * That does make the precedence in resolveEmployee() load-bearing rather
     * than a preference — reverse it and this becomes reachable.
     *
     * @return string 'mapped' | 'already_mapped'
     */
    private function mapIdentity(Employee $employee, string $externalId, ?string $externalRecordId): string
    {
        return DB::transaction(function () use ($employee, $externalId, $externalRecordId): string {
            $identity = IntegrationIdentity::query()->firstOrNew([
                'entity_type' => IntegrationEntityType::Employee,
                'entity_id' => (int) $employee->id,
                'system' => IntegrationSystem::Tcp,
            ]);

            $wasSynced = $identity->exists
                && $identity->external_id === $externalId
                && $identity->isSynced();

            $identity->forceFill([
                'external_id' => $externalId,
                // Only overwrite the record id when TCP actually sent one; a
                // missing one here is "not in this payload", not "cleared".
                'external_record_id' => $externalRecordId ?? $identity->external_record_id,
                // Synced, and honestly so: TCP handed this id back for this
                // person, which is exactly what a confirmed mapping is.
                'sync_state' => IntegrationSyncState::Synced,
                'synced_at' => now(),
                'last_error' => null,
                'attempts' => 0,
            ])->save();

            return $wasSynced ? 'already_mapped' : 'mapped';
        });
    }

    /**
     * The local employee a TCP id belongs to, or null.
     *
     * Owned table first, projected column second — the same precedence
     * TcpEmployeeWriter::resolve() uses, so the two never disagree about who a
     * TCP id points at.
     */
    private function resolveEmployee(string $externalId): ?Employee
    {
        $entityId = IntegrationIdentity::query()
            ->forExternalId(IntegrationSystem::Tcp, IntegrationEntityType::Employee, $externalId)
            ->value('entity_id');

        if ($entityId !== null) {
            return Employee::query()->find((int) $entityId);
        }

        return Employee::query()->where('tcp_employee_id', $externalId)->first();
    }

    /**
     * Enough credentials to be worth attempting a call.
     *
     * Mirrors TcpClient::authDescriptor()'s rules rather than calling it,
     * because that one THROWS on a bad config and the point here is to decide
     * quietly whether to try at all.
     */
    private function tcpIsConfigured(): bool
    {
        $mode = (string) config('tcp.auth_mode', 'oauth');

        return match ($mode) {
            'static' => trim((string) (config('tcp.static_token') ?? '')) !== '',
            'oauth' => trim((string) (config('tcp.oauth.client_id') ?? '')) !== '',
            default => false,
        };
    }

    /** This store's TCP location id, from the scheduling-owned identity map. */
    private function tcpLocationIdForStore(int $storeId): ?string
    {
        return $this->string(
            IntegrationIdentity::query()
                ->forEntity(IntegrationEntityType::Store, $storeId, IntegrationSystem::Tcp)
                ->value('external_id')
        );
    }

    /**
     * Enough to recognise a person in the unmatched list, and no more.
     *
     * A name is what makes "somebody at this store is not in our roster"
     * actionable. Nothing else off the record is worth carrying into a flash
     * message or a log line.
     *
     * @param  array<string, mixed>  $fields
     */
    private function displayName(array $fields): string
    {
        $first = $this->string($this->pick($fields, ['firstName', 'first_name', 'givenName']));
        $last = $this->string($this->pick($fields, ['lastName', 'last_name', 'familyName']));

        $name = trim(($first ?? '').' '.($last ?? ''));

        return $name === ''
            ? ($this->string($this->pick($fields, ['name', 'displayName', 'fullName'])) ?? 'unnamed')
            : $name;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function lowered(array $record): array
    {
        $fields = [];

        foreach ($record as $key => $value) {
            if (is_string($key)) {
                $fields[strtolower($key)] = $value;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $keys
     */
    private function pick(array $fields, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = $fields[strtolower($key)] ?? null;

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @param  array<int, array<string, mixed>>  $unmatched
     * @param  array<int, array<string, mixed>>  $skipped
     * @return array<string, mixed>
     */
    private function report(int $fetched, int $mapped, int $alreadyMapped, array $unmatched, array $skipped): array
    {
        return [
            'fetched' => $fetched,
            // Newly written or corrected mappings.
            'mapped' => $mapped,
            // Already pointing at this TCP id and already confirmed.
            'already_mapped' => $alreadyMapped,
            // At this store in TCP, unknown to us. NOT created — see the class
            // docblock. These are the rows a human has to act on.
            'unmatched' => $unmatched,
            'skipped' => $skipped,
        ];
    }
}
