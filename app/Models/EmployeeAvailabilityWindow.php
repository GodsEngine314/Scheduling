<?php

namespace App\Models;

use App\Enums\AvailabilityShiftType;
use App\Enums\DayOfWeek;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PROJECTION. One row is one concrete window: "Monday, 16:00 to 21:00".
 *
 * Overnight windows are encoded by the ordering of the two time columns, with
 * no flag to fall out of sync with them:
 *
 *   available_to >  available_from   same day        16:00 -> 21:00
 *   available_to <  available_from   wraps midnight  20:00 -> 02:00
 *   available_to == available_from   rejected by a MySQL CHECK
 *
 * day_of_week always names the day the window STARTS on.
 *
 * The times are wall-clock in the store's local zone and are left as strings:
 * casting them to datetimes would invent a date they do not have.
 */
class EmployeeAvailabilityWindow extends Model
{
    protected $table = 'employee_availability_windows';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
            'shift_type' => AvailabilityShiftType::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeForDay(Builder $query, DayOfWeek|string $day): Builder
    {
        return $query->where('day_of_week', $day instanceof DayOfWeek ? $day->value : $day);
    }

    public function wrapsMidnight(): bool
    {
        return $this->secondsOfDay((string) $this->available_to)
            < $this->secondsOfDay((string) $this->available_from);
    }

    /**
     * Does this window fully contain [$start, $end]?
     *
     * $start and $end must be store-local wall-clock instants — the same clock
     * the window is written in.
     *
     * A wrapping window is anchored on the evening it began, so a shift that
     * starts after midnight has to be tested against the PREVIOUS day's window
     * as well as its own day's. Hence the two candidate anchors.
     */
    public function covers(CarbonInterface $start, CarbonInterface $end): bool
    {
        if ($end->lessThan($start)) {
            return false;
        }

        $weekday = $this->day_of_week?->carbonDayOfWeek();

        if ($weekday === null) {
            return false;
        }

        foreach ([0, 1] as $daysBack) {
            $anchor = $start->copy()->startOfDay()->subDays($daysBack);

            if ($anchor->dayOfWeek !== $weekday) {
                continue;
            }

            // Set by wall clock rather than by adding seconds, so a window that
            // spans a DST change keeps the hours the employee actually gave us.
            $windowStart = $anchor->copy()->setTimeFromTimeString((string) $this->available_from);
            $windowEnd = $this->wrapsMidnight()
                ? $anchor->copy()->addDay()->setTimeFromTimeString((string) $this->available_to)
                : $anchor->copy()->setTimeFromTimeString((string) $this->available_to);

            if ($start->greaterThanOrEqualTo($windowStart) && $end->lessThanOrEqualTo($windowEnd)) {
                return true;
            }
        }

        return false;
    }

    /** Accepts 'H:i' or 'H:i:s', which is what MySQL and SQLite hand back. */
    private function secondsOfDay(string $time): int
    {
        [$hours, $minutes, $seconds] = array_pad(array_map('intval', explode(':', $time)), 3, 0);

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }
}
