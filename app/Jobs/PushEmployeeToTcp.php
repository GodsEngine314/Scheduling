<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Services\Scheduling\TcpEmployeeWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Push one employee to TCP, out of band from the event that changed them.
 *
 * Dispatched by the projector after it upserts an employee, so hiring's
 * employee.created and employee.updated both land here. Queued rather than
 * inline because the JetStream consumer must ack promptly: a vendor timeout
 * inside the handler would nack the event, redeliver it, and eventually park
 * it — losing the projection update over a problem with a different system.
 *
 * WithoutOverlapping on the employee id: two workers racing a first sync would
 * both see no identity row and each POST, creating the person in TCP twice.
 */
class PushEmployeeToTcp implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @var array<int,int> seconds */
    public array $backoff = [30, 120, 300, 600, 1200];

    public function __construct(public readonly int $employeeId) {}

    /** @return array<int,object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('tcp-employee:'.$this->employeeId))->releaseAfter(30)];
    }

    public function handle(TcpEmployeeWriter $writer): void
    {
        $employee = Employee::find($this->employeeId);

        if ($employee === null) {
            return;
        }

        $writer->sync($employee);
    }
}
