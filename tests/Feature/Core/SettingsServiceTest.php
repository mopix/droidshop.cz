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
use InvalidArgumentException;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private SettingsService $settings;

    private TenantContext $context;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->settings = app(SettingsService::class);
        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();

        // The docs module ships a real settings.json (due_days, number_prefix,
        // ...) — reused below instead of a synthetic fixture schema.
        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'docs');
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

    public function test_an_unset_value_reads_as_the_schema_default(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $settings = app(SettingsService::class);

            // docs/settings.json declares due_days default 14
            $this->assertSame(14, $settings->get('docs', 'due_days'));
        });
    }

    public function test_set_many_writes_every_value(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $settings = app(SettingsService::class);

            $settings->setMany('docs', ['due_days' => 30, 'number_prefix' => 'FV']);

            $this->assertSame(30, $settings->get('docs', 'due_days'));
            $this->assertSame('FV', $settings->get('docs', 'number_prefix'));
        });
    }

    public function test_set_many_writes_nothing_when_one_value_is_invalid(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $settings = app(SettingsService::class);
            $settings->setMany('docs', ['due_days' => 30]);

            try {
                $settings->setMany('docs', ['due_days' => 45, 'number_prefix' => str_repeat('x', 500)]);
                $this->fail('Expected InvalidSetting.');
            } catch (InvalidSetting) {
                // expected
            }

            $this->assertSame(30, $settings->get('docs', 'due_days'));
        });
    }

    public function test_set_many_refuses_a_key_the_schema_does_not_declare(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $this->expectException(InvalidSetting::class);

            app(SettingsService::class)->setMany('docs', ['nonsense' => 1]);
        });
    }

    public function test_one_tenants_settings_never_leak_into_another(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $this->context->runAs($this->tenant, fn () => app(SettingsService::class)->setMany('docs', ['due_days' => 30]));

        $this->context->runAs($other, function (): void {
            $this->assertSame(14, app(SettingsService::class)->get('docs', 'due_days'));
        });
    }

    public function test_all_propagates_a_malformed_on_disk_schema_instead_of_swallowing_it(): void
    {
        // modules:sync (see ModulesSyncTest) is supposed to keep this out of
        // a running shop, but SettingsService::all() must not paper over it
        // either — it is the runtime backstop, not a second gate that hides
        // the same defect behind a silently empty settings array.
        $this->registerBrokenSchemaModule();

        $this->expectException(InvalidArgumentException::class);

        $this->context->runAs($this->tenantA, fn () => app(SettingsService::class)->all('brokenmod'));
    }

    /**
     * Registers a module whose manifest points at a settings schema on disk.
     */
    private function registerSchemaModule(): void
    {
        $dir = base_path('Modules/Demo');
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/settings.json', json_encode([
            'per_page' => 'integer|min:1|max:100',
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

    /**
     * Registers a module whose on-disk schema itself is malformed (a field
     * with no rules) — the shape modules:sync is meant to reject before it
     * ever reaches here, exercised directly against SettingsService::all()
     * without going through the sync command.
     */
    private function registerBrokenSchemaModule(): void
    {
        $dir = base_path('Modules/Brokenmod');
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/settings.json', json_encode([
            'due_days' => ['label' => 'No rules here'],
        ]));

        Module::create([
            'key' => 'brokenmod',
            'version' => '1.0.0',
            'manifest' => [
                'name' => 'brokenmod',
                'version' => '1.0.0',
                'settings_schema' => 'settings.json',
            ],
        ]);

        app(ModuleRegistry::class)->flush();
    }

    protected function tearDown(): void
    {
        foreach (['Demo', 'Brokenmod'] as $moduleDir) {
            $dir = base_path('Modules/'.$moduleDir);
            @unlink($dir.'/settings.json');
            @rmdir($dir);
        }

        parent::tearDown();
    }
}
