<?php

namespace App\Enums;

use App\Models\EmployeeRequest;
use App\Models\Shift;
use App\Models\WorkSegment;
use Illuminate\Database\Eloquent\Model;

/**
 * What kind of thing was acted on. Mirrors activity_logs.subject_type.
 *
 * A short closed list rather than Laravel's morph map of class names: the value
 * is written into a database column that outlives any refactor, and a renamed
 * class must not orphan a year of history.
 */
enum ActivitySubject: string
{
    case Shift = 'shift';
    case WorkSegment = 'work_segment';
    case EmployeeRequest = 'employee_request';

    /** A day close is about a date, not a row — subject_id stays null. */
    case Day = 'day';

    public static function forModel(Model $model): self
    {
        return match (true) {
            $model instanceof Shift => self::Shift,
            $model instanceof WorkSegment => self::WorkSegment,
            $model instanceof EmployeeRequest => self::EmployeeRequest,
            default => throw new \InvalidArgumentException(
                'No activity subject for '.$model::class
            ),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Shift => 'shift',
            self::WorkSegment => 'punch',
            self::EmployeeRequest => 'request',
            self::Day => 'day',
        };
    }
}
