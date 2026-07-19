<?php

namespace Modules\Pages\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
