<?php

namespace App\Http\Controllers\Tenant;

use App\Core\Modules\Manifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Services\AuditLog;
use App\Core\Settings\SettingsField;
use App\Core\Settings\SettingsSchema;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateModuleSettingsRequest;
use App\Models\Module;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one screen every module's settings are edited on (wave 2.10).
 *
 * The form is generated from the schema the module ships, so a new module needs
 * no screen of its own. Two gates, in this order: a module the shop does not
 * run is a 404 (a 403 would confirm which modules it is missing), and the
 * permission is the one the manifest names — there is no uniform `<key>.manage`
 * across modules to fall back on.
 */
class ModuleSettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ModuleRegistry $registry,
        private readonly SettingsService $settings,
        private readonly AuditLog $audit,
    ) {}

    public function index(): Response
    {
        $tenant = $this->context->current();

        $modules = $this->registry->enabledFor($tenant)
            ->filter(fn (Module $module) => $this->settings->schemaFor($module->key) !== null)
            ->filter(fn (Module $module) => $this->mayManage($module))
            ->map(fn (Module $module) => [
                'key' => $module->key,
                'name' => Manifest::fromArray($module->manifest)->titleFor(),
            ])
            ->values()
            ->all();

        return Inertia::render('Tenant/ModuleSettingsIndex', ['modules' => $modules]);
    }

    public function edit(string $module): Response
    {
        [$model, $schema] = $this->authorizeModule($module);

        return Inertia::render('Tenant/ModuleSettings', [
            'module' => [
                'key' => $model->key,
                'name' => Manifest::fromArray($model->manifest)->titleFor(),
            ],
            'fields' => array_map(fn (SettingsField $field) => [
                'key' => $field->key,
                'label' => $field->label,
                'type' => $field->type,
                'help' => $field->help,
                'options' => $field->options,
                'secret' => $field->secret,
            ], $schema->fields()),
            'values' => $this->maskSecrets($schema, $this->settings->all($model->key)),
        ]);
    }

    /**
     * A stored credential never travels back to the browser — the screen only
     * learns whether one exists (spec §16.5, same as the Comgate and Packeta
     * credential forms). SettingsService::setMany() treats a blank secret as
     * "leave it alone", so an untouched field survives a save.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function maskSecrets(SettingsSchema $schema, array $values): array
    {
        foreach ($schema->fields() as $field) {
            if (! $field->secret) {
                continue;
            }

            $values[$field->key.'_stored'] = ($values[$field->key] ?? '') !== '';
            $values[$field->key] = '';
        }

        return $values;
    }

    public function update(UpdateModuleSettingsRequest $request, string $module): RedirectResponse
    {
        [$model] = $this->authorizeModule($module);

        $values = $request->validated('values');

        $this->settings->setMany($model->key, $values);

        $this->audit->log('module.settings_updated', null, [
            'module' => $model->key,
            'keys' => array_keys($values),
        ]);

        return back()->with('success', 'Nastavení uloženo.');
    }

    /**
     * 404 for a module this shop does not run or that has no schema, 403 for a
     * member without the right the manifest names. Returns the module and its
     * schema so callers do not resolve either twice.
     *
     * @return array{0: Module, 1: SettingsSchema}
     */
    private function authorizeModule(string $module): array
    {
        $tenant = $this->context->current();

        $model = $this->registry->all()->get($module);

        if ($model === null || ! $this->registry->isEnabled($tenant, $module)) {
            throw new NotFoundHttpException;
        }

        $schema = $this->settings->schemaFor($module);

        if ($schema === null) {
            throw new NotFoundHttpException;
        }

        abort_unless($this->mayManage($model), 403);

        return [$model, $schema];
    }

    private function mayManage(Module $module): bool
    {
        $permission = Manifest::fromArray($module->manifest)->settingsPermission;

        // No declared permission means nobody may edit the module from here.
        // Falling back to "anyone who can reach the admin" would hand a staff
        // member the invoice numbering of a module they cannot otherwise touch.
        return $permission !== null && Gate::allows($permission);
    }
}
