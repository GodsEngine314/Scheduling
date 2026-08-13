<?php

namespace App\DataTransferObjects;

/**
 * The query side of GET /worksegments.
 *
 * Two things here are not cosmetic:
 *
 * THE CAP. Each of the five list filters accepts at most 20 values. A longer
 * list is not an error at the vendor — it is worse than an error, because the
 * extra values are dropped and the response looks perfectly normal. Forty-five
 * employee ids would come back as the punches of the first twenty and nobody
 * would notice until payroll. chunked() splits the filter instead, so 45 ids
 * become three requests whose results are unioned.
 *
 * THE CROSS PRODUCT. The filters combine with AND, so splitting two lists at
 * once means every combination of chunks has to be asked for: employees (A∪B)
 * AND jobs (C∪D) is (A,C), (A,D), (B,C), (B,D). The chunks are disjoint, so no
 * record can come back twice.
 *
 * GUESS: every parameter name below is inferred from the surrounding prose and
 * from API convention — the source document's field table is an image that
 * could not be read. Confirm them against a live request before trusting a
 * filtered result to be complete.
 */
final readonly class WorkSegmentFilter
{
    /**
     * @param  array<int,int|string>  $locationIds  TCP location ids, NOT our store ids
     * @param  array<int,int|string>  $employeeIds
     * @param  array<int,int|string>  $jobCodeIds
     * @param  array<int,string>  $costCodeNames
     * @param  array<int,string>  $laborCodes
     * @param  string|null  $startDate  Y-m-d
     * @param  string|null  $endDate  Y-m-d
     * @param  string|null  $updatedOnFrom  ISO-8601; drives the incremental sync
     * @param  string|null  $updatedOnTo  ISO-8601
     */
    public function __construct(
        public array $locationIds = [],
        public array $employeeIds = [],
        public array $jobCodeIds = [],
        public array $costCodeNames = [],
        public array $laborCodes = [],
        public ?string $startDate = null,
        public ?string $endDate = null,
        public ?string $updatedOnFrom = null,
        public ?string $updatedOnTo = null,
        public ?int $page = null,
        public ?int $perPage = null,
    ) {}

    public function withPage(int $page): self
    {
        return $this->with(['page' => $page]);
    }

    public function withPerPage(int $perPage): self
    {
        return $this->with(['perPage' => $perPage]);
    }

    /**
     * This filter split into as many filters as the vendor's 20-value cap
     * requires. A filter already within the cap yields exactly itself.
     *
     * @return iterable<int,self>
     */
    public function chunked(): iterable
    {
        $cap = max(1, (int) config('tcp.filter_value_cap', 20));

        foreach ($this->chunkValues($this->locationIds, $cap) as $locationIds) {
            foreach ($this->chunkValues($this->employeeIds, $cap) as $employeeIds) {
                foreach ($this->chunkValues($this->jobCodeIds, $cap) as $jobCodeIds) {
                    foreach ($this->chunkValues($this->costCodeNames, $cap) as $costCodeNames) {
                        foreach ($this->chunkValues($this->laborCodes, $cap) as $laborCodes) {
                            yield $this->with([
                                'locationIds' => $locationIds,
                                'employeeIds' => $employeeIds,
                                'jobCodeIds' => $jobCodeIds,
                                'costCodeNames' => $costCodeNames,
                                'laborCodes' => $laborCodes,
                            ]);
                        }
                    }
                }
            }
        }
    }

    /**
     * Query parameters with nulls and empty lists stripped.
     *
     * @return array<string,scalar>
     */
    public function toQuery(): array
    {
        $parameters = [
            // GUESS, like every other name here: TCP's location records call
            // the field `id` and the job codes call it `locationName`, so the
            // plural query parameter is inferred from the employeeIds pattern.
            'locationIds' => $this->locationIds,
            'employeeIds' => $this->employeeIds,
            'jobCodeIds' => $this->jobCodeIds,
            'costCodeNames' => $this->costCodeNames,
            'laborCodes' => $this->laborCodes,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'updatedOnFrom' => $this->updatedOnFrom,
            'updatedOnTo' => $this->updatedOnTo,
            'page' => $this->page,
            'perPage' => $this->perPage,
        ];

        $query = [];

        foreach ($parameters as $name => $value) {
            if ($value === null || $value === [] || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                // A PHP bool in a query string serialises as '1' or '', and ''
                // reads as "absent". Say the word instead.
                $query[$name] = $value ? 'true' : 'false';

                continue;
            }

            // GUESS: lists go out comma-joined. The alternative convention is
            // a repeated parameter (employeeIds=1&employeeIds=2); if that
            // turns out to be what TCP wants, this line is the only change.
            $query[$name] = is_array($value)
                ? implode(',', array_map(static fn (mixed $item): string => (string) $item, $value))
                : $value;
        }

        return $query;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,array<int,mixed>>
     */
    private function chunkValues(array $values, int $cap): array
    {
        if ($values === []) {
            // One chunk, empty: "no filter on this field" still has to take
            // part in the cross product.
            return [[]];
        }

        return array_chunk(array_values(array_unique($values)), $cap);
    }

    /**
     * Copy with some fields replaced. Cannot be used to clear a field back to
     * null — nothing needs to, and ?? keeps this readable.
     *
     * @param  array<string,mixed>  $overrides
     */
    private function with(array $overrides): self
    {
        return new self(
            $overrides['locationIds'] ?? $this->locationIds,
            $overrides['employeeIds'] ?? $this->employeeIds,
            $overrides['jobCodeIds'] ?? $this->jobCodeIds,
            $overrides['costCodeNames'] ?? $this->costCodeNames,
            $overrides['laborCodes'] ?? $this->laborCodes,
            $overrides['startDate'] ?? $this->startDate,
            $overrides['endDate'] ?? $this->endDate,
            $overrides['updatedOnFrom'] ?? $this->updatedOnFrom,
            $overrides['updatedOnTo'] ?? $this->updatedOnTo,
            $overrides['page'] ?? $this->page,
            $overrides['perPage'] ?? $this->perPage,
        );
    }
}
