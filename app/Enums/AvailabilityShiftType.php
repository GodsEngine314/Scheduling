<?php

namespace App\Enums;

/**
 * Carried through from hiring as a descriptor only. The hours on the window are
 * what scheduling actually tests a shift against.
 */
enum AvailabilityShiftType: string
{
    case AM = 'AM';
    case PM = 'PM';
    case OP = 'OP';
}
