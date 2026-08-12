<?php

namespace App\Enums;

/**
 * Where a locally-built shift stands with Humanity. Nothing leaves this service
 * until a publish run picks it up.
 */
enum PublishState: string
{
    /** Built here, Humanity has never seen it. */
    case Draft = 'draft';

    /** A publish run has it in hand. */
    case Queued = 'queued';

    /** Live in Humanity, and LOCKED. Unpublish it to change anything. */
    case Published = 'published';

    /**
     * Live in Humanity, but unlocked for editing.
     *
     * The distinction that makes the whole workflow work: humanity_shift_id is
     * KEPT, so re-publishing sends PUT /shifts/{id} rather than creating a
     * second shift. Employees keep seeing the last published version until the
     * edit is re-published — a shift silently vanishing from someone's week is
     * worse than one that is briefly out of date.
     */
    case Unlocked = 'unlocked';

    /** A push was attempted and rejected. last_publish_error says why. */
    case Failed = 'failed';

    /** Withdrawn from Humanity entirely. The remote shift is gone. */
    case Unpublished = 'unpublished';

    /** Humanity currently holds a shift for this row. */
    public function isLive(): bool
    {
        return $this === self::Published || $this === self::Unlocked;
    }

    /** Editing is refused until this is unpublished. */
    public function isLocked(): bool
    {
        return $this === self::Published;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::Queued => 'queued',
            self::Published => 'published',
            self::Unlocked => 'unpublished — editable',
            self::Failed => 'publish failed',
            self::Unpublished => 'withdrawn',
        };
    }
}
