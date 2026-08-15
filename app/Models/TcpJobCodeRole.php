<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TCP job code role => our position. SCHEDULING-OWNED, so a replay cannot
 * erase it.
 *
 * One row per role suffix, MANY of which may point at the same position: 04 and
 * 08 are both Assistant Manager. That is the reason this is not a row in
 * integration_identities — see the migration.
 */
class TcpJobCodeRole extends Model
{
    protected $table = 'tcp_job_code_roles';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'code_count' => 'integer',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * The position a whole jobCodeId means, or null.
     *
     * Takes the WHOLE code and decodes it, so callers do not each re-implement
     * the franchise/store/role split. A four-digit company-wide code (1000
     * Regular, 2000 Sick) is not a role and returns null rather than borrowing
     * suffix '00'.
     */
    public static function positionIdFor(string $jobCodeId): ?int
    {
        if (preg_match('/^\d{4}\d{2}(\d{2})$/', trim($jobCodeId), $parts) !== 1) {
            return null;
        }

        $positionId = static::query()->where('role_suffix', $parts[1])->value('position_id');

        return $positionId === null ? null : (int) $positionId;
    }
}
