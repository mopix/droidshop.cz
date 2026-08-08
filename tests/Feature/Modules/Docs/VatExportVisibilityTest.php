<?php

namespace Tests\Feature\Modules\Docs;

use App\Models\Tenant;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

/**
 * The VAT export belongs to a shop that files a VAT return (wave 3.8).
 *
 * Offering it to a shop that is not registered would hand it a file of
 * zeroes and imply an obligation it does not have.
 */
class VatExportVisibilityTest extends DocsTestCase
{
    public function test_a_payer_is_offered_the_vat_export(): void
    {
        Tenant::query()->whereKey($this->tenant->id)->update(['vat_payer' => true]);

        $this->actingAsDocsManager()
            ->get('http://shop1.droidshop/admin/m/docs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('vatApplies', true));
    }

    public function test_a_non_payer_is_not(): void
    {
        Tenant::query()->whereKey($this->tenant->id)->update(['vat_payer' => false]);

        $this->actingAsDocsManager()
            ->get('http://shop1.droidshop/admin/m/docs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('vatApplies', false));
    }
}
