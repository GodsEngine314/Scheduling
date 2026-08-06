<?php

namespace App\Services\EventConsume;

use Exception;

/**
 * Scheduling consumes TWO domains, not one: auth owns users and stores,
 * hiring owns employees and the positions carried inside them.
 *
 * Under nats.dev_mode both domains move to their .testing. variants together —
 * a mixed pair would silently half-consume production traffic.
 */
class EventRouter
{

    private array $map;
    public function __construct()
    {
        $devMode = (bool) config('nats.dev_mode');

        $authPrefix = $devMode
            ? 'auth.testing.v1'
            : 'auth.v1';

        $hiringPrefix = $devMode
            ? 'hiring.testing.v1'
            : 'hiring.v1';


        $this->map = [
            // USERS (auth)
            "{$authPrefix}.user.created" => \App\Services\EventConsume\Handlers\UserCreatedHandler::class,
            "{$authPrefix}.user.updated" => \App\Services\EventConsume\Handlers\UserUpdatedHandler::class,
            "{$authPrefix}.user.deleted" => \App\Services\EventConsume\Handlers\UserDeletedHandler::class,

            // STORES (auth)
            "{$authPrefix}.store.created" => \App\Services\EventConsume\Handlers\StoreCreatedHandler::class,
            "{$authPrefix}.store.updated" => \App\Services\EventConsume\Handlers\StoreUpdatedHandler::class,
            "{$authPrefix}.store.deleted" => \App\Services\EventConsume\Handlers\StoreDeletedHandler::class,

            // EMPLOYEES (hiring). Both subjects carry the full graph and run the
            // same projector; hiring publishes no employee.deleted.
            "{$hiringPrefix}.employee.created" => \App\Services\EventConsume\Handlers\EmployeeCreatedHandler::class,
            "{$hiringPrefix}.employee.updated" => \App\Services\EventConsume\Handlers\EmployeeUpdatedHandler::class,
        ];
    }
    public function getResolvedMap(): array
    {
        return $this->map;
    }
    public function resolve(string $subject): string
    {
        if (!isset($this->map[$subject])) {
            throw new Exception("No handler for subject '{$subject}'");
        }

        return $this->map[$subject];
    }
}
