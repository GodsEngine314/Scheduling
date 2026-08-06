<?php

namespace App\Services\SchedulingEvents;

use App\Models\SchedulingOutboxEvent;

/**
 * Call this inside the same DB::transaction as the state change being reported:
 * no published event without the row it describes, and no row silently unpublished.
 */
class SchedulingOutboxService
{
    public function record(string $subject, array $payload): SchedulingOutboxEvent
    {
        $subject = $this->applyEnvironmentPrefix($subject);

        return SchedulingOutboxEvent::create([
            'subject' => $subject,
            'type' => $subject,
            'payload' => $payload,
        ]);
    }

    private function applyEnvironmentPrefix(string $subject): string
    {
        if (!config('nats.dev_mode')) {
            return $subject;
        }

        if (str_starts_with($subject, 'scheduling.v1.')) {
            return str_replace('scheduling.v1.', 'scheduling.testing.v1.', $subject);
        }

        return $subject;
    }
}
