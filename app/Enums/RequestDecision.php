<?php

namespace App\Enums;

/**
 * One row per decision on employee_request_decisions, so a reversal keeps both
 * halves of the story. Deliberately NOT HiringPizza's rejected/completed pair —
 * scheduling's decisions map onto the request statuses they produce.
 */
enum RequestDecision: string
{
    case Approved = 'approved';
    case Denied = 'denied';
    case Cancelled = 'cancelled';

    /** The status this decision caches back onto the parent request. */
    public function toRequestStatus(): RequestStatus
    {
        return match ($this) {
            self::Approved => RequestStatus::Approved,
            self::Denied => RequestStatus::Denied,
            self::Cancelled => RequestStatus::Cancelled,
        };
    }
}
