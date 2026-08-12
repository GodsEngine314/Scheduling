<?php

namespace App\Enums;

/**
 * What was done. Mirrors the activity_logs.action enum exactly.
 *
 * `updated` and `moved` are kept apart on purpose even though a move is
 * mechanically an update: "Dana moved Ben's Tuesday shift to Thursday" is the
 * sentence a manager needs, and it cannot be recovered from a field diff after
 * the fact without guessing at intent.
 */
enum ActivityAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Moved = 'moved';
    case Copied = 'copied';
    case Split = 'split';
    case Deleted = 'deleted';
    case Approved = 'approved';
    case Corrected = 'corrected';
    case Decided = 'decided';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case DayClosed = 'day_closed';

    /** Past-tense phrasing for the activity panel. */
    public function label(): string
    {
        return match ($this) {
            self::Created => 'created',
            self::Updated => 'edited',
            self::Moved => 'moved',
            self::Copied => 'copied',
            self::Split => 'split',
            self::Deleted => 'deleted',
            self::Approved => 'approved',
            self::Corrected => 'corrected times on',
            self::Decided => 'decided',
            self::Published => 'published',
            self::Unpublished => 'unpublished',
            self::DayClosed => 'closed the day',
        };
    }

    /** Destructive or irreversible actions get visual weight in the panel. */
    public function isDestructive(): bool
    {
        return $this === self::Deleted;
    }
}
