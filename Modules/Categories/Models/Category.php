<?php

namespace Modules\Categories\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'depth' => 'integer',
            'position' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * Ancestor ids, root first, read straight off the materialised path.
     *
     * @return list<int>
     */
    public function ancestorIds(): array
    {
        return array_map('intval', array_values(array_filter(explode('/', $this->path))));
    }

    /**
     * The path a child of this category would carry.
     */
    public function childPath(): string
    {
        return $this->path.$this->id.'/';
    }

    public function scopeVisible(Builder $query): void
    {
        $query->where('is_visible', true);
    }

    public function url(): string
    {
        return '/kategorie/'.$this->slug;
    }
}
