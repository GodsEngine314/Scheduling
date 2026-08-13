<?php

namespace App\Support\Integrations\Tcp;

use App\DataTransferObjects\EmployeeFilter;
use App\DataTransferObjects\WorkSegmentFilter;
use App\Exceptions\IntegrationException;
use App\Support\Integrations\AbstractApiClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TimeClock Plus On-Demand.
 *
 * TCP owns punches. Scheduling reads work segments out of it and writes back
 * only the two things a manager does by hand — creating a segment for someone
 * who forgot to clock in, and correcting times. It never pushes the schedule.
 *
 * EVERY PATH AND FIELD NAME IN THIS CLASS IS A GUESS. The source document's
 * field tables are images that could not be read, so what is here is inferred
 * from the surrounding prose and from convention. The class is deliberately
 * tolerant about what it accepts back (see records()) and explicit about what
 * it sends, so that the first live response corrects one small place rather
 * than the whole file.
 */
class TcpClient extends AbstractApiClient
{
    /** GUESS: resource paths. */
    private const EMPLOYEES_PATH = '/employees';

    private const WORK_SEGMENTS_PATH = '/worksegments';

    /**
     * Pagination circuit breaker. At the configured page size this is far more
     * segments than any real filter can produce, so tripping it means the
     * vendor is ignoring our page parameter and serving page 1 forever. Better
     * to fail loudly than to fill memory with the same 200 rows.
     */
    private const MAX_PAGES = 500;

    protected function integration(): string
    {
        return 'tcp';
    }

    protected function authDescriptor(): array
    {
        $mode = (string) config('tcp.auth_mode', 'oauth');

        if (! in_array($mode, ['oauth', 'static'], true)) {
            throw IntegrationException::configuration('tcp', "Unknown tcp.auth_mode '{$mode}'; expected 'oauth' or 'static'.");
        }

        $staticToken = (string) (config('tcp.static_token') ?? '');

        if ($mode === 'static' && $staticToken === '') {
            throw IntegrationException::configuration('tcp', "tcp.auth_mode is 'static' but tcp.static_token is empty.");
        }

        return [
            'mode' => $mode,
            'transport' => 'header',
            'header' => (string) config('tcp.auth_header', 'Authorization'),
            'prefix' => (string) config('tcp.auth_prefix', 'Bearer'),
            'param' => null,
            'token' => $mode === 'static' ? $staticToken : null,
        ];
    }

    /**
     * @return array<string,string>
     */
    protected function defaultHeaders(): array
    {
        $customerId = (string) (config('tcp.customer_id') ?? '');

        // An empty customer id means the header is not part of this tenant's
        // contract. Sending it blank is a different request from not sending
        // it, and some gateways reject the blank one.
        if ($customerId === '') {
            return [];
        }

        return [(string) config('tcp.customer_header', 'X-Customer-Id') => $customerId];
    }

    /**
     * @param  array<string,mixed>  $employee
     * @return array<mixed>
     */
    public function createEmployee(array $employee): array
    {
        return $this->post(self::EMPLOYEES_PATH, $this->repeatable($employee));
    }

    /**
     * @param  array<string,mixed>  $employee
     * @return array<mixed>
     */
    public function updateEmployee(int|string $id, array $employee): array
    {
        // A PUT addresses one id, so the body is the bare object rather than
        // the repeatable list a create sends. GUESS: TCP may want POST here.
        return $this->put(self::EMPLOYEES_PATH.'/'.rawurlencode((string) $id), $employee);
    }

    /**
     * @return array<mixed>
     */
    public function deleteEmployee(int|string $id): array
    {
        return $this->delete(self::EMPLOYEES_PATH.'/'.rawurlencode((string) $id));
    }

    /**
     * Every work segment the filter matches — all chunks, all pages.
     *
     * The caller passes one filter and gets one flat list back. Whether that
     * took one request or twelve is this class's problem: a caller that had to
     * remember the 20-value cap would eventually forget it, and a forgotten
     * cap is silently missing punches.
     *
     * @return array<int,array<string,mixed>>
     */
    public function workSegments(WorkSegmentFilter $filter): array
    {
        $perPage = $this->pageSize();
        $records = [];

        foreach ($filter->chunked() as $chunk) {
            $records = array_merge($records, $this->paginate(self::WORK_SEGMENTS_PATH, $chunk->withPerPage($perPage)));
        }

        return $records;
    }

    /**
     * Every employee the filter matches — all chunks, all pages.
     *
     * READ-ONLY, and the mirror image of createEmployee/updateEmployee: this
     * asks TCP who it thinks works somewhere, it does not tell it. Used to
     * reconcile a store's roster, which is why it is scoped by location.
     *
     * @return array<int,array<string,mixed>>
     */
    public function employees(EmployeeFilter $filter): array
    {
        $perPage = $this->pageSize();
        $records = [];

        foreach ($filter->chunked() as $chunk) {
            $records = array_merge($records, $this->paginate(self::EMPLOYEES_PATH, $chunk->withPerPage($perPage)));
        }

        return $records;
    }

    /**
     * @param  array<string,mixed>  $segment
     * @return array<mixed>
     */
    public function createWorkSegment(array $segment): array
    {
        return $this->post(self::WORK_SEGMENTS_PATH, $this->repeatable($segment));
    }

    /**
     * @param  array<string,mixed>  $segment
     * @return array<mixed>
     */
    public function updateWorkSegment(int|string $id, array $segment): array
    {
        return $this->put(self::WORK_SEGMENTS_PATH.'/'.rawurlencode((string) $id), $segment);
    }

    /**
     * @return array<mixed>
     */
    public function deleteWorkSegment(int|string $id): array
    {
        return $this->delete(self::WORK_SEGMENTS_PATH.'/'.rawurlencode((string) $id));
    }

    /**
     * Pull the list of records out of the response envelope.
     *
     * CONFIRMED: TCP answers {data, errors, meta}. A collection endpoint puts a
     * LIST in data; a by-id endpoint puts a single OBJECT there, which is
     * wrapped so both shapes read the same to a caller.
     *
     * The `results` / `items` / bare-list spellings this used to accept were
     * scaffolding for an unseen payload and are gone. The fallback below is
     * kept only because a proxy or an error page can still put something else
     * on the wire.
     *
     * @param  array<mixed>  $response
     * @return array<int,array<string,mixed>>
     */
    public function records(array $response): array
    {
        if (array_key_exists('data', $response)) {
            $data = $response['data'];

            if (! is_array($data)) {
                return [];
            }

            // A by-id response: one record, not a list of them.
            return array_is_list($data)
                ? array_values(array_filter($data, 'is_array'))
                : [$data];
        }

        if ($response === []) {
            return [];
        }

        if (array_is_list($response)) {
            return array_values(array_filter($response, 'is_array'));
        }

        // An unrecognised envelope reaches here and is treated as one record.
        // That is wrong but safe: the pagination loop stops on a short page, so
        // the mistake costs one bogus row rather than an infinite loop. The
        // warning is how anyone finds out — key names only, no values.
        $this->warnUnrecognisedEnvelope($response);

        return [$response];
    }

    /**
     * Walk one filter's pages to the end.
     *
     * @return array<int,array<string,mixed>>
     */
    private function paginate(string $path, WorkSegmentFilter|EmployeeFilter $filter): array
    {
        $perPage = $filter->perPage ?? $this->pageSize();
        $records = [];
        $page = 1;

        while (true) {
            if ($page > self::MAX_PAGES) {
                throw IntegrationException::guard(
                    'tcp',
                    $this->endpoint($path),
                    'Pagination on '.$path.' passed '.self::MAX_PAGES.' pages; the page parameter is being ignored.',
                );
            }

            $batch = $this->records($this->get($path, $filter->withPage($page)->toQuery()));

            if ($batch === []) {
                break;
            }

            $records = array_merge($records, $batch);

            // A short page is the last page. There is no total in the response
            // we can trust, so the count is the only end-of-list signal.
            if (count($batch) < $perPage) {
                break;
            }

            $page++;
        }

        return $records;
    }

    /**
     * What we ask for per page, kept inside the API's own ceiling.
     */
    private function pageSize(): int
    {
        $configured = (int) config('tcp.pagination.page_size', 0);
        $default = (int) config('tcp.pagination.per_page_default', 50);
        $max = (int) config('tcp.pagination.per_page_max', 1000);

        return max(1, min($configured > 0 ? $configured : $default, $max));
    }

    /**
     * Wrap a record as a single-element list.
     *
     * The document's figures show the create body as a REPEATABLE object, so
     * one record goes out as [{...}] rather than {...}. If a tenant turns out
     * to want the bare object, this method is the only place to change — every
     * create routes through it.
     *
     * @param  array<string,mixed>  $record
     * @return array<int,array<string,mixed>>
     */
    private function repeatable(array $record): array
    {
        return [$record];
    }

    /**
     * @param  array<mixed>  $response
     */
    private function warnUnrecognisedEnvelope(array $response): void
    {
        try {
            Log::warning('tcp.response.unrecognised_envelope', [
                'integration' => 'tcp',
                'keys' => array_keys($response),
            ]);
        } catch (Throwable) {
            // Never worth failing a sync over.
        }
    }
}
