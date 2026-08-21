<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The job code TCP has on file for one person.
 *
 * THIS REPLACED A DROPDOWN. A punch's jobCodeId used to be assembled from a
 * position somebody picked — see TcpJobCodeRole::jobCodeIdFor(), which is still
 * there for the one case that has no person in it — and assembling a code is
 * guessing whether TCP has it. It does not, uniformly: three of our positions
 * exist at no TCP store and one exists at a single store.
 *
 * TCP knows. GET /employeejobcodes returns the assignments its own timeclock
 * files hours against, and across two real stores every one of twenty employees
 * carried exactly one per-store role code. So the honest question is not "what
 * role is this punch" but "what is this person's code", and that has an answer
 * we can look up instead of construct.
 *
 * ROLE CODES AND PAY CATEGORIES ARRIVE TOGETHER on that endpoint and only their
 * shape tells them apart — 37951001 "Crew Member - 3795-10" beside 1003 "Bonus".
 * is_role is the stored answer; see the migration for why it is stored rather
 * than re-derived. Only role rows are ever sent as a jobCodeId, because "Bonus"
 * describes how an hour is paid and not what anybody did.
 */
class TcpEmployeeJobCode extends Model
{
    protected $table = 'tcp_employee_job_codes';

    protected $fillable = [
        'employee_id',
        'tcp_employee_id',
        'tcp_record_id',
        'job_code_id',
        'description',
        'store_key',
        'role_suffix',
        'is_role',
        'tcp_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'is_role' => 'boolean',
            'tcp_synced_at' => 'datetime',
        ];
    }

    /**
     * No inverse relation is declared on Employee, deliberately: that model is
     * a projection and this table is not, so the dependency points one way.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Our position, via the suffix mapping that already owns that translation. */
    public function positionId(): ?int
    {
        return $this->role_suffix === null
            ? null
            : TcpJobCodeRole::positionIdFor((string) $this->job_code_id);
    }

    /**
     * The role code this person holds at this store, or null.
     *
     * SCOPED BY STORE, because a job code IS store-specific — 37951001 is Crew
     * Member at store 10 and says nothing about store 42. An employee who covers
     * two stores holds two codes, and filing their hours under the wrong one
     * books them to the wrong store's labour.
     *
     * A null store number (a store TCP has never heard of, or one whose number
     * cannot form a code) matches nothing rather than falling back to any code
     * the person happens to hold. Better a refusal that names the store than a
     * punch filed somewhere else.
     */
    public static function roleFor(?int $employeeId, ?string $storeNumber): ?self
    {
        if ($employeeId === null) {
            return null;
        }

        $storeKey = TcpJobCodeRole::storeKeyFor($storeNumber);

        if ($storeKey === null) {
            return null;
        }

        return static::query()
            ->where('employee_id', $employeeId)
            ->where('is_role', true)
            ->where('store_key', $storeKey)
            // Deterministic when somebody holds two codes at one store, which no
            // live employee does but the schema permits.
            ->orderBy('role_suffix')
            ->first();
    }

    /** The whole code to send on a punch, or null if TCP has none for them here. */
    public static function jobCodeIdFor(?int $employeeId, ?string $storeNumber): ?string
    {
        $role = static::roleFor($employeeId, $storeNumber);

        return $role === null ? null : (string) $role->job_code_id;
    }

    /**
     * Which of OUR positions this person's TCP role means.
     *
     * What the removed dropdown used to be asked for. The board still stores a
     * position on every shift and punch — the cost estimator, the chips and the
     * Humanity publish all read it — so removing the field meant deriving the
     * value, not dropping it.
     */
    public static function positionIdFor(?int $employeeId, ?string $storeNumber): ?int
    {
        return static::roleFor($employeeId, $storeNumber)?->positionId();
    }

    /**
     * Every employee id we hold a role code for at this store.
     *
     * The board uses it to say, on the form itself, which people cannot have
     * hours filed yet — while somebody is looking at the form, rather than at
     * payroll.
     *
     * @return array<int,int>
     */
    public static function employeeIdsWithRoleAt(?string $storeNumber): array
    {
        $storeKey = TcpJobCodeRole::storeKeyFor($storeNumber);

        if ($storeKey === null) {
            return [];
        }

        return static::query()
            ->where('is_role', true)
            ->where('store_key', $storeKey)
            ->pluck('employee_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
