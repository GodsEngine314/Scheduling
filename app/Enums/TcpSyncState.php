<?php

namespace App\Enums;

/**
 * Whether TCP has accepted our version of a work segment.
 *
 * The point of this column is that divergence is VISIBLE. A local approval that
 * TCP rejected is not an edge case to hide — it means payroll is about to pay
 * from a number the timeclock does not agree with.
 */
enum TcpSyncState: string
{
    /** Changed here, not pushed yet. A queued job owns it. */
    case Pending = 'pending';

    /** TCP accepted our version. */
    case Synced = 'synced';

    /** TCP rejected it. tcp_sync_error says why; a human has to look. */
    case Failed = 'failed';

    /** Deliberately never pushed — e.g. a row TCP itself gave us and we have not touched. */
    case Local = 'local';

    public function needsPush(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'queued for TCP',
            self::Synced => 'in TCP',
            self::Failed => 'TCP rejected',
            self::Local => 'local only',
        };
    }
}
