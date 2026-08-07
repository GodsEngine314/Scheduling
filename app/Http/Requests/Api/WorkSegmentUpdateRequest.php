<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\ResolvesLocalWindow;
use App\Models\WorkSegment;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Change Shift workflow: correcting the times on a punch.
 *
 * A NULL SIDE MEANS "LEAVE IT ALONE", not "clear it". That is
 * WorkSegmentService::correctTimes()'s contract, and it is why sending only a
 * clock_out closes an open punch rather than re-opening a closed one.
 *
 * The correction clears manager_approval unless reapprove is explicitly true,
 * because a segment must not stay approved for hours nobody has since looked
 * at. The service stamps who corrected it and when either way.
 */
class WorkSegmentUpdateRequest extends FormRequest
{
    use ResolvesLocalWindow;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Defaults to the segment's own business_date, so a correction of
            // the clock need not restate the day.
            'business_date' => ['sometimes', 'date_format:Y-m-d'],

            'clock_in' => ['sometimes', 'date_format:H:i'],
            // Only meaningful when BOTH sides are being corrected — a lone
            // clock_out is resolved against the stored time_in, and the service
            // is the only thing that can see that value.
            'clock_out' => ['sometimes', 'date_format:H:i', 'different:clock_in'],
            'time_in' => ['sometimes', 'date'],
            'time_out' => ['sometimes', 'date'],

            'reapprove' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The corrected instants, either of which may be null for "unchanged".
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public function correctedTimes(BusinessDay $businessDay, WorkSegment $segment): array
    {
        $validated = $this->validated();

        $clockIn = $validated['clock_in'] ?? null;
        $clockOut = $validated['clock_out'] ?? null;

        if ($clockIn === null && $clockOut === null) {
            return [
                ($validated['time_in'] ?? null) === null ? null : CarbonImmutable::parse($validated['time_in'], 'UTC'),
                ($validated['time_out'] ?? null) === null ? null : CarbonImmutable::parse($validated['time_out'], 'UTC'),
            ];
        }

        $storeId = (int) $segment->store_id;
        $localDate = $validated['business_date'] ?? $segment->business_date->toDateString();

        // Both sides given: the pair states its own window, and a clock_out at
        // or before clock_in is the punch crossing midnight.
        if ($clockIn !== null && $clockOut !== null) {
            [$inLocal, $outLocal] = $this->localWindow($localDate, $clockIn, $clockOut);

            return [
                $businessDay->toUtc($storeId, $inLocal),
                $businessDay->toUtc($storeId, $outLocal),
            ];
        }

        if ($clockOut === null) {
            return [$businessDay->toUtc($storeId, $this->localMoment($localDate, $clockIn)), null];
        }

        return [null, $this->closingMoment($businessDay, $segment, $storeId, $localDate, $clockOut)];
    }

    /**
     * Closing a punch with a clock time alone.
     *
     * A bare 02:00 cannot say which day it is on, and against the segment's own
     * business_date it lands before the punch began. The only reading that is
     * not nonsense is the first 02:00 AFTER time_in, so the date rolls forward
     * — resolved as a wall clock rather than by adding 24 hours to an instant,
     * so a DST night still closes at the hour the employee actually left.
     */
    private function closingMoment(
        BusinessDay $businessDay,
        WorkSegment $segment,
        int $storeId,
        string $localDate,
        string $clockOut,
    ): CarbonImmutable {
        $out = $businessDay->toUtc($storeId, $this->localMoment($localDate, $clockOut));

        if ($segment->time_in !== null && $out->lessThanOrEqualTo($segment->time_in)) {
            return $businessDay->toUtc($storeId, $this->localMoment($this->nextLocalDate($localDate), $clockOut));
        }

        return $out;
    }
}
