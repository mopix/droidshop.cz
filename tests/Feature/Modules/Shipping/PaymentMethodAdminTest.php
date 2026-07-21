<?php

namespace Tests\Feature\Modules\Shipping;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Services\PaymentMethodWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class PaymentMethodAdminTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private const IBAN = 'CZ6508000000192000145399';

    private Tenant $tenant;

    private TenantContext $context;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'shipping');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/admin/m/shipping'.$path;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'provider' => PaymentMethod::PROVIDER_COD,
            'name' => 'Dobírka',
            'fee' => 0,
            'is_active' => true,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function make(Tenant $tenant, array $attributes = []): PaymentMethod
    {
        return $this->context->runAs($tenant, fn () => app(PaymentMethodWriter::class)->create([
            'provider' => PaymentMethod::PROVIDER_COD,
            'name' => 'Dobírka',
            'fee' => 0,
            'is_active' => true,
            ...$attributes,
        ]));
    }

    private function staffWithout(): User
    {
        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff', 'permissions' => ['products.view'], 'joined_at' => now(),
        ]);

        return $staff;
    }

    public function test_a_method_is_created_with_the_fee_as_submitted_haleire(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-platby'), $this->payload(['fee' => 2900]))
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('payment_methods', [
            'name' => 'Dobírka', 'fee' => 2900,
        ]));
    }

    public function test_a_bank_transfer_requires_an_account_on_create(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-platby'), $this->payload([
                'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER,
                'name' => 'Převodem',
            ]))
            ->assertSessionHasErrors('account');
    }

    public function test_a_malformed_account_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-platby'), $this->payload([
                'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER,
                'name' => 'Převodem',
                'account' => 'nonsense',
            ]))
            ->assertSessionHasErrors('account');
    }

    public function test_the_account_is_stored_encrypted_and_never_reaches_the_page_in_the_clear(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-platby'), $this->payload([
                'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER,
                'name' => 'Převodem',
                'account' => self::IBAN,
            ]))
            ->assertRedirect();

        $method = $this->context->runAs($this->tenant, fn () => PaymentMethod::query()->where('name', 'Převodem')->firstOrFail());

        // The raw column does not hold the IBAN in the clear.
        $raw = DB::table('payment_methods')->where('id', $method->id)->value('settings');
        $this->assertStringNotContainsString(self::IBAN, (string) $raw);

        // The Inertia page gets only the masked tail and a "set" flag, never the
        // full account.
        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Shipping/Index')
                ->where('paymentMethods.0.account_set', true)
                ->where('paymentMethods.0.account_masked', '…5399')
                ->missing('paymentMethods.0.settings')
            );

        // Belt and braces: the full account appears nowhere in the response body.
        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertDontSee(self::IBAN);
    }

    public function test_saving_without_a_new_account_preserves_the_stored_one(): void
    {
        $method = $this->make($this->tenant, [
            'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER,
            'name' => 'Převodem',
            'account' => self::IBAN,
        ]);

        // The admin edits the name and leaves the account field blank — it must
        // not blank the stored account.
        $this->actingAs($this->owner)
            ->put($this->url('/zpusoby-platby/'.$method->id), $this->payload([
                'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER,
                'name' => 'Bankovní převod',
                'account' => '',
            ]))
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($method) {
            $fresh = $method->fresh();
            $this->assertSame('Bankovní převod', $fresh->name);
            $this->assertSame(self::IBAN, $fresh->settings['account']);
        });
    }

    public function test_submitting_a_new_account_replaces_the_stored_one(): void
    {
        $method = $this->make($this->tenant, [
            'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER,
            'name' => 'Převodem',
            'account' => self::IBAN,
        ]);

        $this->actingAs($this->owner)
            ->put($this->url('/zpusoby-platby/'.$method->id), $this->payload([
                'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER,
                'name' => 'Převodem',
                'account' => '123456789/0800',
            ]))
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertSame(
            '123456789/0800',
            $method->fresh()->settings['account'],
        ));
    }

    public function test_a_method_of_another_shop_is_not_reachable(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'shipping');
        $foreign = $this->make($other);

        $this->actingAs($this->owner)
            ->put($this->url('/zpusoby-platby/'.$foreign->id), $this->payload())
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->delete($this->url('/zpusoby-platby/'.$foreign->id))
            ->assertNotFound();
    }

    public function test_a_method_is_deleted(): void
    {
        $method = $this->make($this->tenant);

        $this->actingAs($this->owner)
            ->delete($this->url('/zpusoby-platby/'.$method->id))
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseMissing('payment_methods', [
            'id' => $method->id,
        ]));
    }

    public function test_reorder_gaps_positions_and_is_tenant_scoped(): void
    {
        $first = $this->make($this->tenant, ['name' => 'První', 'position' => 10]);
        $second = $this->make($this->tenant, ['name' => 'Druhá', 'position' => 20]);

        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'shipping');
        $foreign = $this->make($other, ['position' => 50]);

        $this->actingAs($this->owner)
            ->put($this->url('/zpusoby-platby/poradi'), ['ids' => [$second->id, $first->id, $foreign->id]])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($first, $second) {
            $this->assertSame(20, $first->fresh()->position);
            $this->assertSame(10, $second->fresh()->position);
        });

        $this->context->runAs($other, fn () => $this->assertSame(50, $foreign->fresh()->position));
    }

    public function test_a_member_without_the_permission_cannot_write(): void
    {
        $staff = $this->staffWithout();
        $method = $this->make($this->tenant);

        $this->actingAs($staff)->post($this->url('/zpusoby-platby'), $this->payload())->assertForbidden();
        $this->actingAs($staff)->put($this->url('/zpusoby-platby/'.$method->id), $this->payload())->assertForbidden();
        $this->actingAs($staff)->delete($this->url('/zpusoby-platby/'.$method->id))->assertForbidden();
        $this->actingAs($staff)->put($this->url('/zpusoby-platby/poradi'), ['ids' => [$method->id]])->assertForbidden();
    }
}
