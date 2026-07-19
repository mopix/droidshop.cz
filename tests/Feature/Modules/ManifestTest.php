<?php

namespace Tests\Feature\Modules;

use App\Core\Enums\PlanLevel;
use App\Core\Modules\Exceptions\InvalidManifest;
use App\Core\Modules\ManifestValidator;
use Tests\TestCase;

class ManifestTest extends TestCase
{
    private ManifestValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = app(ManifestValidator::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function manifest(array $overrides = []): array
    {
        return array_merge([
            'name' => 'pages',
            'version' => '1.0.0',
            'title' => ['cs' => 'Stránky'],
            'core' => false,
            'requires' => [],
            'nav' => [
                ['area' => 'admin', 'label' => 'Stránky', 'route' => 'admin.pages.index', 'order' => 30],
            ],
        ], $overrides);
    }

    public function test_valid_manifest_is_parsed(): void
    {
        $manifest = $this->validator->validate($this->manifest());

        $this->assertSame('pages', $manifest->name);
        $this->assertSame('1.0.0', $manifest->version);
        $this->assertSame('Stránky', $manifest->titleFor('cs'));
        $this->assertSame(PlanLevel::Base, $manifest->level);
        $this->assertFalse($manifest->core);
    }

    public function test_title_falls_back_when_locale_is_missing(): void
    {
        $manifest = $this->validator->validate($this->manifest());

        $this->assertSame('Stránky', $manifest->titleFor('en'));
    }

    public function test_missing_name_is_rejected(): void
    {
        $this->expectException(InvalidManifest::class);

        $this->validator->validate(['version' => '1.0.0']);
    }

    public function test_name_must_be_a_slug(): void
    {
        // The name becomes a route prefix and a database key; spaces and
        // capitals there cause trouble far from where they were typed.
        $this->expectException(InvalidManifest::class);

        $this->validator->validate($this->manifest(['name' => 'My Pages']));
    }

    public function test_invalid_version_is_rejected(): void
    {
        $this->expectException(InvalidManifest::class);

        $this->validator->validate($this->manifest(['version' => 'nightly']));
    }

    public function test_invalid_dependency_constraint_is_rejected(): void
    {
        $this->expectException(InvalidManifest::class);

        $this->validator->validate($this->manifest(['requires' => ['products' => 'whatever']]));
    }

    public function test_valid_dependency_constraint_is_accepted(): void
    {
        $manifest = $this->validator->validate($this->manifest(['requires' => ['products' => '^1.2']]));

        $this->assertSame(['products'], $manifest->dependencyKeys());
    }

    public function test_unknown_level_is_rejected(): void
    {
        $this->expectException(InvalidManifest::class);

        $this->validator->validate($this->manifest(['level' => 'enterprise']));
    }

    public function test_nav_entry_without_route_is_rejected(): void
    {
        $this->expectException(InvalidManifest::class);

        $this->validator->validate($this->manifest([
            'nav' => [['area' => 'admin', 'label' => 'Stránky']],
        ]));
    }

    public function test_error_message_names_the_offending_field(): void
    {
        // A sync that fails has to say what to fix, not just that something is wrong.
        try {
            $this->validator->validate(['name' => 'pages'], 'Modules/Pages/module.json');
            $this->fail('Expected InvalidManifest.');
        } catch (InvalidManifest $e) {
            $this->assertStringContainsString('Modules/Pages/module.json', $e->getMessage());
            $this->assertStringContainsString('version', $e->getMessage());
        }
    }

    public function test_missing_file_is_reported_clearly(): void
    {
        $this->expectException(InvalidManifest::class);

        $this->validator->validateFile('/nope/module.json');
    }
}
