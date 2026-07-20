<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A user's place in a shop (tenant_users).
 *
 * A pivot class rather than a bare withPivot() list, so `permissions` casts in
 * both directions. Without it, attach() with an array raises "Array to string
 * conversion" and reads come back as raw JSON — two silent traps around the
 * column that decides what a staff member may do.
 */
class TenantMembership extends Pivot
{
    protected $table = 'tenant_users';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }
}
