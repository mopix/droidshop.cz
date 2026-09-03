<?php

namespace Modules\Reviews\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ReviewOptout extends Model
{
    use BelongsToTenant;

    protected $guarded = [];
}
