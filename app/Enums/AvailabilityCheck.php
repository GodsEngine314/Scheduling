<?php

namespace App\Enums;

/**
 * Warns, never blocks. Recomputed whenever the shift or the employee's
 * availability changes.
 */
enum AvailabilityCheck: string
{
    case Ok = 'ok';
    case OutsideAvailability = 'outside_availability';
    case Unknown = 'unknown';
}
