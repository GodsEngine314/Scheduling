<?php

namespace App\Jobs;

use App\Models\SchedulingOutboxEvent;
use App\Services\Nats\JetStreamPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishOutboxEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public function __construct(public string $outboxEventId)
    {
    }

    public function handle(JetStreamPublisher $publisher): void
    {
        $event = SchedulingOutboxEvent::find($this->outboxEventId);

        if (!$event)
            return;

        if ($event->published_at)
            return;

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

            throw $e; // let Laravel retry
        }
    }

    public function failed(\Throwable $e): void
    {
        SchedulingOutboxEvent::query()
            ->where('id', $this->outboxEventId)
            ->update([
                'last_error' => $e->getMessage(),
            ]);
    }
}
