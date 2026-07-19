<?php

namespace Tests\Feature\Core;

use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\Exceptions\InvalidSetting;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use App\Models\Module;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private SettingsService $settings;

    private TenantContext $context;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $this->settings = app(SettingsService::class);
        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();
    }

    public function test_get_returns_default_when_unset(): void
    {
        $value = $this->context->runAs($this->tenantA, fn () => $this->settings->get('pages', 'per_page', 20));

        $this->assertSame(20, $value);
    }

    public function test_set_then_get(): void
    {
        $this->context->runAs($this->tenantA, function (): void {
            $this->settings->set('pages', 'per_page', 50);
        });

        $value = $this->context->runAs($this->tenantA, fn () => $this->settings->get('pages', 'per_page'));

        $this->assertSame(50, $value);
    }

    public function test_settings_are_isolated_per_tenant(): void
    {
        $this->context->runAs($this->tenantA, fn () => $this->settings->set('pages', 'per_page', 50));

        $seenByB = $this->context->runAs($this->tenantB, fn () => $this->settings->get('pages', 'per_page', 20));

        $this->assertSame(20, $seenByB, 'Tenant B must not see tenant A settings.');
    }

    public function test_complex_values_survive_a_round_trip(): void
    {
        $payload = ['columns' => ['a', 'b'], 'nested' => ['x' => 1]];

        $this->context->runAs($this->tenantA, fn () => $this->settings->set('pages', 'layout', $payload));

        $value = $this->context->runAs($this->tenantA, fn () => $this->settings->get('pages', 'layout'));

        // Key order is not part of the contract, only the content.
        $this->assertEquals($payload, $value);
    }

    public function test_write_invalidates_the_cache(): void
    {
        $this->context->runAs($this->tenantA, function (): void {
            $this->settings->set('pages', 'per_page', 20);
            $this->assertSame(20, $this->settings->get('pages', 'per_page'));

            $this->settings->set('pages', 'per_page', 99);
            $this->assertSame(99, $this->settings->get('pages', 'per_page'));
        });
    }

    public function test_access_without_a_tenant_throws(): void
    {
        $this->expectException(MissingTenantContext::class);

        $this->settings->get('pages', 'per_page');
    }

    public function test_value_is_validated_against_module_schema(): void
    {
        // Pages ships a settings schema (see the test module setup below); an
        // out-of-range value must be refused, not stored.
        $this->registerSchemaModule();

        $this->expectException(InvalidSetting::class);

        $this->context->runAs($this->tenantA, fn () => $this->settings->set('demo', 'per_page', 'not-an-int'));
    }

    public function test_unknown_key_is_rejected_when_a_schema_exists(): void
    {
        $this->registerSchemaModule();

        $this->expectException(InvalidSetting::class);

        $this->context->runAs($this->tenantA, fn () => $this->settings->set('demo', 'ghost', 1));
    }

    public function test_valid_value_passes_schema(): void
    {
        $this->registerSchemaModule();

        $this->context->runAs($this->tenantA, fn () => $this->settings->set('demo', 'per_page', 30));

        $this->assertSame(30, $this->context->runAs($this->tenantA, fn () => $this->settings->get('demo', 'per_page')));
    }

    /**
     * Registers a module whose manifest points at a settings schema on disk.
     */
    private function registerSchemaModule(): void
    {
        $dir = base_path('Modules/Demo');
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/settings.json', json_encode([
            'per_page' => ['integer', 'min:1', 'max:100'],
        ]));

        Module::create([
            'key' => 'demo',
            'version' => '1.0.0',
            'manifest' => [
                'name' => 'demo',
                'version' => '1.0.0',
                'settings_schema' => 'settings.json',
            ],
        ]);

        app(ModuleRegistry::class)->flush();
    }

    protected function tearDown(): void
    {
        $dir = base_path('Modules/Demo');
        @unlink($dir.'/settings.json');
        @rmdir($dir);

        parent::tearDown();
    }
}
