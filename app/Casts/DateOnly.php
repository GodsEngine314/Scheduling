<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A DATE column that actually stores a date.
 *
 * Laravel's built-in 'date' cast reads back a Carbon at midnight, but on the way
 * IN it serialises through the connection's date format — 'Y-m-d H:i:s'. MySQL
 * hides that: a DATE column truncates the time and the row ends up correct.
 * SQLite does not. It is loosely typed, stores the string it was handed, and the
 * column quietly fills with '2026-08-07 00:00:00'.
 *
 * Every date-keyed lookup in this codebase then misses:
 *
 *   Shift::forBoard()                  where('business_date', '2026-08-07')
 *   WorkSegment::forBoard()            same, so the board sees no segments and
 *                                      reports a day with unapproved hours and
 *                                      open punches on it as entirely settled
 *   ReconciliationService::match()     candidate shifts never match, so every
 *                                      punch lands as unmatched
 *
 * All three pass on MySQL and fail on the SQLite dev connection, which is the
 * worst shape a bug can take: green in the place you test, wrong in the place
 * you develop — or the reverse, once someone runs the suite against MySQL.
 *
 * Writing 'Y-m-d' makes both engines agree, and keeps a plain indexed
 * where() usable instead of a whereDate() that would drop the index.
 */
class DateOnly implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, ?string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => $value === null ? null : CarbonImmutable::parse($value)->toDateString()];
    }
}
