<?php

namespace Tests\Feature\Modules\Accounting;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class AccountingScreenTest extends DocsTestCase
{
    private function owner(): User
    {
        $owner = User::factory()->create();
        $this->tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return $owner;
    }

    public function test_the_documents_screen_knows_whether_accounting_runs(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->get('http://shop1.droidshop/admin/m/docs')
            ->assertInertia(fn (Assert $page) => $page->where('accountingEnabled', false));

        $this->activateModule($this->tenant, 'accounting');

        $this->actingAs($owner)
            ->get('http://shop1.droidshop/admin/m/docs')
            ->assertInertia(fn (Assert $page) => $page->where('accountingEnabled', true));
    }
}
