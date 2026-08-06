<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * PROJECTION of auth.v1.store.*. Ids are assigned by auth and must match
 * byte-for-byte across auth, hiring and scheduling, so the key is neither
 * generated nor auto-incrementing here.
 */
class Store extends Model
{
    protected $table = 'stores';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * SCHEDULING-OWNED settings for this store. There is no foreign key behind
     * this relation on purpose — see the store_settings migration.
     */
    public function settings(): HasOne
    {
        return $this->hasOne(StoreSetting::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'primary_store_id');
    }

    public function employeeAssignments(): HasMany
    {
        return $this->hasMany(EmployeeStoreAssignment::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function workSegments(): HasMany
    {
        return $this->hasMany(WorkSegment::class);
    }

    public function employeeRequests(): HasMany
    {
        return $this->hasMany(EmployeeRequest::class);
    }
}
