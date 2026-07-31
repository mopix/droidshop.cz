<?php

namespace Tests\Feature\Tenant;

use App\Http\Requests\Tenant\UpdateBillingProfileRequest;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillingProfileTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOnHost(): array
    {
        $tenant = Tenant::factory()->create();
        Domain::create(['tenant_id' => $tenant->id, 'domain' => 'shop.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return [$tenant, $owner];
    }

    public function test_owner_can_view_billing_profile(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->get('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace')
            ->assertInertia(fn (Assert $p) => $p->component('Tenant/BillingProfile'));
    }

    /**
     * A profile saved before this field existed has a billing_address with no
     * 'country' key at all. The edit screen must still show something the
     * owner can submit unchanged — it must not silently render blank and let
     * 'required' reject a field the owner never had a chance to fill in.
     */
    public function test_a_legacy_profile_without_a_country_is_prefilled_with_cz(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();
        $tenant->update(['billing_address' => ['street' => 'Ulice 1', 'city' => 'Praha', 'zip' => '11000']]);

        $this->actingAs($owner)
            ->get('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace')
            ->assertInertia(fn (Assert $p) => $p->where('profile.billing_address.country', 'CZ'));
    }

    /**
     * The same legacy profile, saved back through the form unchanged — proof
     * that the CZ prefill (not just visible in the UI) is what actually
     * reaches the request, so 'required' never blocks an owner who never
     * touched the new field.
     *
     * Genuinely round-trips: GETs the edit page, captures the exact
     * billing_address the controller returned (rather than hand-writing
     * 'country' => 'CZ' in the payload), then PATCHes that value back
     * unchanged — so this fails if BillingProfileController::edit()'s CZ
     * merge is ever removed, which two other tests do not exercise.
     */
    public function test_a_legacy_profile_can_be_resaved_without_the_owner_typing_a_country(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();
        $tenant->update(['billing_address' => ['street' => 'Ulice 1', 'city' => 'Praha', 'zip' => '11000']]);

        $capturedAddress = null;

        $this->actingAs($owner)
            ->get('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace')
            ->assertInertia(function (Assert $page) use (&$capturedAddress) {
                $page->where('profile.billing_address', function ($address) use (&$capturedAddress) {
                    // AssertableJson::where() hands the closure a Collection,
                    // not the raw array, whenever the prop is an array — kept
                    // as a plain array here so Arr::get() can dot into it
                    // once it is fed back as the PATCH payload below.
                    $capturedAddress = $address instanceof Collection
                        ? $address->all()
                        : $address;

                    return true;
                });
            });

        $this->assertSame('CZ', $capturedAddress['country'] ?? null, 'Precondition: the edit page must have prefilled CZ.');

        $this->actingAs($owner)
            ->patch('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace', [
                'billing_name' => 'Nájemce s.r.o.',
                'billing_ico' => '12345678',
                'billing_dic' => 'CZ12345678',
                'vat_payer' => true,
                'billing_address' => $capturedAddress,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('CZ', $tenant->fresh()->billing_address['country']);
    }

    public function test_owner_can_update_billing_profile(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->patch('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace', [
                'billing_name' => 'Nájemce s.r.o.',
                'billing_ico' => '12345678',
                'billing_dic' => 'CZ12345678',
                'vat_payer' => true,
                'billing_address' => ['street' => 'Ulice 1', 'city' => 'Praha', 'zip' => '11000', 'country' => 'CZ'],
            ])->assertRedirect();

        $this->assertSame('Nájemce s.r.o.', $tenant->fresh()->billing_name);
    }

    public function test_owner_can_store_a_non_czech_country(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->patch('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace', [
                'billing_name' => 'Nájemce s.r.o.',
                'billing_ico' => '12345678',
                'billing_dic' => 'SK12345678',
                'vat_payer' => true,
                'billing_address' => ['street' => 'Ulica 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'sk'],
            ])->assertRedirect();

        // Lowercase submitted, uppercase stored — prepareForValidation() normalises.
        $this->assertSame('SK', $tenant->fresh()->billing_address['country']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCountryProvider(): array
    {
        return [
            'full name, not a code' => ['Česko'],
            'three-letter ISO code' => ['CZE'],
            'single letter' => ['x'],
        ];
    }

    #[DataProvider('malformedCountryProvider')]
    public function test_a_malformed_country_is_rejected(string $country): void
    {
        [$tenant, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->patch('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace', [
                'billing_name' => 'Nájemce s.r.o.',
                'billing_ico' => '12345678',
                'billing_dic' => 'CZ12345678',
                'vat_payer' => true,
                'billing_address' => ['street' => 'Ulice 1', 'city' => 'Praha', 'zip' => '11000', 'country' => $country],
            ])->assertSessionHasErrors('billing_address.country');

        $this->assertNull($tenant->fresh()->billing_address);
    }

    /**
     * The regex must anchor to the true end of the string, not "before a
     * trailing newline" (PCRE's default $ behaviour without /D) — the same
     * bug class already fixed in App\Core\Theme\ThemeResolver::sanitizeHex()
     * (CLAUDE.md, rozhodnutí 2026-07-25).
     *
     * Exercised directly against the FormRequest's own rules() rather than
     * through the HTTP layer: the "web" middleware group's TrimStrings
     * (Laravel's default) trims a trailing "\n" off every string input
     * before a FormRequest ever sees it, so an HTTP round-trip could not
     * distinguish a working /D from a missing one — it would pass either
     * way, for the wrong reason.
     */
    public function test_the_country_regex_rejects_a_value_with_a_trailing_newline(): void
    {
        $validator = Validator::make(
            [
                'billing_name' => 'Nájemce s.r.o.',
                'vat_payer' => true,
                'billing_address' => ['street' => 'Ulice 1', 'city' => 'Praha', 'zip' => '11000', 'country' => "CZ\n"],
            ],
            (new UpdateBillingProfileRequest)->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('billing_address.country', $validator->errors()->toArray());
    }

    public function test_guest_cannot_access(): void
    {
        $this->ownerOnHost();
        $this->get('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace')
            ->assertRedirect(); // tenant.member throws AuthenticationException -> login redirect
    }
}
