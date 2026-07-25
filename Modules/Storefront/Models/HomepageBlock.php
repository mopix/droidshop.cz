<?php

namespace Modules\Storefront\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Storefront\Enums\BlockType;

class HomepageBlock extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => BlockType::class,
            'payload' => 'array',
            'visible' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('visible', true);
    }
}
