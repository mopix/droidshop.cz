# Vlna 2.10 — Nastavení modulů — implementační plán

> **Pro agentní workery:** POVINNÁ SUB-SKILL — implementuj po úkolech přes `superpowers:subagent-driven-development` (doporučeno) nebo `superpowers:executing-plans`. Kroky mají checkboxy (`- [ ]`).

**Cíl:** Nájemce nastaví chování modulu z adminu (generická obrazovka ze schématu v manifestu) a superadmin přiřadí modul tarifu bez migrace.

**Architektura:** Modul deklaruje `settings_schema` (nově objektový tvar s popisky) a `settings_permission`. Jádro to načte do `SettingsSchema`, jedna generická Inertia obrazovka z toho vyrenderuje formulář, `SettingsService::setMany()` to zvaliduje a zapíše. Superadmin dostane obrazovku nad `plan_modules`, jejíž zápis rekonciliuje moduly všech tenantů tarifu stejnou logikou jako `TenantPlanSwitcher`.

**Stack:** Laravel 13, Inertia + Vue 3 (`<script setup lang="ts">`), Tailwind, PHPUnit, MySQL/SQLite.

**Spec:** `docs/superpowers/specs/2026-07-29-vlna-210-nastaveni-modulu-design.md`

## Globální omezení

- Kód, komentáře a commity anglicky; texty pro uživatele česky.
- Storefront je Blade SSR — `checkout` nastavení musí fungovat **bez JS** (`.claude/rules/storefront-rendering.md`).
- Peníze jsou `App\Core\Money\Money` v haléřích, nikdy float.
- Žádné nové composer/npm závislosti.
- Před commitem: `./vendor/bin/pint` na dotčené PHP soubory, `php artisan test` na dotčenou oblast.
- Tenant-scoped zápis jde vždy přes ambient kontext (`TenantContext::runAs`), nikdy přes ruční `where('tenant_id', …)` v controlleru.
- Testy běží s `config()->set('cache.default', 'array')` — `SettingsService` cachuje.

## Mapa souborů

| Soubor | Odpovědnost |
|--------|-------------|
| `app/Core/Modules/Manifest.php` | + `settingsPermission` |
| `app/Core/Modules/ManifestValidator.php` | + pravidlo a křížová kontrola schéma ↔ právo |
| `app/Core/Settings/SettingsSchema.php` | **nový** — parsuje oba tvary schématu |
| `app/Core/Settings/SettingsField.php` | **nový** — jedno pole (klíč, pravidla, popisek, typ, default, options) |
| `app/Core/Settings/SettingsService.php` | `schemaFor()` vrací objekt, `all()` slévá defaulty, `setMany()` |
| `app/Http/Controllers/Tenant/ModuleSettingsController.php` | **nový** — seznam + formulář + zápis |
| `app/Http/Requests/Tenant/UpdateModuleSettingsRequest.php` | **nový** — pravidla ze schématu |
| `resources/js/Pages/Tenant/ModuleSettings.vue` | **nový** — generický formulář |
| `resources/js/Pages/Tenant/ModuleSettingsIndex.vue` | **nový** — rozcestník |
| `routes/tenant.php` | + tři routy |
| `Modules/Docs/settings.json` | přepis na objektový tvar |
| `app/Core/Theme/VariantDisplay.php` | čte `SettingsService` |
| `Modules/Products/settings.json` | **nový** — `variant_display` |
| `Modules/Checkout/settings.json` | **nový** — `min_order_total`, `guest_checkout` |
| `Modules/Orders/settings.json` | **nový** — `number_prefix` |
| `app/Core/Modules/PlanModuleReconciler.php` | **nový** — dopad + zápis + rekonciliace |
| `app/Http/Controllers/Platform/PlanController.php` | **nový** — superadmin obrazovka |
| `resources/js/Pages/Platform/Plans/*.vue` | **nový** |
| `routes/platform.php` | + čtyři routy |

---

### Task 1: Manifest zná `settings_permission`

**Soubory:**
- Upravit: `app/Core/Modules/Manifest.php`, `app/Core/Modules/ManifestValidator.php`
- Test: `tests/Feature/Core/ManifestValidatorTest.php` (existuje-li; jinak vytvořit)

**Rozhraní:**
- Produkuje: `Manifest::$settingsPermission` (`?string`), klíč `settings_permission` v `toArray()`.

- [x] **Krok 1: Napiš padající test**

```php
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
```

- [x] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=ManifestValidatorTest`
Očekávej: FAIL — `settings_permission` se ignoruje, výjimka nepřijde.

- [x] **Krok 3: Doplň vlastnost do `Manifest`**

V konstruktoru za `settingsSchema`:

```php
public ?string $settingsPermission = null,
```

V `fromArray()`: `settingsPermission: $data['settings_permission'] ?? null,`
V `toArray()`: `'settings_permission' => $this->settingsPermission,`

- [x] **Krok 4: Doplň pravidlo a křížovou kontrolu do `ManifestValidator`**

Do pole pravidel:

```php
'settings_permission' => ['sometimes', 'nullable', 'string'],
```

Do `after()` closure, vedle `checkVersion`/`checkRequires`:

```php
$this->checkSettingsPermission($validator, $data);
```

A metoda:

```php
/**
 * A settings screen without a permission would be a surface nobody guards;
 * a permission the module does not declare would be one TenantPermissions
 * never grants, locking the screen even for the owner.
 *
 * @param  array<string, mixed>  $data
 */
private function checkSettingsPermission(Validator $validator, array $data): void
{
    if (($data['settings_schema'] ?? null) === null) {
        return;
    }

    $permission = $data['settings_permission'] ?? null;

    if ($permission === null) {
        $validator->errors()->add('settings_permission', 'is required when settings_schema is set.');

        return;
    }

    if (! in_array($permission, $data['permissions'] ?? [], true)) {
        $validator->errors()->add('settings_permission', 'must be one of the permissions this module declares.');
    }
}
```

- [x] **Krok 5: Doplň `settings_permission` do `Modules/Docs/module.json`**

`"settings_permission": "docs.manage",` hned za `settings_schema` — jinak `modules:sync` spadne na jediném modulu, který dnes schéma má.

- [x] **Krok 6: Spusť testy**

Spusť: `php artisan test --filter=ManifestValidatorTest` a `php artisan test tests/Feature/Core`
Očekávej: PASS.

- [x] **Krok 7: Commit**

```bash
./vendor/bin/pint app/Core/Modules/Manifest.php app/Core/Modules/ManifestValidator.php
git add app/Core/Modules tests/Feature/Core Modules/Docs/module.json
git commit -m "feat(modules): let a manifest name the permission guarding its settings"
```

---

### Task 2: `SettingsSchema` a `SettingsField`

**Soubory:**
- Vytvořit: `app/Core/Settings/SettingsSchema.php`, `app/Core/Settings/SettingsField.php`
- Test: `tests/Unit/Core/SettingsSchemaTest.php`

**Rozhraní:**
- Konzumuje: nic.
- Produkuje: `SettingsSchema::fromArray(array $raw): self`, `->fields(): list<SettingsField>`, `->field(string $key): ?SettingsField`, `->has(string $key): bool`, `->rules(): array<string, string>`, `->defaults(): array<string, mixed>`.
  `SettingsField` = readonly `{string $key, string $rules, string $label, string $type, mixed $default, ?string $help, array<string,string> $options}`.

- [x] **Krok 1: Napiš padající test**

```php
public function test_a_plain_string_is_read_as_rules_only(): void
{
    $schema = SettingsSchema::fromArray(['due_days' => 'integer|min:0']);

    $field = $schema->field('due_days');

    $this->assertSame('integer|min:0', $field->rules);
    $this->assertSame('due_days', $field->label);
    $this->assertSame('number', $field->type);
    $this->assertNull($field->default);
}

public function test_an_object_carries_label_type_and_default(): void
{
    $schema = SettingsSchema::fromArray([
        'auto_issue_on' => [
            'rules' => 'in:paid,shipped,manual',
            'label' => 'Faktura se vystaví',
            'type' => 'select',
            'default' => 'paid',
            'options' => ['paid' => 'Při zaplacení'],
        ],
    ]);

    $field = $schema->field('auto_issue_on');

    $this->assertSame('Faktura se vystaví', $field->label);
    $this->assertSame('select', $field->type);
    $this->assertSame('paid', $field->default);
    $this->assertSame(['paid' => 'Při zaplacení'], $field->options);
}

public function test_the_type_is_derived_from_the_rules_when_absent(): void
{
    $schema = SettingsSchema::fromArray([
        'email_invoice' => 'boolean',
        'mode' => 'in:a,b',
        'note' => 'nullable|string|max:2000',
    ]);

    $this->assertSame('boolean', $schema->field('email_invoice')->type);
    $this->assertSame('select', $schema->field('mode')->type);
    $this->assertSame('textarea', $schema->field('note')->type);
}

public function test_rules_and_defaults_come_out_keyed_by_field(): void
{
    $schema = SettingsSchema::fromArray([
        'a' => ['rules' => 'integer', 'default' => 3],
        'b' => 'boolean',
    ]);

    $this->assertSame(['a' => 'integer', 'b' => 'boolean'], $schema->rules());
    $this->assertSame(['a' => 3], $schema->defaults());
}

public function test_an_unknown_key_has_no_field(): void
{
    $schema = SettingsSchema::fromArray(['a' => 'integer']);

    $this->assertFalse($schema->has('b'));
    $this->assertNull($schema->field('b'));
}
```

- [x] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=SettingsSchemaTest`
Očekávej: FAIL — `Class "App\Core\Settings\SettingsSchema" not found`.

- [x] **Krok 3: Napiš `SettingsField`**

```php
<?php

namespace App\Core\Settings;

/**
 * One configurable value a module exposes: what may be stored in it
 * (`rules`, the authority) and how to draw it (`type`, presentation only).
 */
final readonly class SettingsField
{
    /**
     * @param  array<string, string>  $options  value => label, select only
     */
    public function __construct(
        public string $key,
        public string $rules,
        public string $label,
        public string $type,
        public mixed $default = null,
        public ?string $help = null,
        public array $options = [],
    ) {}
}
```

- [x] **Krok 4: Napiš `SettingsSchema`**

```php
<?php

namespace App\Core\Settings;

/**
 * A module's settings.json, parsed.
 *
 * Two shapes are accepted. A plain string is the original wave-1.5 form and
 * means rules with no presentation metadata; an object carries the label,
 * field type, default and select options the admin form needs. Keeping the
 * old shape valid is what allows a module to be migrated on its own schedule
 * rather than all thirteen at once.
 */
final readonly class SettingsSchema
{
    /**
     * @param  array<string, SettingsField>  $fields
     */
    private function __construct(private array $fields) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $fields = [];

        foreach ($raw as $key => $definition) {
            $definition = is_array($definition) ? $definition : ['rules' => (string) $definition];
            $rules = (string) ($definition['rules'] ?? '');

            $fields[$key] = new SettingsField(
                key: $key,
                rules: $rules,
                label: (string) ($definition['label'] ?? $key),
                type: (string) ($definition['type'] ?? self::deriveType($rules)),
                default: $definition['default'] ?? null,
                help: $definition['help'] ?? null,
                options: $definition['options'] ?? [],
            );
        }

        return new self($fields);
    }

    /**
     * Best-effort presentation type for a legacy schema that only has rules.
     * Never used to decide what may be stored — that stays with the rules.
     */
    private static function deriveType(string $rules): string
    {
        $parts = explode('|', $rules);

        foreach ($parts as $part) {
            if ($part === 'boolean') {
                return 'boolean';
            }

            if (str_starts_with($part, 'in:')) {
                return 'select';
            }

            if ($part === 'integer' || $part === 'numeric') {
                return 'number';
            }
        }

        // A long free-text limit reads as prose, not as a one-line value.
        foreach ($parts as $part) {
            if (str_starts_with($part, 'max:') && (int) substr($part, 4) > 255) {
                return 'textarea';
            }
        }

        return 'text';
    }

    /**
     * @return list<SettingsField>
     */
    public function fields(): array
    {
        return array_values($this->fields);
    }

    public function field(string $key): ?SettingsField
    {
        return $this->fields[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return array_map(static fn (SettingsField $field): string => $field->rules, $this->fields);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return array_filter(
            array_map(static fn (SettingsField $field): mixed => $field->default, $this->fields),
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
```

- [x] **Krok 5: Spusť testy**

Spusť: `php artisan test --filter=SettingsSchemaTest`
Očekávej: PASS (5 testů).

- [x] **Krok 6: Commit**

```bash
./vendor/bin/pint app/Core/Settings tests/Unit/Core/SettingsSchemaTest.php
git add app/Core/Settings tests/Unit/Core/SettingsSchemaTest.php
git commit -m "feat(settings): parse a module settings schema into typed fields"
```

---

### Task 3: `SettingsService` — defaulty a hromadný zápis

**Soubory:**
- Upravit: `app/Core/Settings/SettingsService.php`
- Test: `tests/Feature/Core/SettingsServiceTest.php` (existuje-li; jinak vytvořit)

**Rozhraní:**
- Konzumuje: `SettingsSchema` z Tasku 2.
- Produkuje: `schemaFor(string $module): ?SettingsSchema`, `setMany(string $module, array<string, mixed> $values): void`; `all()` nově vrací uložené hodnoty **slité s defaulty**.

- [x] **Krok 1: Napiš padající test**

Test si vyrobí dočasný modul s vlastním schématem přes existující `ActivatesModules` a fixture soubor; pokud fixture cesta v projektu ještě není, použij modul `docs` a jeho reálné schéma.

```php
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
```

- [x] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=SettingsServiceTest`
Očekávej: FAIL — `setMany()` neexistuje, `get()` vrací `null` místo defaultu.

- [x] **Krok 3: Přepiš `schemaFor()` na `SettingsSchema`**

```php
public function schemaFor(string $module): ?SettingsSchema
{
    $model = $this->registry->all()->get($module);

    if (! $model) {
        return null;
    }

    $manifest = Manifest::fromArray($model->manifest);

    if ($manifest->settingsSchema === null) {
        return null;
    }

    $path = base_path('Modules/'.str($module)->studly().'/'.$manifest->settingsSchema);

    if (! is_file($path)) {
        return null;
    }

    return SettingsSchema::fromArray(json_decode((string) file_get_contents($path), true) ?? []);
}
```

- [x] **Krok 4: Slij defaulty v `all()` a přepiš `validate()`**

```php
public function all(string $module): array
{
    $tenantId = $this->requireTenant();

    $stored = Cache::remember(
        "settings:{$tenantId}:{$module}",
        now()->addHour(),
        fn () => DB::table('settings')
            ->where('tenant_id', $tenantId)
            ->where('module', $module)
            ->pluck('value', 'key')
            ->map(fn ($json) => json_decode($json, true))
            ->all()
    );

    // The schema is the single source of the default. Leaving it to each
    // call site (get('docs', 'due_days', config(...))) means schema and code
    // can disagree about what an untouched shop is running on.
    return [...$this->schemaFor($module)?->defaults() ?? [], ...$stored];
}
```

`validate()` nahraď kontrolou proti `SettingsSchema`:

```php
private function validate(string $module, string $key, mixed $value): void
{
    $schema = $this->schemaFor($module);

    if ($schema === null) {
        Log::warning("Module [{$module}] has no settings schema; [{$key}] stored unvalidated.");

        return;
    }

    if (! $schema->has($key)) {
        throw InvalidSetting::unknownKey($module, $key);
    }

    $validator = Validator::make([$key => $value], [$key => $schema->field($key)->rules]);

    if ($validator->fails()) {
        throw InvalidSetting::failedValidation($module, $key, $validator->errors()->first($key));
    }
}
```

- [x] **Krok 5: Přidej `setMany()`**

```php
/**
 * Validate the whole set, then write it in one transaction.
 *
 * Per-key writing would leave a form half-applied when the sixth value is
 * rejected — the shop would then be running a mix of the old and the new
 * configuration with nothing on screen saying so.
 *
 * @param  array<string, mixed>  $values
 */
public function setMany(string $module, array $values): void
{
    $tenantId = $this->requireTenant();

    foreach ($values as $key => $value) {
        $this->validate($module, $key, $value);
    }

    DB::transaction(function () use ($tenantId, $module, $values): void {
        foreach ($values as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['tenant_id' => $tenantId, 'module' => $module, 'key' => $key],
                ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
            );
        }
    });

    $this->forget($module);
}
```

- [x] **Krok 6: Přepiš `Modules/Docs/settings.json` na objektový tvar**

```json
{
    "auto_issue_on": {
        "rules": "in:paid,shipped,manual",
        "label": "Faktura se vystaví",
        "type": "select",
        "default": "paid",
        "options": {
            "paid": "Při zaplacení objednávky",
            "shipped": "Při odeslání objednávky",
            "manual": "Jen ručně"
        },
        "help": "Automatické vystavení lze kdykoli doplnit ručním vystavením v detailu objednávky."
    },
    "email_invoice": {
        "rules": "boolean",
        "label": "Poslat fakturu zákazníkovi e-mailem",
        "type": "boolean",
        "default": true
    },
    "due_days": {
        "rules": "integer|min:0|max:90",
        "label": "Splatnost faktury (dny)",
        "type": "number",
        "default": 14,
        "help": "Počet dnů od vystavení do data splatnosti."
    },
    "invoice_footer": {
        "rules": "nullable|string|max:2000",
        "label": "Patička faktury",
        "type": "textarea",
        "default": "",
        "help": "Text pod položkami — například zápis v obchodním rejstříku."
    },
    "number_prefix": {
        "rules": "nullable|string|max:20",
        "label": "Prefix čísla faktury",
        "type": "text",
        "default": ""
    },
    "credit_note_prefix": {
        "rules": "nullable|string|max:20",
        "label": "Prefix čísla dobropisu",
        "type": "text",
        "default": ""
    },
    "proforma_prefix": {
        "rules": "nullable|string|max:20",
        "label": "Prefix čísla proformy",
        "type": "text",
        "default": ""
    }
}
```

- [x] **Krok 7: Spusť testy**

Spusť: `php artisan test tests/Feature/Core tests/Unit/Core tests/Feature/Modules/Docs`
Očekávej: PASS. `docs` testy hlídají, že defaulty ze schématu odpovídají tomu, co dosud předával kód (`due_days` = `config('documents.default_due_days')`); pokud se rozejdou, sedni si na config hodnotu, ne naopak.

- [x] **Krok 8: Commit**

```bash
./vendor/bin/pint app/Core/Settings
git add app/Core/Settings Modules/Docs/settings.json tests
git commit -m "feat(settings): merge schema defaults and add an all-or-nothing write"
```

---

### Task 4: Obrazovka nastavení modulu

**Soubory:**
- Vytvořit: `app/Http/Controllers/Tenant/ModuleSettingsController.php`, `app/Http/Requests/Tenant/UpdateModuleSettingsRequest.php`, `resources/js/Pages/Tenant/ModuleSettingsIndex.vue`, `resources/js/Pages/Tenant/ModuleSettings.vue`
- Upravit: `routes/tenant.php`
- Test: `tests/Feature/Tenant/ModuleSettingsTest.php`

**Rozhraní:**
- Konzumuje: `SettingsService::schemaFor()`, `all()`, `setMany()`; `Manifest::$settingsPermission`; `ModuleRegistry::isEnabled()`.
- Produkuje: routy `admin.settings.modules.index|edit|update`.

- [x] **Krok 1: Napiš padající test**

```php
public function test_the_owner_sees_the_form_built_from_the_schema(): void
{
    $this->actingAs($this->owner)
        ->get($this->url('/admin/nastaveni/moduly/docs'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/ModuleSettings')
            ->where('module.key', 'docs')
            ->has('fields', 7)
            ->where('values.due_days', 14));
}

public function test_saving_a_value_takes_effect(): void
{
    $this->actingAs($this->owner)
        ->patch($this->url('/admin/nastaveni/moduly/docs'), ['values' => ['due_days' => 30]])
        ->assertRedirect();

    $this->context->runAs($this->tenant, function (): void {
        $this->assertSame(30, app(SettingsService::class)->get('docs', 'due_days'));
    });
}

public function test_an_invalid_value_is_rejected_and_nothing_is_written(): void
{
    $this->actingAs($this->owner)
        ->patch($this->url('/admin/nastaveni/moduly/docs'), ['values' => ['due_days' => 900]])
        ->assertSessionHasErrors('values.due_days');

    $this->context->runAs($this->tenant, function (): void {
        $this->assertSame(14, app(SettingsService::class)->get('docs', 'due_days'));
    });
}

public function test_an_unknown_key_is_rejected(): void
{
    $this->actingAs($this->owner)
        ->patch($this->url('/admin/nastaveni/moduly/docs'), ['values' => ['nonsense' => 1]])
        ->assertSessionHasErrors();
}

public function test_a_module_the_shop_does_not_run_is_not_found(): void
{
    // `docs` deliberately not activated for this tenant in this test.
    $this->actingAs($this->owner)
        ->get($this->url('/admin/nastaveni/moduly/docs'))
        ->assertNotFound();
}

public function test_a_member_without_the_permission_is_forbidden(): void
{
    $this->actingAs($this->staffWithoutPermission)
        ->get($this->url('/admin/nastaveni/moduly/docs'))
        ->assertForbidden();
}

public function test_the_index_lists_only_modules_with_a_schema(): void
{
    $this->actingAs($this->owner)
        ->get($this->url('/admin/nastaveni/moduly'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/ModuleSettingsIndex')
            ->where('modules.0.key', 'docs'));
}
```

- [x] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=ModuleSettingsTest`
Očekávej: FAIL — routa neexistuje (404 na všech).

- [x] **Krok 3: Přidej routy**

Do `routes/tenant.php` za blok `/admin/nastaveni/vzhled`:

```php
Route::get('/admin/nastaveni/moduly', [ModuleSettingsController::class, 'index'])->name('admin.settings.modules.index');
Route::get('/admin/nastaveni/moduly/{module}', [ModuleSettingsController::class, 'edit'])->name('admin.settings.modules.edit');
Route::patch('/admin/nastaveni/moduly/{module}', [ModuleSettingsController::class, 'update'])->name('admin.settings.modules.update');
```

- [x] **Krok 4: Napiš controller**

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Core\Modules\Manifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateModuleSettingsRequest;
use App\Core\Services\AuditLog;
use App\Models\Module;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one screen every module's settings are edited on (wave 2.10).
 *
 * The form is generated from the schema the module ships, so a new module
 * needs no screen of its own. Two gates, in this order: a module the shop
 * does not run is a 404 (a 403 would confirm which modules it is missing),
 * and the permission is the one the manifest names — there is no uniform
 * `<key>.manage` across modules to fall back on.
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
            ->filter(fn (Module $module) => $this->mayManage($module->key))
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
        $schema = $this->authorizeModule($module);

        return Inertia::render('Tenant/ModuleSettings', [
            'module' => [
                'key' => $module,
                'name' => Manifest::fromArray($this->registry->all()->get($module)->manifest)->titleFor(),
            ],
            'fields' => array_map(fn ($field) => [
                'key' => $field->key,
                'label' => $field->label,
                'type' => $field->type,
                'help' => $field->help,
                'options' => $field->options,
            ], $schema->fields()),
            'values' => $this->settings->all($module),
        ]);
    }

    public function update(UpdateModuleSettingsRequest $request, string $module): RedirectResponse
    {
        $this->authorizeModule($module);

        $this->settings->setMany($module, $request->validated('values'));

        $this->audit->log('module.settings_updated', null, [
            'module' => $module,
            'keys' => array_keys($request->validated('values')),
        ]);

        return back()->with('success', 'Nastavení uloženo.');
    }

    /**
     * 404 for a module this shop does not run, 403 for a member without the
     * right. Returns the schema so callers do not resolve it twice.
     */
    private function authorizeModule(string $module): \App\Core\Settings\SettingsSchema
    {
        $tenant = $this->context->current();

        if (! $this->registry->isEnabled($tenant, $module)) {
            throw new NotFoundHttpException;
        }

        $schema = $this->settings->schemaFor($module);

        if ($schema === null) {
            throw new NotFoundHttpException;
        }

        $permission = Manifest::fromArray($this->registry->all()->get($module)->manifest)->settingsPermission;

        abort_unless($permission !== null && \Illuminate\Support\Facades\Gate::allows($permission), 403);

        return $schema;
    }

    private function mayManage(string $key): bool
    {
        $permission = Manifest::fromArray($this->registry->all()->get($key)->manifest)->settingsPermission;

        return $permission !== null && \Illuminate\Support\Facades\Gate::allows($permission);
    }
}
```

- [x] **Krok 5: Napiš FormRequest**

```php
<?php

namespace App\Http\Requests\Tenant;

use App\Core\Settings\SettingsService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rules come from the module's own schema, so the form can never accept a
 * key or a value the module did not declare. `authorize()` stays true: which
 * module and which permission is decided by the controller, which is also
 * the only place that knows whether the shop runs the module at all.
 */
class UpdateModuleSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $schema = app(SettingsService::class)->schemaFor((string) $this->route('module'));

        if ($schema === null) {
            return ['values' => ['array', 'size:0']];
        }

        $rules = ['values' => ['required', 'array']];

        foreach ($schema->rules() as $key => $fieldRules) {
            $rules['values.'.$key] = $fieldRules;
        }

        // Anything outside the schema is a hard failure, not a silently
        // dropped field: a typo'd key would otherwise look saved.
        $rules['values'][] = function (string $attribute, mixed $value, callable $fail) use ($schema): void {
            foreach (array_keys((array) $value) as $key) {
                if (! $schema->has($key)) {
                    $fail("Neznámé nastavení [{$key}].");
                }
            }
        };

        return $rules;
    }
}
```

- [x] **Krok 6: Napiš Vue stránky**

`resources/js/Pages/Tenant/ModuleSettings.vue` — `<script setup lang="ts">`, `useForm({ values: { ...props.values } })`, pole podle `field.type`:

```vue
<template>
  <AdminLayout>
    <h1 class="text-2xl font-semibold">Nastavení — {{ module.name }}</h1>

    <form class="mt-6 space-y-6" @submit.prevent="form.patch(route('admin.settings.modules.update', module.key))">
      <div v-for="field in fields" :key="field.key">
        <label :for="field.key" class="block font-medium">{{ field.label }}</label>

        <input v-if="field.type === 'boolean'" :id="field.key" v-model="form.values[field.key]" type="checkbox" />
        <select v-else-if="field.type === 'select'" :id="field.key" v-model="form.values[field.key]">
          <option v-for="(label, value) in field.options" :key="value" :value="value">{{ label }}</option>
        </select>
        <textarea v-else-if="field.type === 'textarea'" :id="field.key" v-model="form.values[field.key]" rows="4" />
        <input v-else :id="field.key" v-model="form.values[field.key]" :type="field.type === 'number' ? 'number' : 'text'" />

        <p v-if="field.help" class="mt-1 text-sm text-slate-600">{{ field.help }}</p>
        <p v-if="form.errors[`values.${field.key}`]" class="mt-1 text-sm text-red-700">
          {{ form.errors[`values.${field.key}`] }}
        </p>
      </div>

      <button type="submit" class="btn btn-primary" :disabled="form.processing">Uložit</button>
    </form>
  </AdminLayout>
</template>
```

`ModuleSettingsIndex.vue` je prostý seznam `<Link :href="route('admin.settings.modules.edit', m.key)">{{ m.name }}</Link>`.

- [x] **Krok 7: Spusť testy a build**

Spusť: `php artisan test --filter=ModuleSettingsTest` a `npm run build`
Očekávej: PASS a čistý build.

- [x] **Krok 8: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/Tenant/ModuleSettingsController.php app/Http/Requests/Tenant/UpdateModuleSettingsRequest.php
git add app/Http routes/tenant.php resources/js/Pages/Tenant tests/Feature/Tenant/ModuleSettingsTest.php
git commit -m "feat(settings): add the generic module settings screen"
```

---

### Task 5: Přesun `variant_display` do nastavení modulu `products`

**Soubory:**
- Vytvořit: `Modules/Products/settings.json`, `database/migrations/2026_07_29_100000_move_variant_display_to_settings.php`
- Upravit: `Modules/Products/module.json`, `app/Core/Theme/VariantDisplay.php`, `app/Http/Controllers/Tenant/AppearanceController.php`, `app/Http/Requests/Tenant/UpdateAppearanceRequest.php`, `resources/js/Pages/Tenant/Appearance.vue`
- Test: `tests/Feature/Theme/VariantDisplayTest.php` (existuje-li), `tests/Feature/Tenant/ModuleSettingsTest.php`

**Rozhraní:**
- Konzumuje: `SettingsService` z Tasku 3.
- Produkuje: `VariantDisplay::forCurrentTenant()` beze změny podpisu, nově čte `settings('products','variant_display')`.

- [x] **Krok 1: Napiš padající test**

```php
public function test_the_variant_display_comes_from_the_products_module_settings(): void
{
    $this->context->runAs($this->tenant, function (): void {
        app(SettingsService::class)->setMany('products', ['variant_display' => 'select']);

        $this->assertSame('select', app(VariantDisplay::class)->forCurrentTenant());
    });
}

public function test_an_unset_display_falls_back_to_radio(): void
{
    $this->context->runAs($this->tenant, function (): void {
        $this->assertSame('radio', app(VariantDisplay::class)->forCurrentTenant());
    });
}

public function test_the_appearance_screen_no_longer_carries_the_display(): void
{
    $this->actingAs($this->owner)
        ->get($this->url('/admin/nastaveni/vzhled'))
        ->assertInertia(fn ($page) => $page->missing('appearance.variant_display'));
}
```

- [x] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=VariantDisplay`
Očekávej: FAIL — hodnota se čte z `tenant_theme`, ne ze settings.

- [x] **Krok 3: Přidej schéma modulu `products`**

`Modules/Products/settings.json`:

```json
{
    "variant_display": {
        "rules": "in:radio,select",
        "label": "Výběr varianty na detailu produktu",
        "type": "select",
        "default": "radio",
        "options": {
            "radio": "Přepínače",
            "select": "Rozbalovací seznam"
        },
        "help": "Výchozí pro celý e-shop. Jednotlivý produkt může mít vlastní nastavení."
    }
}
```

V `Modules/Products/module.json` doplň `"settings_schema": "settings.json"` a `"settings_permission": "products.edit"`.

- [x] **Krok 4: Přepiš `VariantDisplay`**

```php
public function __construct(
    private readonly TenantContext $context,
    private readonly SettingsService $settings,
) {}

public function forCurrentTenant(): string
{
    if ($this->context->current() === null) {
        return self::DEFAULT;
    }

    return self::sanitize($this->settings->get('products', 'variant_display'));
}
```

`sanitize()` beze změny — je to poslední pojistka proti hodnotě, na kterou by Blade nenašel widget.

- [x] **Krok 5: Napiš migraci**

```php
public function up(): void
{
    // Carry the choice over before the column goes: a shop that picked
    // dropdowns must not silently flip back to radios on deploy.
    DB::table('tenant_theme')
        ->whereNotNull('variant_display')
        ->orderBy('tenant_id')
        ->each(function (object $row): void {
            DB::table('settings')->updateOrInsert(
                ['tenant_id' => $row->tenant_id, 'module' => 'products', 'key' => 'variant_display'],
                ['value' => json_encode($row->variant_display), 'created_at' => now(), 'updated_at' => now()],
            );
        });

    Schema::table('tenant_theme', function (Blueprint $table): void {
        $table->dropColumn('variant_display');
    });
}

public function down(): void
{
    Schema::table('tenant_theme', function (Blueprint $table): void {
        $table->string('variant_display', 16)->nullable();
    });
}
```

- [x] **Krok 6: Vyndej pole ze vzhledu**

`AppearanceController::edit()` — smaž `'variant_display' => …` z propů; `update()` — smaž z `$data`; `UpdateAppearanceRequest` — smaž pravidlo i hlášku; `Appearance.vue` — smaž typ, `useForm` klíč a celý blok obou radio inputů.

- [x] **Krok 7: Spusť testy a migraci**

Spusť: `php artisan migrate`, `php artisan test tests/Feature/Theme tests/Feature/Tenant tests/Feature/Modules/Products`
Očekávej: PASS.

- [x] **Krok 8: Commit**

```bash
./vendor/bin/pint app/Core/Theme app/Http database/migrations
git add app Modules/Products database/migrations resources/js/Pages/Tenant/Appearance.vue tests
git commit -m "refactor(products): move the variant display to module settings"
```

---

### Task 6: `checkout` — minimum objednávky a nákup bez registrace

**Soubory:**
- Vytvořit: `Modules/Checkout/settings.json`
- Upravit: `Modules/Checkout/module.json`, `Modules/Checkout/Http/Controllers/CheckoutController.php`, `Modules/Checkout/Resources/views/cart/show.blade.php`, `Modules/Checkout/Resources/views/checkout/details.blade.php`
- Test: `tests/Feature/Modules/Checkout/CheckoutSettingsTest.php`

**Rozhraní:**
- Konzumuje: `SettingsService`, `PricedCart::$payableTotal`/`$itemsTotal`.
- Produkuje: nic pro další úkoly.

- [x] **Krok 1: Napiš padající test**

```php
public function test_a_cart_below_the_minimum_cannot_be_ordered(): void
{
    $this->context->runAs($this->tenant, fn () => app(SettingsService::class)
        ->setMany('checkout', ['min_order_total' => 100_000]));

    $this->addToCart($this->makeProduct(['price' => 50_000]));
    $token = $this->cartToken();

    $cart = $this->withCookie('cart_token', $token)->get($this->url('/kosik'));
    $cart->assertSee('Minimální hodnota objednávky');

    $details = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/udaje'));
    $details->assertRedirect($this->url('/kosik'));
}

public function test_the_minimum_is_measured_after_discount_and_without_delivery(): void
{
    // 1 000,00 items, 99,00 delivery, minimum 1 050,00 → still refused.
    $this->context->runAs($this->tenant, fn () => app(SettingsService::class)
        ->setMany('checkout', ['min_order_total' => 105_000]));

    $this->addToCart($this->makeProduct(['price' => 100_000]));

    $this->withCookie('cart_token', $this->cartToken())
        ->get($this->url('/pokladna/udaje'))
        ->assertRedirect($this->url('/kosik'));
}

public function test_a_guest_is_sent_to_login_when_guest_checkout_is_off(): void
{
    $this->context->runAs($this->tenant, fn () => app(SettingsService::class)
        ->setMany('checkout', ['guest_checkout' => false]));

    $this->addToCart($this->makeProduct());

    $this->withCookie('cart_token', $this->cartToken())
        ->get($this->url('/pokladna/udaje'))
        ->assertRedirect($this->url('/prihlaseni'));
}

public function test_a_signed_in_customer_passes_with_guest_checkout_off(): void
{
    $this->context->runAs($this->tenant, fn () => app(SettingsService::class)
        ->setMany('checkout', ['guest_checkout' => false]));

    $this->addToCart($this->makeProduct());

    $this->actingAsCustomer($this->customer)
        ->withCookie('cart_token', $this->cartToken())
        ->get($this->url('/pokladna/udaje'))
        ->assertOk();
}

public function test_the_minimum_also_refuses_a_direct_post_to_place(): void
{
    // The button is hidden, so this is the path an attacker or a stale tab takes.
    $this->context->runAs($this->tenant, fn () => app(SettingsService::class)
        ->setMany('checkout', ['min_order_total' => 100_000]));

    $this->addToCart($this->makeProduct(['price' => 50_000]));

    $this->withCookie('cart_token', $this->cartToken())
        ->post($this->url('/pokladna/udaje'), $this->validDetailsPayload())
        ->assertRedirect($this->url('/kosik'));

    $this->assertSame(0, $this->context->runAs($this->tenant, fn () => Order::query()->count()));
}
```

- [x] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=CheckoutSettingsTest`
Očekávej: FAIL — nastavení neexistuje, pokladna pustí dál.

- [x] **Krok 3: Přidej schéma**

`Modules/Checkout/settings.json`:

```json
{
    "min_order_total": {
        "rules": "integer|min:0|max:100000000",
        "label": "Minimální hodnota objednávky (v haléřích)",
        "type": "number",
        "default": 0,
        "help": "Počítá se ze zboží po slevě, bez dopravy a platby. 0 = bez omezení. 100000 = 1 000 Kč."
    },
    "guest_checkout": {
        "rules": "boolean",
        "label": "Povolit nákup bez registrace",
        "type": "boolean",
        "default": true,
        "help": "Při vypnutí musí být zákazník před dokončením objednávky přihlášený."
    }
}
```

`Modules/Checkout/module.json`: `"permissions": ["checkout.manage"]`, `"settings_schema": "settings.json"`, `"settings_permission": "checkout.manage"`.

- [x] **Krok 4: Vynuť pravidla v `CheckoutController`**

Do `details()` hned za kontrolu prázdného košíku a do `place()` před založení objednávky:

```php
// Both gates run on the server and before anything is written. The cart
// page states the reason; sending the shopper there is what makes the
// refusal readable without JavaScript.
if ($blocked = $this->refuseBelowMinimum($priced)) {
    return CartCookie::attach($blocked, $cart, $request);
}

if ($blocked = $this->refuseGuest($request)) {
    return CartCookie::attach($blocked, $cart, $request);
}
```

Pomocné metody:

```php
private function minimumOrderTotal(): Money
{
    return Money::fromMinor((int) $this->settings->get('checkout', 'min_order_total', 0));
}

private function refuseBelowMinimum(PricedCart $priced): ?RedirectResponse
{
    $minimum = $this->minimumOrderTotal();

    if ($minimum->isZero()) {
        return null;
    }

    // Measured on the goods after discount: adding delivery would let an
    // expensive carrier carry the shopper over the shop's own floor.
    $payable = $priced->payableTotal ?? $priced->itemsTotal;

    if ($payable->minorUnits() >= $minimum->minorUnits()) {
        return null;
    }

    return redirect()->route('storefront.checkout.show')
        ->with('status', 'Minimální hodnota objednávky je '.$minimum->format().'.');
}

private function refuseGuest(Request $request): ?RedirectResponse
{
    if ((bool) $this->settings->get('checkout', 'guest_checkout', true)) {
        return null;
    }

    if ($request->user('customer') !== null) {
        return null;
    }

    return redirect()->guest(route('storefront.customers.login'));
}
```

Do košíkové Blade šablony přidej hlášku, když je `payableTotal` pod minimem, a schovej tlačítko „Pokračovat k pokladně".

- [x] **Krok 5: Spusť testy**

Spusť: `php artisan test tests/Feature/Modules/Checkout`
Očekávej: PASS včetně stávajících 80 testů.

- [x] **Krok 6: Commit**

```bash
./vendor/bin/pint Modules/Checkout
git add Modules/Checkout tests/Feature/Modules/Checkout
git commit -m "feat(checkout): add a minimum order value and a guest checkout switch"
```

---

### Task 7: `orders.number_prefix`

**Soubory:**
- Vytvořit: `Modules/Orders/settings.json`
- Upravit: `Modules/Orders/module.json`, `Modules/Orders/Services/OrderPlacer.php:224`
- Test: `tests/Feature/Modules/Orders/OrderNumberPrefixTest.php`

**Rozhraní:**
- Konzumuje: `SettingsService`, `SequenceService::next('orders')`.

- [ ] **Krok 1: Napiš padající test**

```php
public function test_a_new_order_carries_the_configured_prefix(): void
{
    $this->context->runAs($this->tenant, fn () => app(SettingsService::class)
        ->setMany('orders', ['number_prefix' => 'OBJ']));

    $order = $this->placeOrder();

    $this->assertStringStartsWith('OBJ', $order->number);
}

public function test_existing_numbers_are_untouched_by_a_later_prefix_change(): void
{
    $first = $this->placeOrder();

    $this->context->runAs($this->tenant, fn () => app(SettingsService::class)
        ->setMany('orders', ['number_prefix' => 'OBJ']));

    $second = $this->placeOrder();

    $this->assertStringStartsNotWith('OBJ', $first->fresh()->number);
    $this->assertStringStartsWith('OBJ', $second->number);
}
```

- [ ] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=OrderNumberPrefixTest`
Očekávej: FAIL — prefix se ignoruje.

- [ ] **Krok 3: Přidej schéma**

`Modules/Orders/settings.json`:

```json
{
    "number_prefix": {
        "rules": "nullable|string|max:10",
        "label": "Prefix čísla objednávky",
        "type": "text",
        "default": "",
        "help": "Platí od další objednávky. Už vystavená čísla se nemění."
    }
}
```

`Modules/Orders/module.json`: `"settings_schema": "settings.json"`, `"settings_permission": "orders.edit"`.

- [ ] **Krok 4: Slož číslo v `OrderPlacer`**

```php
// 7. A gap-free order number for the current tenant. The prefix is a
// tenant setting rather than the sequence row's own, so changing it never
// rewrites numbers already handed out — the same split InvoiceIssuer uses.
$number = $this->settings->get('orders', 'number_prefix', '').$this->sequences->next('orders');
```

`SettingsService` přidej do konstruktoru `OrderPlacer`.

- [ ] **Krok 5: Spusť testy**

Spusť: `php artisan test tests/Feature/Modules/Orders tests/Feature/Modules/Checkout`
Očekávej: PASS.

- [ ] **Krok 6: Commit**

```bash
./vendor/bin/pint Modules/Orders
git add Modules/Orders tests/Feature/Modules/Orders
git commit -m "feat(orders): make the order number prefix a tenant setting"
```

---

### Task 8: `PlanModuleReconciler`

**Soubory:**
- Vytvořit: `app/Core/Modules/PlanModuleReconciler.php`
- Test: `tests/Feature/Platform/PlanModuleReconcilerTest.php`

**Rozhraní:**
- Konzumuje: `ModuleRegistry::available()|enabledFor()|activate()|deactivate()`, tabulka `plan_modules`.
- Produkuje: `impact(Plan $plan, array $keys): array{tenants: int, activate: array<string, list<string>>, deactivate: array<string, list<string>>}` (klíč vnějšího pole = tenant id) a `apply(Plan $plan, array $keys): void`.

- [ ] **Krok 1: Napiš padající test**

```php
public function test_the_impact_counts_what_would_change(): void
{
    // tenant runs base = [products, orders]; proposed = [products, feeds]
    $impact = app(PlanModuleReconciler::class)->impact($this->plan, ['products', 'feeds']);

    $this->assertSame(1, $impact['tenants']);
    $this->assertSame(['feeds'], $impact['activate'][$this->tenant->id]);
    $this->assertSame(['orders'], $impact['deactivate'][$this->tenant->id]);
}

public function test_applying_activates_and_deactivates_for_every_tenant_of_the_plan(): void
{
    app(PlanModuleReconciler::class)->apply($this->plan, ['products', 'feeds']);

    $registry = app(ModuleRegistry::class);
    $this->assertTrue($registry->isEnabled($this->tenant->fresh(), 'feeds'));
    $this->assertFalse($registry->isEnabled($this->tenant->fresh(), 'orders'));
}

public function test_a_globally_killed_module_is_skipped_rather_than_throwing(): void
{
    app(ModuleKillSwitch::class)->disable(Module::firstWhere('key', 'feeds'), 'incident');

    app(PlanModuleReconciler::class)->apply($this->plan, ['products', 'feeds']);

    $this->assertFalse(app(ModuleRegistry::class)->isEnabled($this->tenant->fresh(), 'feeds'));
    $this->assertDatabaseHas('plan_modules', ['plan_id' => $this->plan->id, 'module_key' => 'feeds']);
}

public function test_a_core_module_is_never_deactivated(): void
{
    app(PlanModuleReconciler::class)->apply($this->plan, []);

    $this->assertTrue(app(ModuleRegistry::class)->isEnabled($this->tenant->fresh(), 'storefront'));
}

public function test_every_touched_tenant_gets_its_own_audit_entry(): void
{
    app(PlanModuleReconciler::class)->apply($this->plan, ['products', 'feeds']);

    $this->assertDatabaseHas('audit_log_entries', [
        'tenant_id' => $this->tenant->id,
        'action' => 'module.activated',
    ]);
}
```

- [ ] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=PlanModuleReconcilerTest`
Očekávej: FAIL — třída neexistuje.

- [ ] **Krok 3: Napiš službu**

```php
<?php

namespace App\Core\Modules;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites which modules a plan grants and brings every shop on that plan
 * in line with it (wave 2.10).
 *
 * The reconciliation is the same shape as TenantPlanSwitcher's: computed
 * from the live enabled set rather than from a diff of the edit, so it is
 * idempotent and order-independent. Activation is intersected with
 * available() — a globally kill-switched module is skipped, not thrown on,
 * because a throw here would abort a change that touches many shops over
 * one module an incident already took out of service.
 */
class PlanModuleReconciler
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * @param  list<string>  $keys
     * @return array{tenants: int, activate: array<int, list<string>>, deactivate: array<int, list<string>>}
     */
    public function impact(Plan $plan, array $keys): array
    {
        $activate = [];
        $deactivate = [];
        $tenants = $plan->tenants()->get();

        foreach ($tenants as $tenant) {
            [$on, $off] = $this->diffFor($tenant, $keys);

            if ($on !== []) {
                $activate[$tenant->id] = $on;
            }

            if ($off !== []) {
                $deactivate[$tenant->id] = $off;
            }
        }

        return ['tenants' => $tenants->count(), 'activate' => $activate, 'deactivate' => $deactivate];
    }

    /**
     * @param  list<string>  $keys
     */
    public function apply(Plan $plan, array $keys): void
    {
        DB::transaction(function () use ($plan, $keys): void {
            $plan->modules()->sync($keys);
        });

        $plan->unsetRelation('modules');

        foreach ($plan->tenants()->get() as $tenant) {
            $tenant->unsetRelation('plan');

            [$on, $off] = $this->diffFor($tenant, $keys);

            foreach ($on as $key) {
                $this->registry->activate($tenant, $key);
            }

            foreach ($off as $key) {
                $this->registry->deactivate($tenant, $key);
            }
        }
    }

    /**
     * @param  list<string>  $keys
     * @return array{0: list<string>, 1: list<string>}
     */
    private function diffFor(Tenant $tenant, array $keys): array
    {
        $enabled = $this->registry->enabledFor($tenant)->keys()->all();
        $available = $this->registry->available()->keys()->all();

        // Only keys some plan can grant may be deactivated. Core modules are
        // never in plan_modules, which is what keeps them out of reach here.
        $planCatalog = DB::table('plan_modules')->distinct()->pluck('module_key')->all();

        return [
            array_values(array_intersect(array_diff($keys, $enabled), $available)),
            array_values(array_intersect($enabled, array_diff($planCatalog, $keys))),
        ];
    }
}
```

Pozor na pořadí v `apply()`: `sync()` musí proběhnout **před** rekonciliací, protože `ModuleRegistry::activate()` guarduje, že modul patří do tarifu tenanta.

- [ ] **Krok 4: Spusť testy**

Spusť: `php artisan test --filter=PlanModuleReconcilerTest`
Očekávej: PASS (5 testů).

- [ ] **Krok 5: Commit**

```bash
./vendor/bin/pint app/Core/Modules/PlanModuleReconciler.php
git add app/Core/Modules tests/Feature/Platform/PlanModuleReconcilerTest.php
git commit -m "feat(modules): reconcile every tenant of a plan when its modules change"
```

---

### Task 9: Superadmin obrazovka tarifů

**Soubory:**
- Vytvořit: `app/Http/Controllers/Platform/PlanController.php`, `resources/js/Pages/Platform/Plans/Index.vue`, `resources/js/Pages/Platform/Plans/Show.vue`
- Upravit: `routes/platform.php`
- Test: `tests/Feature/Platform/PlanManagementTest.php`

**Rozhraní:**
- Konzumuje: `PlanModuleReconciler` z Tasku 8.
- Produkuje: routy `platform.plans.index|show|impact|modules`.

- [ ] **Krok 1: Napiš padající test**

```php
public function test_the_plan_list_shows_shops_and_module_counts(): void
{
    $this->actingAsSuperadmin()
        ->get('http://droidshop/superadmin/tarify')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Platform/Plans/Index')->has('plans', 2));
}

public function test_the_impact_endpoint_answers_before_anything_is_written(): void
{
    $this->actingAsSuperadmin()
        ->get('http://droidshop/superadmin/tarify/'.$this->plan->id.'/dopad?keys[]=products')
        ->assertOk()
        ->assertJsonPath('tenants', 1);

    $this->assertDatabaseHas('plan_modules', ['plan_id' => $this->plan->id, 'module_key' => 'orders']);
}

public function test_removing_a_module_requires_a_reason(): void
{
    $this->actingAsSuperadmin()
        ->patch('http://droidshop/superadmin/tarify/'.$this->plan->id.'/moduly', ['keys' => ['products']])
        ->assertSessionHasErrors('reason');
}

public function test_saving_reconciles_the_tenants(): void
{
    $this->actingAsSuperadmin()
        ->patch('http://droidshop/superadmin/tarify/'.$this->plan->id.'/moduly', [
            'keys' => ['products', 'feeds'],
            'reason' => 'feeds moved into base',
        ])
        ->assertRedirect();

    $this->assertTrue(app(ModuleRegistry::class)->isEnabled($this->tenant->fresh(), 'feeds'));
}

public function test_a_tenant_admin_cannot_reach_the_screen(): void
{
    $this->actingAs($this->tenantOwner)
        ->get('http://droidshop/superadmin/tarify')
        ->assertRedirect();
}
```

- [ ] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=PlanManagementTest`
Očekávej: FAIL — routy neexistují.

- [ ] **Krok 3: Přidej routy**

Do skupiny `['auth:platform', 'platform.2fa']` v `routes/platform.php`:

```php
Route::get('/superadmin/tarify', [PlanController::class, 'index'])->name('platform.plans.index');
Route::get('/superadmin/tarify/{plan}', [PlanController::class, 'show'])->name('platform.plans.show');
Route::get('/superadmin/tarify/{plan}/dopad', [PlanController::class, 'impact'])->name('platform.plans.impact');
Route::patch('/superadmin/tarify/{plan}/moduly', [PlanController::class, 'updateModules'])->name('platform.plans.modules');
```

- [ ] **Krok 4: Napiš controller**

```php
public function updateModules(Request $request, Plan $plan, PlanModuleReconciler $reconciler): RedirectResponse
{
    $validated = $request->validate([
        'keys' => ['present', 'array'],
        'keys.*' => ['string'],
        // Same asymmetry as the module kill switch: granting needs no
        // justification, taking something away from live shops does.
        'reason' => ['required_if:removes_modules,true', 'nullable', 'string', 'max:500'],
    ]);

    $impact = $reconciler->impact($plan, $validated['keys']);

    if ($impact['deactivate'] !== [] && blank($validated['reason'] ?? null)) {
        return back()->withErrors(['reason' => 'Odebrání modulu z tarifu vyžaduje důvod.']);
    }

    $reconciler->apply($plan, $validated['keys']);

    $this->audit->log('plan.modules_changed', $plan, [
        'keys' => $validated['keys'],
        'reason' => $validated['reason'] ?? null,
        'tenants' => $impact['tenants'],
    ]);

    return back()->with('success', 'Tarif upraven.');
}
```

`index()` vrací tarify s `tenants_count` a počtem modulů; `show()` posílá seznam všech modulů (`ModuleRegistry::all()`), zaškrtnuté klíče tarifu a příznak `core` (core moduly jsou v seznamu jen informativně, bez checkboxu); `impact()` vrací JSON z `PlanModuleReconciler::impact()`.

- [ ] **Krok 5: Napiš Vue stránky**

`Show.vue`: checkboxy modulů, tlačítko „Spočítat dopad" (`router.get` na `platform.plans.impact` s `preserveState`), panel s výsledkem („dotkne se N e-shopů, zapne X, vypne Y"), povinné pole důvodu, které se zobrazí jen když dopad obsahuje deaktivace, a potvrzovací dialog před odesláním.

- [ ] **Krok 6: Spusť testy a build**

Spusť: `php artisan test tests/Feature/Platform` a `npm run build`
Očekávej: PASS.

- [ ] **Krok 7: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/Platform/PlanController.php
git add app/Http/Controllers/Platform routes/platform.php resources/js/Pages/Platform tests/Feature/Platform
git commit -m "feat(platform): manage plan modules from the superadmin"
```

---

### Task 10: Uzavření vlny

- [ ] **Krok 1: Spusť celou sadu**

Spusť: `php artisan test --compact`
Očekávej: PASS, žádný pád.

- [ ] **Krok 2: Ověř ručně na demu**

`php artisan serve`, přihlas se jako `admin@demo.cz`, projdi `/admin/nastaveni/moduly`, změň `docs.auto_issue_on` na „Při odeslání", zaplať objednávku (faktura nesmí vzniknout), odešli ji (faktura musí vzniknout). Jako superadmin změň složení tarifu a zkontroluj, že se to na e-shopu projeví.

- [ ] **Krok 3: Napiš as-is**

`docs/as-is/2026-07-29-nastaveni-modulu.md` podle `.claude/rules/as-is-on-milestone.md`, včetně povinné sekce Odchylky od specifikace.

- [ ] **Krok 4: Zapiš rozhodnutí do `CLAUDE.md`**

Minimálně: `settings_permission` jako manifestový klíč a proč nestačí konvence `<klíč>.manage`; obrazovka v jádře místo `/admin/m/{modul}/nastaveni` kvůli kolizi s vazbou produktu na slug; rekonciliace tarifu se řídí živým stavem, ne diffem editace.

- [ ] **Krok 5: Aktualizuj `docs/DEMO-URLS.md`**

Přidej `/admin/nastaveni/moduly` a `/superadmin/tarify`.

- [ ] **Krok 6: Uzavři vlnu**

Spusť skill `/finish-wave`.

---

## Sebekontrola plánu

- **Pokrytí specifikace:** AK 1 → Task 4 + 10; AK 2 a 2a → Task 4, Task 1; AK 3 → Task 3; AK 4 → Task 3 + 4; AK 5 → Task 2; AK 6 → Task 3; AK 7 → Task 5; AK 8 → Task 6; AK 9 → Task 6; AK 10 → Task 7; AK 11 → Task 9; AK 12 → Task 8 + 9; AK 13 → Task 8.
- **Konzistence typů:** `SettingsSchema::field()` vrací `?SettingsField` (Task 2) a přesně tak ho čte Task 3 (`->rules`) i Task 4 (`->key`, `->label`, `->type`, `->help`, `->options`). `PlanModuleReconciler::impact()` vrací tvar, který Task 9 čte v `updateModules()` (`['tenants']`, `['deactivate']`).
- **Známé riziko:** Task 5 dropuje sloupec `tenant_theme.variant_display`; migrace musí přenést hodnoty **před** dropem, jinak nájemci ztratí volbu. Test na to je v Tasku 5, kroku 1.
