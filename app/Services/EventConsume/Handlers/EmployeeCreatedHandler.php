<?php

namespace App\Services\EventConsume\Handlers;

use App\Services\EventConsume\EventHandlerInterface;

/**
 * hiring.v1.employee.created carries the full employee graph, exactly as
 * employee.updated does, so the two are identical in effect. See EmployeeProjector.
 */
class EmployeeCreatedHandler implements EventHandlerInterface
{
    public function __construct(private readonly EmployeeProjector $projector)
    {
    }

    public function handle(array $event): void
    {
        $this->projector->project($event);
    }
}
