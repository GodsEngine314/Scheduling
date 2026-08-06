<?php

namespace App\Services\SchedulingEvents;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Builds the CloudEvents envelope for everything this service publishes.
 * Same shape as HiringPizza's HiringEventFactory so consumers can read both
 * domains with one parser.
 */
class SchedulingEventFactory
{
    public function make(
        string $type,
        array $data,
        ?Request $request = null,
        array $metaOverrides = []
    ): array {

        $type = $this->applyEnvironmentPrefix($type);

        $now = now()->utc()->toIso8601String();

        $meta = array_merge([
            'correlation_id' => $request?->headers->get('X-Correlation-Id') ?? (string) Str::uuid(),
            'causation_id' => $request?->headers->get('X-Causation-Id'),
            'actor_user_id' => optional($request?->user())->id,
            'actor_type' => $request?->user() ? 'user' : 'service_client',
            'actor_ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ], $metaOverrides);

        return [
            'specversion' => '1.0',
            'id' => (string) Str::ulid(),
            'type' => $type,
            'source' => 'scheduling-system',
            'subject' => $type,
            'time' => $now,
            'datacontenttype' => 'application/json',
            'data' => $data,
            'meta' => $meta,
        ];
    }

    /**
     * Scheduling publishes its own facts and nothing else, so only the
     * scheduling domain is rewritten here.
     */
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
