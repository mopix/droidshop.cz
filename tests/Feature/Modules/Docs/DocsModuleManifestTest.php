<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class DocsModuleManifestTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    public function test_manifest_declares_docs_manage_permission(): void
    {
        $manifest = json_decode(file_get_contents(base_path('Modules/Docs/module.json')), true);

        $this->assertSame('docs', $manifest['name']);
        $this->assertSame('base', $manifest['level']);
        $this->assertContains('docs.manage', $manifest['permissions']);
        $this->assertSame('settings.json', $manifest['settings_schema']);
    }

    public function test_settings_default_auto_issue_on_paid(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant);

        $this->assertSame('paid', app(SettingsService::class)->get('docs', 'auto_issue_on', 'paid'));
    }
}
