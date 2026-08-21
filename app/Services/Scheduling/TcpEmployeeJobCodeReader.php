<?php

namespace App\Services\Scheduling;

use App\DataTransferObjects\EmployeeFilter;
use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSystem;
use App\Models\Employee;
use App\Models\IntegrationIdentity;
use App\Models\Store;
use App\Models\TcpEmployeeJobCode;
use App\Models\TcpJobCodeRole;
use App\Support\Integrations\Tcp\TcpClient;

/**
 * Reads GET /employeejobcodes into tcp_employee_job_codes.
 *
 * WHY, IN ONE LINE: so nobody has to tell us what TCP already knows.
 *
 * A punch needs a jobCodeId. Until now a manager picked a position and
 * TcpJobCodeRole::jobCodeIdFor() assembled franchise + store + role into a code
 * we hoped existed. It often did not — three of our positions map to no TCP code
 * anywhere, one exists at a single store — and the failure was the bad kind: the
 * punch saved, appeared on the board, and was refused at the vendor afterwards.
 * TCP assigns codes to PEOPLE, and its own timeclock files hours against those
 * assignments, so reading them turns a guess into a lookup and lets the dropdown
 * go away.
 *
 * READ-ONLY AND REBUILT, LIKE THE ROSTER PULL NEXT TO IT. Nothing here creates
 * an employee: an assignment for somebody who is not in our roster is REPORTED,
 * because people arrive from hiring over NATS and inventing one here would put a
 * row in a projection that the next replay erases. Same rule, same reason, as
 * TcpEmployeeReader.
 *
 * WHAT THE ENDPOINT RETURNS, and the one distinction that matters:
 *
 *     37951001   "Crew Member - 3795-10"    a per-store ROLE
 *     1003       "Bonus"                    a company-wide PAY CATEGORY
 *
 * Both are stored — a pay category that is silently dropped is a pay category
 * nobody can see we ignored — but only role rows are ever sent as a punch's job
 * code. "Bonus" says how an hour is paid, not what anybody did, and filing hours
 * under it because it happened to sort first is a payroll error nobody catches.
 *
 * STALE ASSIGNMENTS ARE PRUNED, per store. An assignment removed at TCP simply
 * stops coming back, and a row left behind here is a code we would go on
 * sending after TCP stopped accepting it. Scoped to the store just synced, so
 * one store's pull can never delete another store's mapping.
 */
class TcpEmployeeJobCodeReader
{
    public function __construct(private readonly TcpClient $tcp) {}

    /**
     * Sync every assignment for one store's roster.
     *
     * @return array<string,mixed>
     */
    public function syncStore(int $storeId): array
    {
        if (! $this->tcpIsConfigured()) {
            return $this->report(0, 0, 0, 0, [], [['reason' => 'tcp_not_configured']]);
        }

        $storeNumber = $this->storeNumberFor($storeId);
        $storeKey = TcpJobCodeRole::storeKeyFor($storeNumber);

        if ($storeKey === null) {
            // No code can name this store, so no assignment here could be
            // matched to it even if one came back.
            return $this->report(0, 0, 0, 0, [], [[
                'reason' => 'store_number_cannot_form_a_job_code',
                'store_id' => $storeId,
                'store_number' => $storeNumber,
            ]]);
        }

        // WHO TO ASK ABOUT. The endpoint filters by people and nothing else, so
        // a store has to be turned into ids first. Read from the roster we
        // already hold rather than from TCP: the mapping is what the employee
        // pull maintains, and going back to the vendor for it would double the
        // calls to learn something we were just told.
        $employees = $this->rosterFor($storeId);

        if ($employees === []) {
            return $this->report(0, 0, 0, 0, [], [[
                'reason' => 'store_has_no_tcp_employees',
                'store_id' => $storeId,
            ]]);
        }

        $records = $this->tcp->employeeJobCodes(
            new EmployeeFilter(employeeIds: array_keys($employees)),
        );

        $written = 0;
        $roles = 0;
        $unmatched = [];
        $skipped = [];
        $seenIds = [];

        foreach ($records as $record) {
            $fields = $this->lowered($record);

            $tcpEmployeeId = $this->string($this->pick($fields, [
                'employeeId', 'employee_id', 'employeeID',
            ]));

            $jobCodeId = $this->string($this->pick($fields, [
                'jobCodeId', 'job_code_id', 'jobCode',
            ]));

            if ($tcpEmployeeId === null || $jobCodeId === null) {
                $skipped[] = ['reason' => 'no_employee_or_job_code_id'];

                continue;
            }

            $employeeId = $employees[$tcpEmployeeId] ?? null;

            if ($employeeId === null) {
                // Reported, never created. See the class docblock.
                $unmatched[] = [
                    'tcp_employee_id' => $tcpEmployeeId,
                    'job_code_id' => $jobCodeId,
                ];

                continue;
            }

            $shape = $this->shapeOf($jobCodeId);

            TcpEmployeeJobCode::query()->updateOrCreate(
                ['employee_id' => $employeeId, 'job_code_id' => $jobCodeId],
                [
                    'tcp_employee_id' => $tcpEmployeeId,
                    'tcp_record_id' => $this->string($this->pick($fields, ['id', 'recordId', 'employeeJobCodeRecordId'])),
                    'description' => $this->string($this->pick($fields, ['description', 'name'])),
                    'store_key' => $shape['store_key'],
                    'role_suffix' => $shape['role_suffix'],
                    'is_role' => $shape['is_role'],
                    'tcp_synced_at' => now(),
                ],
            );

            $written++;

            if ($shape['is_role']) {
                $roles++;
            }

            $seenIds[] = $employeeId.':'.$jobCodeId;
        }

        $pruned = $this->pruneStale($storeKey, array_values(array_unique($seenIds)));

        return $this->report(count($records), $written, $roles, $pruned, $unmatched, $skipped);
    }

    /**
     * Every store, for the scheduled sweep.
     *
     * One store's failure does not stop the rest: an estate-wide run that
     * abandoned itself at the first store TCP has never heard of would leave
     * most of the estate unmapped and report success for the part it reached.
     *
     * @return array<string,mixed>
     */
    public function syncAll(): array
    {
        $stores = Store::query()->orderBy('id')->pluck('id');

        $totals = ['fetched' => 0, 'written' => 0, 'roles' => 0, 'pruned' => 0];
        $unmatched = [];
        $skipped = [];

        foreach ($stores as $storeId) {
            $report = $this->syncStore((int) $storeId);

            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) ($report[$key] ?? 0);
            }

            $unmatched = array_merge($unmatched, $report['unmatched'] ?? []);

            foreach ($report['skipped'] ?? [] as $row) {
                $skipped[] = $row;
            }
        }

        return $this->report(
            $totals['fetched'],
            $totals['written'],
            $totals['roles'],
            $totals['pruned'],
            $unmatched,
            $skipped,
        );
    }

    /**
     * Which kind of code this is, and its parts.
     *
     * THE WHOLE DISTINCTION IN ONE PLACE. A per-store role is eight digits —
     * four of franchise, two of store, two of role — and a pay category is four.
     * Nothing else about the payload separates them, and getting it wrong files
     * somebody's shift as "Bonus".
     *
     * The store and role are only trusted when the code is a role: reading
     * digits out of 1003 would produce a store_key of '10' and a suffix of '03',
     * both meaningless and both indexed.
     *
     * @return array{store_key: ?string, role_suffix: ?string, is_role: bool}
     */
    private function shapeOf(string $jobCodeId): array
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', trim($jobCodeId), $parts) !== 1) {
            return ['store_key' => null, 'role_suffix' => null, 'is_role' => false];
        }

        return [
            'store_key' => $parts[1].$parts[2],
            'role_suffix' => $parts[3],
            'is_role' => true,
        ];
    }

    /**
     * Drop assignments at this store that TCP no longer reports.
     *
     * PER STORE, and only role rows. A pay category carries no store_key, so it
     * cannot be attributed to the store just synced and must not be swept by it;
     * they are pruned only when the estate-wide sweep sees the whole picture,
     * which it does not do here on purpose — an over-eager prune of a code we
     * still need is worse than a stale row somebody can see.
     *
     * @param  array<int,string>  $seen  "employeeId:jobCodeId" pairs kept
     */
    private function pruneStale(string $storeKey, array $seen): int
    {
        $rows = TcpEmployeeJobCode::query()
            ->where('is_role', true)
            ->where('store_key', $storeKey)
            ->get(['id', 'employee_id', 'job_code_id']);

        $stale = $rows
            ->reject(fn (TcpEmployeeJobCode $row): bool => in_array(
                $row->employee_id.':'.$row->job_code_id,
                $seen,
                true,
            ))
            ->pluck('id');

        if ($stale->isEmpty()) {
            return 0;
        }

        return TcpEmployeeJobCode::query()->whereIn('id', $stale)->delete();
    }

    /**
     * This store's roster as TCP id => our employee id.
     *
     * integration_identities FIRST, then the projected column, which is the same
     * precedence TcpEmployeeReader::resolveEmployee() uses and for the same
     * reason: an id we obtained by calling TCP must not be read off a projection
     * that a rebuild erases.
     *
     * @return array<string,int>
     */
    private function rosterFor(int $storeId): array
    {
        $employeeIds = Employee::query()
            ->where('primary_store_id', $storeId)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);

        if ($employeeIds->isEmpty()) {
            return [];
        }

        $roster = [];

        // The authoritative mapping.
        IntegrationIdentity::query()
            ->where('system', IntegrationSystem::Tcp)
            ->where('entity_type', IntegrationEntityType::Employee)
            ->whereIn('entity_id', $employeeIds)
            ->get(['entity_id', 'external_id'])
            ->each(function ($identity) use (&$roster): void {
                $external = trim((string) $identity->external_id);

                if ($external !== '') {
                    $roster[$external] = (int) $identity->entity_id;
                }
            });

        // The projected fallback, for anyone the identities table has not
        // recorded yet. Does not overwrite the above.
        Employee::query()
            ->whereIn('id', $employeeIds)
            ->whereNotNull('tcp_employee_id')
            ->get(['id', 'tcp_employee_id'])
            ->each(function (Employee $employee) use (&$roster): void {
                $external = trim((string) $employee->tcp_employee_id);

                if ($external !== '' && ! isset($roster[$external])) {
                    $roster[$external] = (int) $employee->id;
                }
            });

        return $roster;
    }

    /**
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

    private function storeNumberFor(int $storeId): ?string
    {
        return $this->string(Store::query()->whereKey($storeId)->value('store_number'));
    }

    /**
     * @param  array<int,array<string,mixed>>  $unmatched
     * @param  array<int,array<string,mixed>>  $skipped
     * @return array<string,mixed>
     */
    private function report(int $fetched, int $written, int $roles, int $pruned, array $unmatched, array $skipped): array
    {
        return [
            'fetched' => $fetched,
            'written' => $written,
            'roles' => $roles,
            'pruned' => $pruned,
            'unmatched' => $unmatched,
            'skipped' => $skipped,
        ];
    }

    /**
     * Vendor payloads are not consistent about case. Lowered keys let pick()
     * name the spelling it wants once.
     *
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function lowered(array $record): array
    {
        $lowered = [];

        foreach ($record as $key => $value) {
            $lowered[strtolower((string) $key)] = $value;
        }

        return $lowered;
    }

    /**
     * @param  array<string,mixed>  $fields
     * @param  array<int,string>  $keys
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
        if ($value === null || is_array($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
