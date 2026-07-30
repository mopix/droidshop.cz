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

    /**
     * The module being on is not enough: the export route enforces
     * `accounting.export` itself, so a member with docs.manage but without it
     * was offered an ISDOC link that 403s (final review, wave 2.11).
     */
    public function test_a_member_without_the_export_permission_is_not_offered_isdoc(): void
    {
        $this->activateModule($this->tenant, 'accounting');

        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            // A plain array, not json_encode(): TenantMembership casts the
            // column both ways, so a pre-encoded string would be encoded twice
            // and read back as a string the permission check ignores.
            'permissions' => ['docs.manage'],
            'joined_at' => now(),
        ]);

        $this->actingAs($staff)
            ->get('http://shop1.droidshop/admin/m/docs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('accountingEnabled', false));
    }
}
