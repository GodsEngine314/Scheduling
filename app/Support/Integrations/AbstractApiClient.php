<?php

namespace App\Support\Integrations;

use App\Exceptions\IntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared transport for the vendor clients: auth, retry, correlation, logging.
 *
 * Everything a subclass has to say about itself is said twice at most — its
 * config namespace (integration()) and how its token rides along
 * (authDescriptor()). The two vendors' config files are shaped differently
 * enough that reading them generically here would mean inventing keys that do
 * not exist in one of them, so each subclass reads its own file and hands back
 * a descriptor this class knows how to apply.
 *
 * RETRY POLICY, and why it is what it is:
 *   429 / 5xx  — repeat, up to retry.attempts, with the configured backoff.
 *                The same request may well succeed once the vendor recovers.
 *   401        — clear the cached token and try exactly once more. This does
 *                not spend the retry budget, because it is a repair rather
 *                than a hope.
 *   other 4xx  — return immediately. A bad id or a malformed body will be
 *                rejected identically on the second attempt and the third.
 *   no response — treated as 429/5xx: the request may never have arrived.
 *
 * LOGGING: endpoint, method, status, duration, correlation id, and the KEY
 * NAMES of the request body and query. Never a value. These payloads carry
 * addresses, birth dates and pay rates, and a log file is the wrong place for
 * all three. A failure inside the logging path degrades to a warning; it never
 * takes down the request it was trying to describe.
 */
abstract class AbstractApiClient
{
    /**
     * GUESS: neither vendor documents a correlation header, but sending one
     * costs nothing and it is the only way to line our log up with theirs when
     * something goes wrong.
     */
    public const CORRELATION_HEADER = 'X-Correlation-Id';

    public function __construct(protected readonly TokenProvider $tokens) {}

    /** Config namespace and log label: 'tcp' or 'humanity'. */
    abstract protected function integration(): string;

    /**
     * How this vendor wants its token presented.
     *
     * @return array{
     *     mode: string,
     *     transport: string,
     *     header: string|null,
     *     prefix: string|null,
     *     param: string|null,
     *     token: string|null,
     * }
     */
    abstract protected function authDescriptor(): array;

    /**
     * Headers every call to this vendor carries, auth aside.
     *
     * @return array<string,string>
     */
    protected function defaultHeaders(): array
    {
        return [];
    }

    /**
     * How this vendor wants a request body encoded: 'json' or 'form'.
     *
     * JSON by default because TCP takes JSON, and because a wrong default that
     * a vendor rejects outright is safer than one it accepts and misreads.
     *
     * Humanity overrides it. Its every endpoint is documented
     * application/x-www-form-urlencoded, and the failure mode when this is wrong
     * is nasty rather than obvious: the vendor answers 200 with a body that
     * looks fine, having parsed NO parameters out of the JSON at all — so a
     * create reports success and no shift exists.
     */
    protected function bodyFormat(): string
    {
        return 'json';
    }

    /**
     * @param  array<string,mixed>  $query
     * @return array<mixed>
     */
    protected function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * @param  array<mixed>  $body
     * @param  array<string,mixed>  $query
     * @return array<mixed>
     */
    protected function post(string $path, array $body = [], array $query = []): array
    {
        return $this->request('POST', $path, $query, $body);
    }

    /**
     * @param  array<mixed>  $body
     * @param  array<string,mixed>  $query
     * @return array<mixed>
     */
    protected function put(string $path, array $body = [], array $query = []): array
    {
        return $this->request('PUT', $path, $query, $body);
    }

    /**
     * @param  array<string,mixed>  $query
     * @param  array<mixed>  $body
     * @return array<mixed>
     */
    protected function delete(string $path, array $query = [], array $body = []): array
    {
        return $this->request('DELETE', $path, $query, $body === [] ? null : $body);
    }

    /**
     * @param  array<string,mixed>  $query
     * @param  array<mixed>|null  $body
     * @return array<mixed>
     *
     * @throws IntegrationException
     */
    protected function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $correlationId = (string) Str::uuid();
        $endpoint = $this->endpoint($path);
        $maxAttempts = $this->maxAttempts();

        $attempt = 1;
        $tokenRefreshed = false;

        while (true) {
            $startedAt = microtime(true);

            try {
                $response = $this->send($method, $path, $query, $body, $correlationId);
            } catch (ConnectionException $e) {
                $this->logCall($method, $endpoint, null, $startedAt, $correlationId, $query, $body);

                if ($attempt < $maxAttempts) {
                    $this->backOff($attempt++);

                    continue;
                }

                throw IntegrationException::connectionFailure(
                    $this->integration(),
                    $method,
                    $endpoint,
                    $correlationId,
                    $e,
                );
            } catch (IntegrationException $e) {
                /**
                 * THE TOKEN FETCH IS INSIDE send(), AND IT WAS NOT RETRYABLE.
                 *
                 * TokenProvider does its own HTTP and wraps a failure as an
                 * IntegrationException, which is not a ConnectionException — so
                 * it sailed straight past the catch above and out of this loop.
                 * A DNS blip while fetching a token therefore failed the whole
                 * publish on the first attempt, with retry.attempts sitting at 3
                 * and never being spent. Observed twice against the live vendor:
                 * "could not be reached", then the identical call succeeding
                 * seconds later.
                 *
                 * isTransient() is what decides. A REJECTED CREDENTIAL is not
                 * transient and must not be retried — repeating a bad login is
                 * how an account gets locked — so credentialsRejected() rethrows
                 * here immediately and only the reachability failures loop.
                 */
                if (! $e->isTransient() || $attempt >= $maxAttempts) {
                    throw $e;
                }

                $this->backOff($attempt++);

                continue;
            }

            $status = $response->status();
            $this->logCall($method, $endpoint, $status, $startedAt, $correlationId, $query, $body);

            if ($response->successful()) {
                return $this->decode($response);
            }

            // The vendor says our token is no good. Believing the cache over
            // that answer only produces a second 401, so drop it and go again
            // with a fresh one — once. A second 401 is a credentials problem,
            // not a staleness problem, and looping would not fix it.
            if ($status === 401 && ! $tokenRefreshed && $this->authDescriptor()['mode'] === 'oauth') {
                $this->tokens->forget($this->integration());
                $tokenRefreshed = true;

                continue;
            }

            if (($status === 429 || $status >= 500) && $attempt < $maxAttempts) {
                $this->backOff($attempt++);

                continue;
            }

            throw IntegrationException::fromResponse(
                $this->integration(),
                $method,
                $endpoint,
                $status,
                $correlationId,
                $response->body(),
            );
        }
    }

    /**
     * @param  array<string,mixed>  $query
     * @param  array<mixed>|null  $body
     */
    private function send(string $method, string $path, array $query, ?array $body, string $correlationId): Response
    {
        $auth = $this->authDescriptor();

        $headers = $this->defaultHeaders();
        $headers[static::CORRELATION_HEADER] = $correlationId;

        $token = $auth['mode'] === 'static'
            ? (string) $auth['token']
            : $this->tokens->token($this->integration());

        if ($auth['transport'] === 'query') {
            $query[(string) $auth['param']] = $token;
        } else {
            $headers[(string) $auth['header']] = trim(((string) $auth['prefix']).' '.$token);
        }

        $request = Http::baseUrl($this->baseUri())
            ->timeout($this->timeout())
            ->acceptJson()
            ->withHeaders($headers);

        if ($this->bodyFormat() === 'form') {
            $request = $request->asForm();
        }

        if ($query !== []) {
            $request = $request->withQueryParameters($query);
        }

        return match (strtoupper($method)) {
            'GET' => $request->get($path),
            'POST' => $request->post($path, $body ?? []),
            'PUT' => $request->put($path, $body ?? []),
            'PATCH' => $request->patch($path, $body ?? []),
            // A body on a DELETE, which is unusual and is what Humanity's
            // documented `rule` parameter needs. Laravel omits it entirely when
            // the array is empty, so this stays a bare DELETE for TCP.
            'DELETE' => $request->delete($path, $body ?? []),
            default => throw IntegrationException::guard(
                $this->integration(),
                $this->endpoint($path),
                "Unsupported HTTP method '{$method}'.",
            ),
        };
    }

    /**
     * A 204 or an empty body is a success with nothing to say, and callers
     * should not have to null-check for it. A scalar body is wrapped so the
     * return type stays honest.
     *
     * @return array<mixed>
     */
    protected function decode(Response $response): array
    {
        $decoded = $response->json();

        if (is_array($decoded)) {
            return $decoded;
        }

        return $decoded === null ? [] : ['data' => $decoded];
    }

    protected function endpoint(string $path): string
    {
        return $this->baseUri().'/'.ltrim($path, '/');
    }

    protected function baseUri(): string
    {
        $baseUri = rtrim((string) config($this->integration().'.base_uri', ''), '/');

        if ($baseUri === '') {
            throw IntegrationException::configuration(
                $this->integration(),
                $this->integration().'.base_uri is not set.',
            );
        }

        return $baseUri;
    }

    protected function timeout(): int
    {
        return max(1, (int) config($this->integration().'.timeout', 30));
    }

    protected function maxAttempts(): int
    {
        return max(1, (int) config($this->integration().'.retry.attempts', 3));
    }

    /**
     * Wait before attempt N+1. The configured list is indexed by the attempt
     * that just failed; a list shorter than the attempt count reuses its last
     * value rather than falling through to no delay at all.
     */
    protected function backOff(int $failedAttempt): void
    {
        $backoff = array_values(array_map(
            static fn (mixed $ms): int => max(0, (int) $ms),
            (array) config($this->integration().'.retry.backoff_ms', [500, 1000, 2000]),
        ));

        if ($backoff === []) {
            return;
        }

        $milliseconds = $backoff[$failedAttempt - 1] ?? $backoff[count($backoff) - 1];

        // Illuminate's Sleep, not usleep(), so a test can fake the wait
        // instead of actually spending three and a half seconds on it.
        Sleep::usleep($milliseconds * 1000);
    }

    /**
     * @param  array<string,mixed>  $query
     * @param  array<mixed>|null  $body
     */
    protected function logCall(
        string $method,
        string $endpoint,
        ?int $status,
        float $startedAt,
        string $correlationId,
        array $query,
        ?array $body,
    ): void {
        try {
            $context = [
                'integration' => $this->integration(),
                'method' => strtoupper($method),
                'endpoint' => $endpoint,
                'status' => $status,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                'correlation_id' => $correlationId,
                'query_keys' => $this->keyNames($query),
                'body_keys' => $body === null ? [] : $this->keyNames($body),
            ];

            $status !== null && $status < 400
                ? Log::info('integration.request', $context)
                : Log::warning('integration.request', $context);
        } catch (Throwable) {
            // A broken log channel must not take down the call it was
            // describing. Try once more at warning level on the off chance the
            // failure was specific to the payload, then give up quietly.
            try {
                Log::warning('integration.request.log_failed', [
                    'integration' => $this->integration(),
                    'correlation_id' => $correlationId,
                ]);
            } catch (Throwable) {
                // Nothing left to try.
            }
        }
    }

    /**
     * The SHAPE of a payload, never its contents. 'employee[].birthDate' tells
     * you the mapping is wired up; the value would tell a log reader when
     * somebody was born.
     *
     * @param  array<mixed>  $data
     * @return array<int,string>
     */
    private function keyNames(array $data, string $prefix = ''): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            $label = is_int($key)
                ? $prefix.'[]'
                : ($prefix === '' ? $key : $prefix.'.'.$key);

            if (is_array($value)) {
                $keys = array_merge($keys, $this->keyNames($value, $label));

                continue;
            }

            $keys[] = $label;
        }

        return array_values(array_unique($keys));
    }
}
