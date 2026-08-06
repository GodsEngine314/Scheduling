<?php

namespace App\Services\EventConsume\Handlers;

use App\Services\EventConsume\EventHandlerInterface;

/**
 * hiring.v1.employee.updated carries the full employee graph, exactly as
 * employee.created does, so the two are identical in effect. See EmployeeProjector.
 */
class EmployeeUpdatedHandler implements EventHandlerInterface
{
    public function __construct(private readonly EmployeeProjector $projector)
    {
    }

    public function handle(array $event): void
    {
        $this->projector->project($event);
    }
}
