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

    private const JOB_CODES_PATH = '/jobcodes';

    /**
     * WHICH PEOPLE HOLD WHICH CODES. Undocumented — neither /employees nor
     * /jobcodes carries the assignment, and /employees/{id}/jobcodes answers
     * 403. This spelling was found by probing and confirmed against live data.
     */
    private const EMPLOYEE_JOB_CODES_PATH = '/employeejobcodes';

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
        $headers = [];

        // The gateway key is not the bearer token and does not replace it: the
        // token says who is calling, the key says this application may reach
        // the gateway at all. Both go on every request.
        $apiKey = trim((string) (config('tcp.api_key') ?? ''));

        if ($apiKey !== '') {
            $headers[(string) config('tcp.api_key_header', 'x-api-key')] = $apiKey;
        }

        $customerId = trim((string) (config('tcp.customer_id') ?? ''));

        // An empty value means the header is not part of this tenant's
        // contract. Sending it blank is a different request from not sending
        // it, and some gateways reject the blank one.
        if ($customerId !== '') {
            $headers[(string) config('tcp.customer_header', 'X-Customer-Id')] = $customerId;
        }

        return $headers;
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
     * Every job code TCP holds — all pages, no filter.
     *
     * READ-ONLY, and unfiltered on purpose: the endpoint takes no location
     * parameter, and the store is encoded in the code itself. A job code reads
     *
     *     jobCodeId 37954202  "Crew Leader - 3795-42"
     *                ^^^^ ^^ ^^
     *                3795 42 02   franchise, store, role
     *
     * so the whole list is what tells you which position a punch's jobCodeId
     * means. There are roughly six per store plus a handful of company-wide
     * ones, which is one request at any sane page size.
     *
     * @return array<int,array<string,mixed>>
     */
    public function jobCodes(): array
    {
        $perPage = $this->pageSize();
        $records = [];
        $page = 1;

        while (true) {
            if ($page > self::MAX_PAGES) {
                throw IntegrationException::guard(
                    'tcp',
                    $this->endpoint(self::JOB_CODES_PATH),
                    'Pagination on '.self::JOB_CODES_PATH.' passed '.self::MAX_PAGES.' pages; the page parameter is being ignored.',
                );
            }

            $batch = $this->records($this->get(self::JOB_CODES_PATH, [
                'page' => $page,
                'perPage' => $perPage,
            ]));

            if ($batch === []) {
                break;
            }

            $records = array_merge($records, $batch);

            // A short page is the last page — the same end-of-list signal
            // paginate() uses, for the same reason: no trustworthy total.
            if (count($batch) < $perPage) {
                break;
            }

            $page++;
        }

        return $records;
    }

    /**
     * The job codes TCP has assigned to these people.
     *
     * THE LOOKUP THAT REPLACED A DROPDOWN. A punch needs a jobCodeId, and it
     * used to be assembled from a position a manager picked — franchise + store
     * + role, on the hope that TCP had that combination. It frequently did not.
     * This is TCP's own answer to the same question, and its timeclock files
     * hours against exactly these assignments.
     *
     * TWO KINDS OF ROW COME BACK TOGETHER and only their shape separates them:
     *
     *     37951001   "Crew Member - 3795-10"    a per-store ROLE, 8 digits
     *     1003       "Bonus"                    a company-wide PAY CATEGORY
     *
     * Both are returned here unfiltered; deciding which may be sent as a punch's
     * job code is TcpEmployeeJobCodeReader's job, and it is a distinction worth
     * making explicitly rather than inside a client.
     *
     * SCOPED BY PEOPLE, not by store, because that is the only filter the
     * endpoint takes — and the 20-value cap applies here as everywhere else, so
     * the filter is chunked. A store's roster is turned into ids by the caller.
     *
     * @return array<int,array<string,mixed>>
     */
    public function employeeJobCodes(EmployeeFilter $filter): array
    {
        $perPage = $this->pageSize();
        $records = [];

        foreach ($filter->chunked() as $chunk) {
            $records = array_merge(
                $records,
                $this->paginate(self::EMPLOYEE_JOB_CODES_PATH, $chunk->withPerPage($perPage)),
            );
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
