<?php

namespace Tests\Feature\Tenant;

use App\Core\Enums\TenantStatus;
use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\TenantTheme;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppearanceControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function ownerOnHost(array $tenantAttributes = [], string $subdomain = 'shop'): array
    {
        $tenant = Tenant::factory()->create($tenantAttributes);
        Domain::create([
            'tenant_id' => $tenant->id,
            'domain' => $subdomain.'.'.config('tenancy.platform_domain'),
            'type' => 'subdomain',
            'is_primary' => true,
        ]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return [$tenant, $owner];
    }

    private function host(string $subdomain = 'shop'): string
    {
        return 'http://'.$subdomain.'.'.config('tenancy.platform_domain');
    }

    public function test_owner_can_view_the_appearance_screen(): void
    {
        [, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->get($this->host().'/admin/nastaveni/vzhled')
            ->assertInertia(fn (Assert $p) => $p->component('Tenant/Appearance')
                ->where('appearance.primary_color', TenantTheme::DEFAULT_PRIMARY)
                ->where('appearance.accent_color', TenantTheme::DEFAULT_ACCENT)
                ->where('appearance.logo_url', null)
                ->where('appearance.favicon_url', null)
                ->has('appearance.contrast_ratio')
            );
    }

    public function test_owner_can_update_brand_colors(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/vzhled', [
                'primary_color' => '#0f766e',
                'accent_color' => '#2563eb',
            ])
            ->assertRedirect();

        $theme = TenantTheme::where('tenant_id', $tenant->id)->first();

        $this->assertNotNull($theme);
        $this->assertSame('#0f766e', $theme->primary_color);
        $this->assertSame('#2563eb', $theme->accent_color);
    }

    public function test_owner_can_upload_a_logo(): void
    {
        Storage::fake(FileStorage::PUBLIC_DISK);

        [$tenant, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/vzhled', [
                'primary_color' => '#0f766e',
                'accent_color' => '#2563eb',
                'logo' => UploadedFile::fake()->image('logo.png', 200, 60)->size(10),
            ])
            ->assertRedirect();

        $theme = TenantTheme::where('tenant_id', $tenant->id)->first();

        $this->assertNotNull($theme->logo_path);

        $exists = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(FileStorage::class)->exists($theme->logo_path, private: false)
        );

        $this->assertTrue($exists);
    }

    public function test_owner_can_upload_an_ico_favicon(): void
    {
        // .ico is the conventional favicon format but is not in Laravel's
        // `image` rule mime whitelist — favicon uses an explicit
        // extension/mime list instead (unlike logo), so this must succeed,
        // not 422.
        Storage::fake(FileStorage::PUBLIC_DISK);

        [$tenant, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/vzhled', [
                'primary_color' => '#0f766e',
                'accent_color' => '#2563eb',
                'favicon' => UploadedFile::fake()->create('favicon.ico', 10, 'image/x-icon'),
            ])
            ->assertSessionDoesntHaveErrors('favicon')
            ->assertRedirect();

        $theme = TenantTheme::where('tenant_id', $tenant->id)->first();

        $this->assertNotNull($theme->favicon_path);

        $exists = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(FileStorage::class)->exists($theme->favicon_path, private: false)
        );

        $this->assertTrue($exists);
    }

    public function test_rejects_an_svg_favicon(): void
    {
        // SVG is deliberately excluded from the favicon whitelist: files on
        // this disk are served as static assets with Content-Type
        // image/svg+xml, and an SVG can embed <script> — allowing it would
        // be stored XSS reachable by any tenant admin. Same reasoning as
        // ProductImageService::ALLOWED_MIMES (raster-only, no svg).
        Storage::fake(FileStorage::PUBLIC_DISK);

        [, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/vzhled', [
                'primary_color' => '#0f766e',
                'accent_color' => '#2563eb',
                'favicon' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml'),
            ])
            ->assertSessionHasErrors('favicon');
    }

    public function test_rejects_an_invalid_hex_color(): void
    {
        [, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/vzhled', [
                'primary_color' => 'red',
                'accent_color' => '#2563eb',
            ])
            ->assertSessionHasErrors('primary_color');
    }

    public function test_rejects_a_hex_color_with_a_trailing_newline(): void
    {
        // PCRE `$` matches before a trailing newline unless the `D` modifier
        // is set — without it, "#0f766e\n" would slip past the regex rule.
        // TrimStrings (default web middleware) would otherwise strip the
        // newline before validation ever sees it, masking the bug this test
        // is for — disabled here so the FormRequest's own regex is what's
        // actually under test.
        $this->withoutMiddleware(TrimStrings::class);

        [, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/vzhled', [
                'primary_color' => "#0f766e\n",
                'accent_color' => '#2563eb',
            ])
            ->assertSessionHasErrors('primary_color');
    }

    public function test_rejects_an_oversized_logo(): void
    {
        Storage::fake(FileStorage::PUBLIC_DISK);

        [, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/vzhled', [
                'primary_color' => '#0f766e',
                'accent_color' => '#2563eb',
                'logo' => UploadedFile::fake()->image('logo.png', 2000, 2000)->size(1024),
            ])
            ->assertSessionHasErrors('logo');
    }

    public function test_owner_can_delete_the_logo(): void
    {
        Storage::fake(FileStorage::PUBLIC_DISK);

        [$tenant, $owner] = $this->ownerOnHost();

        $context = app(TenantContext::class);
        $path = $context->runAs($tenant, fn () => app(FileStorage::class)->putPublic('theme/logo.png', 'fake-bytes'));

        TenantTheme::create(['tenant_id' => $tenant->id, 'logo_path' => $path]);

        $this->actingAs($owner)
            ->delete($this->host().'/admin/nastaveni/vzhled/logo')
            ->assertRedirect();

        $theme = TenantTheme::where('tenant_id', $tenant->id)->first();
        $this->assertNull($theme->logo_path);

        $exists = $context->runAs($tenant, fn () => app(FileStorage::class)->exists($path, private: false));
        $this->assertFalse($exists);
    }

    public function test_owner_can_delete_the_favicon(): void
    {
        Storage::fake(FileStorage::PUBLIC_DISK);

        [$tenant, $owner] = $this->ownerOnHost();

        $context = app(TenantContext::class);
        $path = $context->runAs($tenant, fn () => app(FileStorage::class)->putPublic('theme/favicon.png', 'fake-bytes'));

        TenantTheme::create(['tenant_id' => $tenant->id, 'favicon_path' => $path]);

        $this->actingAs($owner)
            ->delete($this->host().'/admin/nastaveni/vzhled/favicon')
            ->assertRedirect();

        $theme = TenantTheme::where('tenant_id', $tenant->id)->first();
        $this->assertNull($theme->favicon_path);

        $exists = $context->runAs($tenant, fn () => app(FileStorage::class)->exists($path, private: false));
        $this->assertFalse($exists);
    }

    public function test_guest_cannot_access(): void
    {
        $this->ownerOnHost();

        $this->get($this->host().'/admin/nastaveni/vzhled')
            ->assertRedirect(); // tenant.member throws AuthenticationException -> login redirect
    }

    public function test_non_member_cannot_access(): void
    {
        $this->ownerOnHost();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get($this->host().'/admin/nastaveni/vzhled')
            ->assertForbidden();
    }

    public function test_suspended_tenant_cannot_write(): void
    {
        [, $owner] = $this->ownerOnHost(['status' => TenantStatus::Suspended]);

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/vzhled', [
                'primary_color' => '#0f766e',
                'accent_color' => '#2563eb',
            ])
            ->assertStatus(503);
    }

    public function test_owner_cannot_change_another_tenants_theme(): void
    {
        [$tenantA, $ownerA] = $this->ownerOnHost(subdomain: 'shopa');
        [$tenantB] = $this->ownerOnHost(subdomain: 'shopb');

        TenantTheme::create([
            'tenant_id' => $tenantB->id,
            'primary_color' => '#111111',
        ]);

        $this->actingAs($ownerA)
            ->post($this->host('shopa').'/admin/nastaveni/vzhled', [
                'primary_color' => '#0f766e',
                'accent_color' => '#2563eb',
            ])
            ->assertRedirect();

        $themeA = TenantTheme::where('tenant_id', $tenantA->id)->first();
        $themeB = TenantTheme::where('tenant_id', $tenantB->id)->first();

        $this->assertSame('#0f766e', $themeA->primary_color);
        $this->assertSame('#111111', $themeB->primary_color);
    }
}
