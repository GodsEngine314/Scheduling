<?php

namespace App\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * One failure type for every outbound call to TCP or Humanity.
 *
 * isTransient() is the load-bearing part. It decides two separate things:
 *
 *   - whether the HTTP client retries the call in-flight, and
 *   - whether the queued job that wrapped the call is worth re-running.
 *
 * Getting it wrong is expensive in both directions. A 422 marked transient
 * burns three attempts and a job retry on a payload that will be rejected
 * identically every time; a 503 marked permanent parks a shift as failed
 * because Humanity was restarting.
 *
 * The response body is deliberately NOT in getMessage(). Vendor error bodies
 * echo the record you sent them, and the record you sent them contains birth
 * dates and pay rates. Laravel's exception handler logs getMessage(); it does
 * not log a property. A caller that wants the detail can read
 * $e->responseExcerpt and put it somewhere that is not a log file.
 */
class IntegrationException extends RuntimeException
{
    /** Vendor error bodies can be enormous; keep enough to identify the fault. */
    private const EXCERPT_LIMIT = 1000;

    /**
     * @param  string  $integration  'tcp' or 'humanity'
     * @param  int|null  $status  null when the request never got a response
     */
    final public function __construct(
        public readonly string $integration,
        public readonly string $method,
        public readonly string $endpoint,
        public readonly ?int $status,
        public readonly string $correlationId,
        private readonly bool $transient,
        string $message,
        public readonly ?string $responseExcerpt = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /**
     * The vendor answered, and the answer was not a success.
     *
     * 429 and 5xx are worth repeating unchanged. Every other 4xx is a fact
     * about the request — a bad id, a malformed payload, a revoked key — and
     * repeating it just produces the same rejection more slowly.
     */
    public static function fromResponse(
        string $integration,
        string $method,
        string $endpoint,
        int $status,
        string $correlationId,
        ?string $body = null,
    ): self {
        $transient = $status === 429 || $status >= 500;

        return new static(
            $integration,
            $method,
            $endpoint,
            $status,
            $correlationId,
            $transient,
            sprintf(
                '%s %s %s failed with HTTP %d (correlation %s).',
                $integration,
                strtoupper($method),
                $endpoint,
                $status,
                $correlationId,
            ),
            $body === null ? null : Str::limit($body, self::EXCERPT_LIMIT),
        );
    }

    /**
     * No response at all: DNS, TLS, refused connection, read timeout. Always
     * transient — the request may not even have reached the vendor.
     */
    public static function connectionFailure(
        string $integration,
        string $method,
        string $endpoint,
        string $correlationId,
        ?Throwable $previous = null,
    ): self {
        return new static(
            $integration,
            $method,
            $endpoint,
            null,
            $correlationId,
            true,
            sprintf(
                '%s %s %s could not be reached (correlation %s).',
                $integration,
                strtoupper($method),
                $endpoint,
                $correlationId,
            ),
            null,
            $previous,
        );
    }

    /**
     * We never left the building: a missing client secret, an auth mode nobody
     * implements. Retrying cannot fix a config file.
     */
    public static function configuration(string $integration, string $message): self
    {
        return new static(
            $integration,
            'CONFIG',
            '-',
            null,
            (string) Str::uuid(),
            false,
            $message,
        );
    }

    /**
     * A local safety rail tripped — the pagination circuit breaker, an
     * argument that would silently do the wrong thing at the vendor. Not
     * transient: the same call would trip it again.
     */
    public static function guard(string $integration, string $endpoint, string $message): self
    {
        return new static(
            $integration,
            'GUARD',
            $endpoint,
            null,
            (string) Str::uuid(),
            false,
            $message,
        );
    }

    /** Retry me, or park me? */
    public function isTransient(): bool
    {
        return $this->transient;
    }

    /**
     * Safe to log and safe to return in an API response: identifiers only, no
     * payload, no response body.
     *
     * @return array<string,mixed>
     */
    public function context(): array
    {
        return [
            'integration' => $this->integration,
            'method' => $this->method,
            'endpoint' => $this->endpoint,
            'status' => $this->status,
            'correlation_id' => $this->correlationId,
            'transient' => $this->transient,
        ];
    }
}
