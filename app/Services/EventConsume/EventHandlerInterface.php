<?php

namespace App\Services\EventConsume;

interface EventHandlerInterface
{
    public function handle(array $event): void;
}
