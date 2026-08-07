<?php

namespace App\Services\Scheduling;

use App\Enums\EmployeeStatus;
use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSyncState;
use App\Enums\IntegrationSystem;
use App\Exceptions\IntegrationException;
use App\Models\Employee;
use App\Models\IntegrationIdentity;
use Illuminate\Support\Facades\Log;

/**
 * Keeps TCP's employee record in step with the projection.
 *
 * The source document has hiring doing this ("(1) Add employee to our hiring
 * system (2) Post action request to .../v1/employees ... (4) store employeeId
 * and employeeRecordId"). Hiring does not implement it — it makes no HTTP calls
 * to TCP at all — and scheduling owns every other TCP call, so the push lives
 * here, driven by the hiring.v1.employee.* events the projector already
 * consumes. The trigger is the same; only the caller moved.
 *
 * WHERE THE IDS LIVE, and why it matters:
 *
 *   employees.tcp_employee_id is a PROJECTION column. It is whatever hiring
 *   sent us, and a replay overwrites it.
 *
 *   integration_identities is SCHEDULING-OWNED. An id WE obtained by calling
 *   TCP goes there, because a projection rebuild would erase it from the
 *   employees row and the next sync would create the person in TCP a second
 *   time.
 *
 * resolve() reads the owned table first and falls back to the projected column,
 * so the day hiring does start populating employee_ids, this keeps working.
 *
 * TERMINATION IS AN UPDATE, NOT A DELETE. Hiring publishes no employee.deleted
 * event — someone leaving arrives as employee.updated carrying a terminated or
 * resigned status. Approved hours and closed shifts still have to resolve to a
 * person for payroll long after the account is gone, so this sends TCP an
 * inactive flag and never DELETE /employees/{id}.
 *
 * FIELD NAMES ARE UNCONFIRMED — Figures 1-11 are unreadable images. Everything
 * on the wire is built in wireBody() and nowhere else.
 */
class TcpEmployeeWriter
{
    public function __construct(private readonly \App\Support\Integrations\Tcp\TcpClient $tcp) {}

    /**
     * The TCP ids for an employee, or null if they have never been synced.
     *
     * @return array{external_id: string, external_record_id: ?string}|null
     */
    public static function resolve(Employee $employee): ?array
    {
        $owned = IntegrationIdentity::query()
            ->where('entity_type', IntegrationEntityType::Employee)
            ->where('entity_id', $employee->id)
            ->where('system', IntegrationSystem::Tcp)
            ->whereNotNull('external_id')
            ->first();

        if ($owned !== null) {
            return [
                'external_id' => (string) $owned->external_id,
                'external_record_id' => $owned->external_record_id,
            ];
        }

        // Fallback: hiring's own employee_ids, projected onto the employee row.
        if ($employee->tcp_employee_id !== null) {
            return [
                'external_id' => (string) $employee->tcp_employee_id,
                'external_record_id' => $employee->tcp_employee_record_id,
            ];
        }

        return null;
    }

    /**
     * Create the employee in TCP, or update the record already there.
     *
     * Idempotent by construction: the identity row is what decides create vs
     * update, and it is only written after TCP has confirmed the id.
     */
    public function sync(Employee $employee): IntegrationIdentity
    {
        $identity = $this->identityRow($employee);

        try {
            if ($identity->external_id === null) {
                $response = $this->tcp->createEmployee($this->wireBody($employee));
                $ids = $this->extractIds($response);

                if ($ids['external_id'] === null) {
                    // No id back means we cannot address this person again.
                    // Leave the row failed so the retry command picks it up
                    // rather than pretending the create worked.
                    return $this->markFailed($identity, 'TCP create returned no employee id.');
                }

                $identity->external_id = $ids['external_id'];
                $identity->external_record_id = $ids['external_record_id'];
            } else {
                $this->tcp->updateEmployee($identity->external_id, $this->wireBody($employee));
            }

            $identity->forceFill([
                'sync_state' => IntegrationSyncState::Synced,
                'synced_at' => now(),
                'last_error' => null,
                'attempts' => 0,
            ])->save();

            return $identity;
        } catch (IntegrationException $e) {
            $failed = $this->markFailed($identity, $e->getMessage());

            if ($e->isTransient()) {
                throw $e;
            }

            return $failed;
        }
    }

    /** The row that records our mapping, created pending on first sight. */
    private function identityRow(Employee $employee): IntegrationIdentity
    {
        return IntegrationIdentity::query()->firstOrCreate(
            [
                'entity_type' => IntegrationEntityType::Employee,
                'entity_id' => $employee->id,
                'system' => IntegrationSystem::Tcp,
            ],
            ['sync_state' => IntegrationSyncState::Pending],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function wireBody(Employee $employee): array
    {
        $terminated = in_array(
            $employee->current_status,
            [EmployeeStatus::Terminated, EmployeeStatus::Resigned],
            true,
        );

        return array_filter([
            'firstName' => $employee->first_name,
            'middleName' => $employee->middle_name,
            'lastName' => $employee->last_name,
            'email' => $employee->primary_email,
            'phone' => $employee->primary_phone,
            'birthDate' => $employee->birth_date?->toDateString(),
            // Sent, not omitted: flipping somebody back to active on a rehire
            // has to be expressible, and array_filter would eat a false.
            'isActive' => ! $terminated,
            'terminationDate' => $terminated
                ? $employee->current_status_effective_date?->toDateString()
                : null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param  array<mixed>  $response
     * @return array{external_id: ?string, external_record_id: ?string}
     */
    private function extractIds(array $response): array
    {
        $pick = function (array $keys) use ($response): ?string {
            foreach ($keys as $key) {
                $value = data_get($response, $key)
                    ?? data_get($response, "0.{$key}")
                    ?? data_get($response, "data.{$key}");

                if (is_scalar($value) && (string) $value !== '') {
                    return (string) $value;
                }
            }

            return null;
        };

        // "store employeeId and employeeRecordId for future use" — both, and
        // they are different things: the record id addresses one employment
        // record, the employee id addresses the person.
        return [
            'external_id' => $pick(['employeeId', 'employee_id', 'id']),
            'external_record_id' => $pick(['employeeRecordId', 'employee_record_id', 'recordId']),
        ];
    }

    private function markFailed(IntegrationIdentity $identity, string $error): IntegrationIdentity
    {
        $identity->forceFill([
            'sync_state' => IntegrationSyncState::Failed,
            'attempts' => (int) $identity->attempts + 1,
            'last_error' => $error,
        ])->save();

        Log::warning('TCP employee sync failed', [
            'employee_id' => $identity->entity_id,
            'external_id' => $identity->external_id,
            'error' => $error,
        ]);

        return $identity;
    }
}
