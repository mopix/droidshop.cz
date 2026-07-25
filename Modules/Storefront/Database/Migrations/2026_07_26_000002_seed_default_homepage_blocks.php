<?php

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Modules\Storefront\Support\DefaultHomepage;

return new class extends Migration
{
    public function up(): void
    {
        $seeder = app(DefaultHomepage::class);

        Tenant::query()->each(function (Tenant $tenant) use ($seeder): void {
            $seeder->seed($tenant); // Idempotent: skips tenants that already have blocks.
        });
    }

    public function down(): void
    {
        // Seed data is not reverted — deleting would also discard hand-edited blocks.
    }
};
