<?php

namespace App\Enums;

enum RequestType: string
{
    case TimeOff = 'time_off';
    case ShiftSwap = 'shift_swap';
    case CoverRequest = 'cover_request';
    case OpenShiftClaim = 'open_shift_claim';
    case AvailabilityChange = 'availability_change';
    case Other = 'other';
}
