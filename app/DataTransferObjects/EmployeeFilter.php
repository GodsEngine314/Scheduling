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
 * GUESS, and a more dangerous one here than usual: `locationIds` is inferred
 * from the employeeIds pattern. TCP's own location records call the field `id`
 * and its job codes call it `locationName`, so nothing confirms this spelling.
 * A filter parameter TCP does not recognise is likely to be IGNORED rather than
 * rejected — which returns every employee in the company and looks exactly like
 * a successful store-scoped pull. Confirm against a live response before
 * trusting a roster to be limited to one store.
 */
final readonly class EmployeeFilter
{
    /**
     * @param  array<int,int|string>  $locationIds  TCP location ids, NOT our store ids
     * @param  array<int,int|string>  $employeeIds  TCP employee ids
     */
    public function __construct(
        public array $locationIds = [],
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

        foreach ($this->chunkValues($this->locationIds, $cap) as $locationIds) {
            foreach ($this->chunkValues($this->employeeIds, $cap) as $employeeIds) {
                yield $this->with([
                    'locationIds' => $locationIds,
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
            'locationIds' => $this->locationIds,
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
            $overrides['locationIds'] ?? $this->locationIds,
            $overrides['employeeIds'] ?? $this->employeeIds,
            $overrides['page'] ?? $this->page,
            $overrides['perPage'] ?? $this->perPage,
        );
    }
}
