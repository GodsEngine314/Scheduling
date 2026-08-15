<?php

namespace App\DataTransferObjects;

/**
 * The query side of GET /employees.
 *
 * The same two rules as WorkSegmentFilter, for the same reasons:
 *
 * THE CAP. Each list filter accepts at most 20 values, and a longer list is not
 * rejected — the extra values are dropped and the response looks perfectly
 * normal. chunked() splits instead.
 *
 * THE CROSS PRODUCT. The filters combine with AND, so splitting two lists at
 * once means asking for every combination of chunks.
 *
 * CONFIRMED AGAINST THE LIVE API, and the first guess was wrong in the exact way
 * predicted: `locationIds` with a numeric id was silently IGNORED. It returned
 * all 430 employees in the company and looked like a successful store-scoped
 * pull — as did a parameter name invented on the spot.
 *
 * The parameter is `locations`, and its value is the STORE NUMBER string
 * ("03795-00001"), not the numeric location id from GET /locations. That is what
 * an employee record carries in its own `location` field, so the two agree.
 */
final readonly class EmployeeFilter
{
    /**
     * @param  array<int,string>  $locations  store NUMBERS ("03795-00001"), not numeric ids
     * @param  array<int,int|string>  $employeeIds  TCP employee ids
     */
    public function __construct(
        public array $locations = [],
        public array $employeeIds = [],
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
     * This filter split into as many filters as the 20-value cap requires. A
     * filter already within the cap yields exactly itself.
     *
     * @return iterable<int,self>
     */
    public function chunked(): iterable
    {
        $cap = max(1, (int) config('tcp.filter_value_cap', 20));

        foreach ($this->chunkValues($this->locations, $cap) as $locations) {
            foreach ($this->chunkValues($this->employeeIds, $cap) as $employeeIds) {
                yield $this->with([
                    'locations' => $locations,
                    'employeeIds' => $employeeIds,
                ]);
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
            'locations' => $this->locations,
            'employeeIds' => $this->employeeIds,
            'page' => $this->page,
            'perPage' => $this->perPage,
        ];

        $query = [];

        foreach ($parameters as $name => $value) {
            if ($value === null || $value === [] || $value === '') {
                continue;
            }

            // GUESS: lists go out comma-joined, matching WorkSegmentFilter. If
            // TCP turns out to want a repeated parameter, both files change.
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
     * @param  array<string,mixed>  $overrides
     */
    private function with(array $overrides): self
    {
        return new self(
            $overrides['locations'] ?? $this->locations,
            $overrides['employeeIds'] ?? $this->employeeIds,
            $overrides['page'] ?? $this->page,
            $overrides['perPage'] ?? $this->perPage,
        );
    }
}
