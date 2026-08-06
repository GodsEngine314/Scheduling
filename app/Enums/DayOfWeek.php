<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum DayOfWeek: string
{
    case Sunday = 'sunday';
    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';

    /** Carbon's dayOfWeek: 0 = Sunday ... 6 = Saturday. */
    public function carbonDayOfWeek(): int
    {
        return match ($this) {
            self::Sunday => 0,
            self::Monday => 1,
            self::Tuesday => 2,
            self::Wednesday => 3,
            self::Thursday => 4,
            self::Friday => 5,
            self::Saturday => 6,
        };
    }

    public static function fromCarbonDayOfWeek(int $dayOfWeek): self
    {
        return match ($dayOfWeek % 7) {
            0 => self::Sunday,
            1 => self::Monday,
            2 => self::Tuesday,
            3 => self::Wednesday,
            4 => self::Thursday,
            5 => self::Friday,
            6 => self::Saturday,
        };
    }

    public static function fromDate(CarbonInterface $date): self
    {
        return self::fromCarbonDayOfWeek($date->dayOfWeek);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
