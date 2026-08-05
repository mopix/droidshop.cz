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

    // Deliberately no getRouteKeyName() override: pages bind by id.
    //
    // It used to return 'slug', for a storefront route that no longer binds
    // the model at all — since wave 3.1 the page is served by
    // Route::fallback() and PageController reads the path itself. The admin
    // is now the only place that binds a Page, and binding it by slug there
    // would mean the edit URL changes whenever the tenant renames the page,
    // so a bookmarked or open editing tab would 404 after its own save.
}
