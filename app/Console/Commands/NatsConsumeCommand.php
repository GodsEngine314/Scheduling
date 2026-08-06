<?php

namespace App\Console\Commands;

use App\Services\EventConsume\JetStreamConsumer;
use Illuminate\Console\Command;

class NatsConsumeCommand extends Command
{
    protected $signature = 'nats:consume';
    protected $description = 'Consume auth + hiring JetStream events into the scheduling projections';

    public function handle(JetStreamConsumer $consumer): int
    {
        $this->info('Starting JetStream consumer...');
        $consumer->runForever();
        return self::SUCCESS;
    }
}
