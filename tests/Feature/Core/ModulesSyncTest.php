<?php

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * A malformed on-disk settings.json (see SettingsSchema::fromArray()) must
 * fail modules:sync — deploy time — rather than surface later inside
 * SettingsService::all(), which runs on request/job hot paths such as
 * checkout, invoice issuance and PDF generation.
 */
class ModulesSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_fails_for_a_module_whose_schema_has_a_rule_less_field(): void
    {
        $this->writeModule('brokenschema', [
            // No 'rules' key and not a list of rule strings either — this
            // field would silently validate anything if it reached a shop.
            'due_days' => ['label' => 'No rules here'],
        ]);

        // Both substrings land on the same single error() line, and
        // PendingCommand::expectsOutputToContain() consumes one output line
        // per expectation — a plain substring check on the captured output
        // avoids that mismatch.
        $exitCode = Artisan::call('modules:sync');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('brokenschema', $output);
        $this->assertStringContainsString('due_days', $output);
    }

    public function test_sync_passes_for_a_well_formed_schema(): void
    {
        $this->writeModule('goodschema', [
            'per_page' => 'integer|min:1|max:100',
        ]);

        $this->artisan('modules:sync')->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function writeModule(string $key, array $schema): void
    {
        $dir = base_path('Modules/'.str($key)->studly());
        @mkdir($dir, 0777, true);

        file_put_contents($dir.'/module.json', json_encode([
            'name' => $key,
            'version' => '1.0.0',
            'permissions' => ["{$key}.manage"],
            'settings_schema' => 'settings.json',
            'settings_permission' => "{$key}.manage",
        ]));

        file_put_contents($dir.'/settings.json', json_encode($schema));
    }

    protected function tearDown(): void
    {
        foreach (['brokenschema', 'goodschema'] as $key) {
            $dir = base_path('Modules/'.str($key)->studly());
            @unlink($dir.'/module.json');
            @unlink($dir.'/settings.json');
            @rmdir($dir);
        }

        parent::tearDown();
    }
}
