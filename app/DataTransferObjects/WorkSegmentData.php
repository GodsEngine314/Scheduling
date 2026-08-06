<?php

namespace App\DataTransferObjects;

/**
 * One block of worked time, in OUR field names.
 *
 * The keys toArray() produces are the work_segments table's columns, not TCP's
 * wire format. There is deliberately no fromTcpRecord() here: TCP's field
 * names come from images in the source document that could not be read, so the
 * inbound mapping belongs in one sync service where it can be corrected in a
 * single place, not baked into a DTO that everything else depends on.
 *
 * timeOut === null is a real, expected state — an open punch. Somebody clocked
 * in and has not left. It is not missing data.
 *
 * hours is TCP's own figure when TCP supplied one, never a subtraction of
 * timeIn from timeOut. When the two disagree, payroll needs TCP's.
 */
final readonly class WorkSegmentData
{
    /**
     * @param  string  $businessDate  Y-m-d
     * @param  string  $timeIn  Y-m-d H:i:s
     * @param  string|null  $timeOut  Y-m-d H:i:s; null is an open punch
     * @param  float|string|null  $hours  as TCP reported it
     * @param  string|null  $tcpUpdatedOn  ISO-8601; drives the incremental sync
     * @param  array<string,mixed>|null  $tcpPayload  the raw record, kept whole while the mapping is unconfirmed
     */
    public function __construct(
        public int $employeeId,
        public int $storeId,
        public string $businessDate,
        public string $timeIn,
        public ?string $timeOut = null,
        public ?string $tcpSegmentId = null,
        public ?int $positionId = null,
        public int $breakMinutes = 0,
        public float|string|null $hours = null,
        public ?string $costCodeName = null,
        public ?string $laborCode = null,
        public ?string $notes = null,
        public ?string $tcpUpdatedOn = null,
        public ?array $tcpPayload = null,
    ) {
    }

    /** Clocked in, not yet out. */
    public function isOpenPunch(): bool
    {
        return $this->timeOut === null;
    }

    /**
     * Column names to values, nulls removed.
     *
     * Note what that costs: an open punch's `time_out => null` is dropped, so
     * this array can create a segment but cannot REOPEN one. Clearing a field
     * is an explicit write by the caller that means it.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'tcp_segment_id' => $this->tcpSegmentId,
            'employee_id' => $this->employeeId,
            'store_id' => $this->storeId,
            'position_id' => $this->positionId,
            'business_date' => $this->businessDate,
            'time_in' => $this->timeIn,
            'time_out' => $this->timeOut,
            'break_minutes' => $this->breakMinutes,
            'hours' => $this->hours,
            'cost_code_name' => $this->costCodeName,
            'labor_code' => $this->laborCode,
            'notes' => $this->notes,
            'tcp_updated_on' => $this->tcpUpdatedOn,
            'tcp_payload' => $this->tcpPayload,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
