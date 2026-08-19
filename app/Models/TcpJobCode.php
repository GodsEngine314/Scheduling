<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One per-store job code TCP actually has. SCHEDULING-OWNED, rebuilt by
 * PositionSeeder from GET /jobcodes.
 *
 * The catalogue this consults before an outbound punch names a code. See the
 * migration for why a well-formed code is not the same thing as a real one:
 * every store carries roles 01–06, and only store 42 carries 07 and 08.
 */
class TcpJobCode extends Model
{
    protected $table = 'tcp_job_codes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * The real code for this store and role, or null if TCP has none.
     *
     * NULL IS A REAL ANSWER, not a lookup miss to route around. Management
     * exists at exactly one store in the estate; asking for it at any other is
     * asking for something that does not exist, and the only safe reply is to
     * say so.
     */
    public static function codeFor(string $storeKey, string $roleSuffix): ?string
    {
        $code = static::query()
            ->where('store_key', $storeKey)
            ->where('role_suffix', $roleSuffix)
            ->where('active', true)
            ->value('job_code_id');

        return $code === null ? null : (string) $code;
    }

    /**
     * Whether the catalogue has been read at all.
     *
     * An EMPTY table and a table that says "no such code" are different facts
     * and must not be collapsed. Empty means nobody has run PositionSeeder
     * here yet, and treating that as "no codes exist anywhere" would refuse
     * every punch in the estate — a far worse outcome than the blind
     * synthesis it replaced.
     */
    public static function isPopulated(): bool
    {
        return static::query()->exists();
    }
}
