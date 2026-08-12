<?php

namespace App\DataTransferObjects;

/**
 * One planned shift, in OUR field names.
 *
 * The keys toArray() produces are the shifts table's columns, not a vendor's
 * wire format. Translating to Humanity is a mapper's job: the vendor's field
 * names come from images in the source document that could not be read, and
 * building a typed structure on top of an unconfirmed shape would just be a
 * confident-looking guess.
 *
 * One instance is one employee working one continuous block, matching one row.
 * An open shift has a null employeeId; a split shift is two instances sharing
 * a splitGroupId, and the gap between them is unpaid but is not a break
 * is time inside a block, a split gap is time between two of them.
 */
final readonly class ShiftData
{
    /**
     * @param  string  $businessDate  Y-m-d, the store-local day the shift starts on
     * @param  string  $startAt  Y-m-d H:i:s, UTC
     * @param  string  $endAt  Y-m-d H:i:s, UTC
     * @param  string|null  $repeatUntil  Y-m-d
     */
    public function __construct(
        public int $storeId,
        public string $businessDate,
        public string $startAt,
        public string $endAt,
        public ?int $employeeId = null,
        public ?int $positionId = null,
        public ?string $notes = null,
        public string $repeatRule = 'none',
        public ?string $repeatUntil = null,
        public ?string $seriesId = null,
        public ?string $splitGroupId = null,
        public ?int $splitPart = null,
    ) {
    }

    /**
     * Column names to values, nulls removed.
     *
     * Stripping nulls means a partial payload cannot blank a field it never
     * meant to touch. It also means this cannot EXPRESS a clear: unassigning a
     * shift is `employee_id => null` and has to be written by the caller that
     * intends it, not smuggled in by an absent field.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'employee_id' => $this->employeeId,
            'store_id' => $this->storeId,
            'position_id' => $this->positionId,
            'business_date' => $this->businessDate,
            'start_at' => $this->startAt,
            'end_at' => $this->endAt,
            'notes' => $this->notes,
            'repeat_rule' => $this->repeatRule,
            'repeat_until' => $this->repeatUntil,
            'series_id' => $this->seriesId,
            'split_group_id' => $this->splitGroupId,
            'split_part' => $this->splitPart,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
