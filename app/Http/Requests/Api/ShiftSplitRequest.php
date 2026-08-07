<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\ResolvesLocalWindow;
use App\Models\Shift;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The second part of a split shift — 11:00-14:00 becomes that plus 17:00-21:00.
 *
 * Only the NEW part is described here. Part one keeps its own times and its own
 * unpaid break; the gap between the two is not a break and is not stated.
 */
class ShiftSplitRequest extends FormRequest
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
            // Optional: the second part is normally on the same local day as
            // the shift it follows, so the caller need not restate it.
            'business_date' => ['sometimes', 'date_format:Y-m-d'],

            'start_time' => ['required_without:start_at', 'required_with:end_time', 'nullable', 'date_format:H:i'],
            'end_time' => ['required_without:end_at', 'required_with:start_time', 'nullable', 'date_format:H:i', 'different:start_time'],
            'start_at' => ['required_without:start_time', 'required_with:end_at', 'nullable', 'date'],
            'end_at' => ['required_without:end_time', 'required_with:start_at', 'nullable', 'date', 'after:start_at'],
        ];
    }

    /**
     * The second part's window as the pair of UTC instants
     * ShiftService::split() takes.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function secondPart(BusinessDay $businessDay, Shift $shift): array
    {
        $validated = $this->validated();

        if (($validated['start_time'] ?? null) === null) {
            return [
                CarbonImmutable::parse($validated['start_at'], 'UTC'),
                CarbonImmutable::parse($validated['end_at'], 'UTC'),
            ];
        }

        $storeId = (int) $shift->store_id;
        $statedDate = $validated['business_date'] ?? null;
        $localDate = $statedDate ?? $shift->business_date->toDateString();

        [$start, $end] = $this->utcWindow($businessDay, $storeId, $localDate, $validated);

        // An overnight FIRST part pushes the second part's clock times onto the
        // next calendar day: 23:30 -> 02:00, then 05:00 -> 09:00. Read against
        // business_date those 05:00 hours land before the part they follow, and
        // ShiftService would rightly refuse them.
        //
        // So roll the pair — but only when part one really did cross midnight
        // and the caller did not state a date. A second part that falls before
        // a first part which stayed inside its own day is a genuine mistake,
        // and quietly moving it to tomorrow would be worse than refusing it.
        if ($statedDate === null && $start->lessThan($shift->end_at) && $this->crossesMidnight($businessDay, $shift)) {
            [$start, $end] = $this->utcWindow($businessDay, $storeId, $this->nextLocalDate($localDate), $validated);
        }

        return [$start, $end];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function utcWindow(BusinessDay $businessDay, int $storeId, string $localDate, array $validated): array
    {
        [$startLocal, $endLocal] = $this->localWindow(
            $localDate,
            $validated['start_time'],
            $validated['end_time'],
        );

        return [
            $businessDay->toUtc($storeId, $startLocal),
            $businessDay->toUtc($storeId, $endLocal),
        ];
    }

    /** Does the first part end on a later local day than it started on? */
    private function crossesMidnight(BusinessDay $businessDay, Shift $shift): bool
    {
        return $businessDay->toLocal((int) $shift->store_id, $shift->end_at)->toDateString()
            > $shift->business_date->toDateString();
    }
}
