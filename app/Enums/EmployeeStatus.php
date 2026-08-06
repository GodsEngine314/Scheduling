<?php

namespace App\Enums;

enum EmployeeStatus: string
{
    case Hired = 'hired';
    case Resigned = 'resigned';
    case Terminated = 'terminated';
    case Rehired = 'rehired';
    case OJE = 'OJE';

    /**
     * Hiring publishes no employee.deleted event, so "may this person be put on
     * the board?" is answered from the status column, not from the row's absence.
     */
    public function isSchedulable(): bool
    {
        return match ($this) {
            self::Hired, self::Rehired, self::OJE => true,
            self::Resigned, self::Terminated => false,
        };
    }

    /** @return array<int, string> */
    public static function schedulableValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isSchedulable()),
        ));
    }
}
