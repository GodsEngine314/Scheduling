<?php

namespace App\Enums;

/**
 * Cached on employee_requests from the latest decision. A decision write must
 * always update both, or the board query lies.
 */
enum RequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Cancelled = 'cancelled';
}
