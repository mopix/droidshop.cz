<?php

namespace App\Console\Commands;

use App\Core\Modules\Exceptions\InvalidManifest;
use App\Core\Modules\Manifest;
use App\Core\Modules\ManifestValidator;
use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\SettingsSchema;
use App\Models\Module;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Reads module manifests from disk into the registry (spec §15.5 bod 1).
 *
 * Deploy-time only. It never touches tenant_modules: what is installed and
 * who has it switched on are separate questions with separate answers.
 */
class ModulesSync extends Command
{
    protected $signature = 'modules:sync {--prune : Remove registry rows whose module is no longer on disk}';

    protected $description = 'Read module manifests from disk and update the module registry';

    public function handle(ManifestValidator $validator, ModuleRegistry $registry): int
    {
        $manifests = [];

        foreach ($this->manifestPaths() as $path) {
            try {
                $manifest = $validator->validateFile($path);

                // A malformed settings schema must fail here, at sync time —
                // not later inside SettingsService::all(), which runs on
                // request/job hot paths (checkout, invoice issuance, PDF
                // generation). Failing loudly is right; failing loudly
                // during a customer's order settlement is not. A deploy that
                // ships a broken schema fails at deploy, not at checkout.
                $this->assertSettingsSchemaIsValid($manifest, $path);
            } catch (InvalidManifest $e) {
                // Abort the whole sync: a partially updated registry is worse
                // than an outdated one, because it looks correct.
                $this->error($e->getMessage());

                return self::FAILURE;
            } catch (InvalidArgumentException $e) {
                $this->error("Module [{$manifest->name}] has an invalid settings schema: {$e->getMessage()}");

                return self::FAILURE;
            }

            $manifests[$manifest->name] = $manifest;
        }

        $added = $updated = 0;

        foreach ($manifests as $manifest) {
            $existing = Module::find($manifest->name);

            Module::updateOrCreate(['key' => $manifest->name], [
                'version' => $manifest->version,
                'core' => $manifest->core,
                'level' => $manifest->level,
                'manifest' => $manifest->toArray(),
            ]);

            $existing ? $updated++ : $added++;
        }

        $removed = $this->prune(array_keys($manifests));

        $registry->flush();

        $this->info(sprintf(
            'Modules synced: %d added, %d updated%s.',
            $added,
            $updated,
            $removed !== null ? ", {$removed} removed" : ''
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $keep
     */
    private function prune(array $keep): ?int
    {
        if (! $this->option('prune')) {
            $stale = Module::query()->whereNotIn('key', $keep)->pluck('key');

            if ($stale->isNotEmpty()) {
                $this->warn(
                    'These modules are in the registry but not on disk: '.$stale->implode(', ').
                    '. Run with --prune to remove them.'
                );
            }

            return null;
        }

        // Deleting a module row cascades to tenant_modules, so this is a
        // destructive operation and stays opt-in.
        return Module::query()->whereNotIn('key', $keep)->delete();
    }

    /**
     * Parses the module's settings.json (if it declares one) the same way
     * SettingsService::schemaFor() would at runtime, purely to surface a
     * malformed schema (see SettingsSchema::fromArray()) as a sync failure.
     * A missing file is not this method's concern — schemaFor() already
     * treats that as "no schema" and degrades safely.
     *
     * @throws InvalidArgumentException
     */
    private function assertSettingsSchemaIsValid(Manifest $manifest, string $modulePath): void
    {
        if ($manifest->settingsSchema === null) {
            return;
        }

        $schemaPath = dirname($modulePath).'/'.$manifest->settingsSchema;

        if (! is_file($schemaPath)) {
            return;
        }

        SettingsSchema::fromArray(json_decode((string) file_get_contents($schemaPath), true) ?? []);
    }

    /**
     * @return list<string>
     */
    private function manifestPaths(): array
    {
        $pattern = base_path('Modules/*/module.json');

        return glob($pattern) ?: [];
    }
}
