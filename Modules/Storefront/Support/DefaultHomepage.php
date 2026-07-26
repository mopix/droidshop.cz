<?php

namespace Modules\Storefront\Support;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Models\HomepageBlock;

/**
 * The one recipe for a shop's starting homepage. Called by TenantProvisioner
 * for new tenants and by the backfill migration for existing ones — never
 * duplicated. Idempotent: a tenant that already has blocks is left untouched.
 */
class DefaultHomepage
{
    public function __construct(private readonly TenantContext $context) {}

    public function seed(Tenant $tenant): void
    {
        $this->context->runAs($tenant, function () use ($tenant): void {
            if (HomepageBlock::query()->exists()) {
                return;
            }

            HomepageBlock::create([
                'position' => 0,
                'type' => BlockType::Hero,
                'payload' => [
                    'title' => $tenant->name,
                    'subtitle' => 'Vítejte v našem e-shopu. Podívejte se na aktuální nabídku.',
                    'cta_label' => null,
                    'cta_url' => null,
                    'image_path' => null,
                ],
                'visible' => true,
            ]);

            HomepageBlock::create([
                'position' => 1,
                'type' => BlockType::ProductRow,
                'payload' => ['heading' => 'Novinky', 'mode' => 'latest', 'count' => 8, 'product_ids' => []],
                'visible' => true,
            ]);

            HomepageBlock::create([
                'position' => 2,
                'type' => BlockType::CategoryGrid,
                'payload' => ['heading' => 'Kategorie', 'category_ids' => []],
                'visible' => true,
            ]);
        });
    }
}
