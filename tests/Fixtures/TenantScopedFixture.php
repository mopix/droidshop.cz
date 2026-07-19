<?php

namespace Tests\Fixtures;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Stand-in for a real domain model until wave 0.2 brings products and orders.
 *
 * Isolation is a property of the trait, not of any particular table, so the
 * trait is what these tests exercise.
 */
class TenantScopedFixture extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_scoped_fixtures';

    protected $guarded = [];

    public $timestamps = false;
}
