<?php

namespace Tests\Feature\Core;

use App\Core\Modules\Exceptions\InvalidManifest;
use App\Core\Modules\ManifestValidator;
use Tests\TestCase;

class ManifestValidatorTest extends TestCase
{
    public function test_a_module_with_a_settings_schema_must_name_the_permission_that_guards_it(): void
    {
        $this->expectException(InvalidManifest::class);

        app(ManifestValidator::class)->validate([
            'name' => 'demo',
            'version' => '1.0.0',
            'permissions' => ['demo.manage'],
            'settings_schema' => 'settings.json',
            // settings_permission chybí
        ]);
    }

    public function test_the_settings_permission_must_be_one_the_module_declares(): void
    {
        $this->expectException(InvalidManifest::class);

        app(ManifestValidator::class)->validate([
            'name' => 'demo',
            'version' => '1.0.0',
            'permissions' => ['demo.manage'],
            'settings_schema' => 'settings.json',
            'settings_permission' => 'demo.other',
        ]);
    }

    public function test_a_module_without_a_schema_needs_no_settings_permission(): void
    {
        $manifest = app(ManifestValidator::class)->validate([
            'name' => 'demo',
            'version' => '1.0.0',
        ]);

        $this->assertNull($manifest->settingsPermission);
    }
}
