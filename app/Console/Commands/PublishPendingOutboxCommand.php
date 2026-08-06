<?php

namespace App\Console\Commands;

use App\Models\SchedulingOutboxEvent;
use App\Services\Nats\JetStreamPublisher;
use Illuminate\Console\Command;

class PublishPendingOutboxCommand extends Command
{
    protected $signature = 'outbox:publish-pending {--chunk=100}';
    protected $description = 'Publish unpublished scheduling outbox events directly';

    public function handle(JetStreamPublisher $publisher): int
    {
        $chunkSize = (int) $this->option('chunk');

        SchedulingOutboxEvent::query()
            ->whereNull('published_at')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($events) use ($publisher) {
                foreach ($events as $event) {
                    try {
                        $publisher->publish($event->subject, $event->payload);

                        $event->update([
                            'published_at' => now(),
                            'last_error' => null,
                        ]);
                    } catch (\Throwable $e) {
                        $event->increment('attempts');

                        $event->update([
                            'last_error' => $e->getMessage(),
                        ]);

                        $this->error("Failed to publish outbox event {$event->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info('Pending outbox events processed.');

        return self::SUCCESS;
    }
}
