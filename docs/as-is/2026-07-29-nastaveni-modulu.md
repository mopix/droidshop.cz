# As-is: nastavení modulů a správa tarifů (vlna 2.10)

Datum: 2026-07-30 · Verze: **0.28.x** (minor uzavře `/finish-wave`) · Větev: `feature/vlna-210-nastaveni-modulu` · **1658 testů** zelených (5845 assertions)

Spec: [`docs/superpowers/specs/2026-07-29-vlna-210-nastaveni-modulu-design.md`](../superpowers/specs/2026-07-29-vlna-210-nastaveni-modulu-design.md)
Plán: [`docs/superpowers/plans/2026-07-29-vlna-210-nastaveni-modulu.md`](../superpowers/plans/2026-07-29-vlna-210-nastaveni-modulu.md)

## Co vlna přinesla

Nájemce si poprvé nastaví chování modulu z adminu. Do této vlny existoval `SettingsService`, ale **nikdo nevolal jeho `set()`** — sedm nastavení modulu `docs` (splatnost, prefixy číselných řad, patička faktury, kdy se faktura vystaví) šlo změnit jen zásahem do kódu. Nově je nad manifestovým schématem jedna generická obrazovka, takže nový modul dostane své nastavení bez vlastní obrazovky.

Superadmin zároveň skládá tarify z nasazených modulů. Přiřadit modul tarifu šlo dosud jen migrací — past, do které vlna 2.9 spadla: modul, který není v žádném tarifu, si nájemce nemůže zapnout a fakticky neexistuje.

## Mapa změn

### Jádro — schéma a služba

| Soubor | Role |
|--------|------|
| `app/Core/Settings/SettingsField.php` | Jedno pole: `rules` (autorita), `type` (jen vykreslení), `label`, `default`, `help`, `options` |
| `app/Core/Settings/SettingsSchema.php` | Parser schématu; `fields()`, `field()`, `has()`, `rules()`, `defaults()` |
| `app/Core/Settings/SettingsService.php` | `all()` slévá defaulty ze schématu, nový `setMany()` (validace celé sady, pak zápis v jedné transakci), `schemaFor()` vrací `?SettingsSchema` |
| `app/Core/Modules/Manifest.php` | Nový klíč `settings_permission` |
| `app/Core/Modules/ManifestValidator.php` | Pravidlo + křížová kontrola, že jmenované právo modul opravdu deklaruje |
| `app/Console/Commands/ModulesSync.php` | Vadné schéma (pole bez `rules`) shodí sync — deploy, ne checkout |
| `app/Core/Modules/PlanModuleReconciler.php` | `impact()` / `apply()` nad `plan_modules` + rekonciliace modulů všech tenantů tarifu |

### Obrazovka nájemce

| Soubor | Role |
|--------|------|
| `app/Http/Controllers/Tenant/ModuleSettingsController.php` | `index` / `edit` / `update`; 404 na neběžící modul, 403 bez práva z manifestu |
| `app/Http/Requests/Tenant/UpdateModuleSettingsRequest.php` | Pravidla ze schématu modulu + closure, která odmítne klíč mimo schéma |
| `resources/js/Pages/Tenant/ModuleSettingsIndex.vue` | Seznam modulů, které mají co nastavovat |
| `resources/js/Pages/Tenant/ModuleSettings.vue` | Formulář generovaný z `fields`, pole podle `type` |
| `routes/tenant.php` | `admin.settings.modules.index|edit|update` |
| `resources/js/Layouts/AdminLayout.vue` | Položka „Nastavení modulů“ v nav |

### Obrazovka superadmina

| Soubor | Role |
|--------|------|
| `app/Http/Controllers/Platform/PlanController.php` | `index` / `show` / `impact` (JSON) / `updateModules` |
| `resources/js/Pages/Platform/Plans/Index.vue` | Tarify s počtem e-shopů a modulů |
| `resources/js/Pages/Platform/Plans/Show.vue` | Checkboxy modulů, „Spočítat dopad“, potvrzovací dialog s povinným důvodem při odebrání |
| `routes/platform.php` | `platform.plans.index|show|impact|modules` |
| `resources/js/Layouts/PlatformLayout.vue` | Položka „Tarify“ v nav |

### Schémata modulů

| Soubor | Obsah |
|--------|-------|
| `Modules/Docs/settings.json` | Přepis sedmi polí ze starého tvaru „klíč → string pravidel“ do objektového |
| `Modules/Products/settings.json` | `variant_display` (přesun z `tenant_theme`) |
| `Modules/Checkout/settings.json` | `min_order_total`, `guest_checkout` (nové právo `checkout.manage`) |
| `Modules/Orders/settings.json` | `number_prefix` |

### Nová logika mimo schémata

| Soubor | Změna |
|--------|-------|
| `app/Core/Theme/VariantDisplay.php` | Čte `settings('products','variant_display')`, ne `tenant_theme` |
| `database/migrations/2026_07_29_100000_move_variant_display_to_settings.php` | Přenese hodnoty, pak dropne sloupec |
| `app/Http/Controllers/Tenant/AppearanceController.php`, `UpdateAppearanceRequest`, `Appearance.vue` | `variant_display` vyňato ze Vzhledu |
| `Modules/Checkout/Http/Controllers/CheckoutController.php` | `refuseBelowMinimum()` + `refuseGuest()` v `details()` i `place()` |
| `Modules/Checkout/Http/Controllers/CartController.php`, `Resources/views/cart.blade.php` | Hláška o minimu, skryté tlačítko „Pokračovat k pokladně“ |
| `Modules/Orders/Services/OrderPlacer.php` | Číslo objednávky = prefix ze settings + `SequenceService::next('orders')` |

## Jak to funguje

### Formát schématu

Pole nese `rules` jako autoritu nad tím, co smí být uloženo, a `type` jen jako informaci, čím to nakreslit. Starý tvar (`"per_page": "integer|min:1"`) se dál parsuje — `type` se odvodí z pravidel (`boolean` → checkbox, `max:>255` → textarea) a `label` z klíče. Pole **bez** pravidel je chyba: takové by tiše přijalo cokoli. Chyba padá při `modules:sync`, ne uvnitř `SettingsService::all()` na hot path (checkout, vystavení faktury, generování PDF); runtime cesta ji propaguje dál, aby druhá brána neschovávala tu samou vadu za tiše prázdné pole.

### Zápis je all-or-nothing

`setMany()` zvaliduje celou sadu a teprve pak zapisuje, v jedné transakci. Po jednom by šestá odmítnutá hodnota nechala e-shop běžet na míchanici starého a nového nastavení, a nic na obrazovce by to neřeklo.

### Dvě brány obrazovky, v tomto pořadí

Modul, který e-shop neběží, je **404** — 403 by prozradilo, které moduly e-shopu chybí. Právo je to, které jmenuje manifest (`settings_permission`), ne konvence `<klíč>.manage`: `products` má `products.edit`, `docs` má `docs.manage`, `orders` má `orders.edit`. Modul bez jmenovaného práva nesmí nastavovat nikdo — fallback na „kdokoli v adminu“ by dal personálu do ruky číselnou řadu modulu, na který jinak nemá právo.

### Minimum objednávky a nákup bez registrace

Obě pravidla vynucuje server v `details()` i `place()`, před jakýmkoli zápisem. Skryté tlačítko v košíku je prezentace, ne pravidlo — zapomenutá karta v prohlížeči se přes minimum neprotlačí. Minimum se měří na **zboží po slevě, bez dopravy a platby**: kdyby se do něj počítala doprava, drahý dopravce by zákazníka přenesl přes hranici, kterou si nájemce sám nastavil. Host se posílá na přihlášení přes `redirect()->guest()`, takže po přihlášení pokračuje tam, kde byl.

### Prefix čísla objednávky

Prefix je nastavení nájemce, ne součást řádku v `sequences`. Změna proto nikdy nepřepisuje čísla, která už byla vydána — stejné dělení, jaké používá `InvoiceIssuer` pro čísla dokladů.

### Rekonciliace tarifu

`PlanModuleReconciler` počítá, co udělat, ze **živě zapnuté sady** tenanta, ne z diffu editace — je tedy idempotentní a nezávislý na pořadí (stejný tvar jako `TenantPlanSwitcher` z vlny 1.9). Katalog „co vůbec smí tarif udělovat“ se snímá **před** zápisem: přečtený po `sync()` už neobsahuje klíč, který tahle editace odebrala, a nevypnulo by se nikdy nic. `activate` se protíná s `ModuleRegistry::available()`, takže globálně kill-switchnutý modul se přeskočí místo výjimky, která by shodila změnu dotýkající se mnoha e-shopů kvůli jednomu modulu, který už incident vyřadil. Core moduly nejsou v `plan_modules`, což je přesně to, co je drží mimo dosah deaktivace.

Povinný důvod se vyžaduje podle **spočítaného dopadu**, ne podle formuláře: jestli se něco reálně ztrácí, závisí na tom, co ty e-shopy dnes běží.

## Plnění spec (akceptační kritéria)

| AK | Stav | Kde |
|----|------|-----|
| 1 — nájemce přepne `auto_issue_on` a faktura se vystaví jinde | ✅ mechanika | `ModuleSettingsTest`, `AutoIssueTest` (ověření na demu = otevřený bod, viz níže) |
| 2 — 403 bez práva, 404 na neběžící modul | ✅ | `ModuleSettingsTest::test_a_member_without_the_permission_is_forbidden`, `…does_not_run_is_not_found` |
| 3 — izolace mezi nájemci | ✅ | `SettingsServiceTest::test_one_tenants_settings_never_leak_into_another` |
| 4 — neznámý klíč odmítnut, neplatná hodnota neuloží nic | ✅ | `ModuleSettingsTest::test_an_unknown_key_is_rejected`, `…nothing_is_written` |
| 5 — starý tvar schématu funguje | ✅ | `tests/Unit/Core/SettingsSchemaTest.php`, `ModulesSyncTest::test_sync_passes_for_a_well_formed_schema` |
| 6 — neuložená hodnota se čte jako default | ✅ | `SettingsServiceTest` (defaulty v `all()`), `ModuleSettingsTest` (`values.due_days = 14`) |
| 7 — `variant_display` ze settings, mizí ze Vzhledu, přepis per produkt platí | ✅ | `VariantDisplayTest`, `AppearanceControllerTest`, `VariantStorefrontTest` |
| 8 — košík pod minimem neodešle a řekne proč, bez JS | ✅ | `CheckoutSettingsTest` (5 testů, včetně přímého POST) |
| 9 — `guest_checkout = false` pošle hosta na přihlášení | ✅ | `CheckoutSettingsTest::test_a_guest_is_sent_to_login…`, `…a_signed_in_customer_passes…` |
| 10 — nový prefix platí od další objednávky | ✅ | `OrderNumberPrefixTest` (3 testy) |
| 11 — superadmin vidí dopad před uložením | ✅ | `PlanManagementTest::test_the_impact_endpoint_answers_before_anything_is_written` |
| 12 — odebrání vypne všem + audit per tenant; kill switch se přeskočí | ✅ | `PlanModuleReconcilerTest`, `PlanManagementTest` |
| 13 — rekonciliace nevypne core modul | ✅ | `PlanModuleReconcilerTest::test_a_core_module_is_never_deactivated` |

## Testy

| Soubor | Co drží |
|--------|---------|
| `tests/Unit/Core/SettingsSchemaTest.php` | Parsování obou tvarů, odvození typu, `defaults()` u falsy hodnot, odmítnutí pole bez pravidel |
| `tests/Feature/Core/SettingsServiceTest.php` | Defaulty v `all()`, `setMany()` all-or-nothing, izolace, propagace vadného schématu |
| `tests/Feature/Core/ModulesSyncTest.php` | Vadné schéma shodí sync; dobré projde |
| `tests/Feature/Tenant/ModuleSettingsTest.php` | 9 testů: formulář ze schématu, uložení, validace, 403/404, index podle práv |
| `tests/Feature/Theme/VariantDisplayTest.php` | Čtení ze settings, fallback na `radio`, přepis per produkt |
| `tests/Feature/Modules/Checkout/CheckoutSettingsTest.php` | 8 testů: minimum (i na hraně, i přímý POST), guest checkout, výchozí stav |
| `tests/Feature/Modules/Orders/OrderNumberPrefixTest.php` | Prefix od další objednávky, holé číslo bez prefixu, stará čísla nezměněna |
| `tests/Feature/Platform/PlanModuleReconcilerTest.php` | 8 testů: dopad nic nezapíše, rekonciliace, cizí tarif, kill switch, core, idempotence, audit |
| `tests/Feature/Platform/PlanManagementTest.php` | 8 testů: seznam, detail, dopad, povinný důvod, uložení, autorizace |

Celá sada: **1658 zelených**.

## Odchylky od specifikace

1. **`variant_display` se přesunul, ale zůstal svázán s `tenant_theme` migrací, ne novým sloupcem.** Spec počítala s přesunem — hotovo; navíc se dropnul sloupec `tenant_theme.variant_display`. Migrace hodnoty přenese před dropem, takže e-shop nastavený na rozbalovací seznam se po deployi nepřepne na přepínače. Tím se uzavírá odchylka zapsaná ve vlně 2.4.
2. **`checkout` dostal nové právo `checkout.manage`.** Spec ho nezmiňovala; bez něj by nešlo obrazovku nastavení modulu autorizovat (manifest musí jmenovat existující právo — hlídá `ManifestValidator`). Právo hlídá jen tuhle obrazovku, což je jeho jediný účel, takže nejde o klamnou autorizační plochu jako `packeta.manage` ve vlně 2.5.
3. **Guard na vadné schéma v `modules:sync` není v plánu.** Doplněno nad rámec: schéma s polem bez pravidel by jinak vybuchlo až za běhu na hot path. Deploy s vadným schématem teď selže při deployi.
4. **Obrazovka nastavení sedí v jádře (`/admin/nastaveni/moduly/{modul}`), ne pod `/admin/m/{modul}/nastaveni`.** Modulová cesta by u `products` kolidovala s vazbou produktu na slug (`/admin/m/products/{product}`) — `nastaveni` by se hledalo jako produkt toho jména. Precedent: routy importu/exportu ve vlně 2.8.
5. **Ruční ověření na demu (AK 1 end-to-end, plán Task 10 krok 2) neproběhlo.** Mechanika je pokrytá testy včetně `AutoIssueTest`, ale proklikání dema zbývá.

## Oprava po uzavření vlny (2026-07-30)

Nesrovnalost „tarif hlásí 13 modulů, detail nabízí 12" odkryla vadu se širším dosahem než počítadlo. V `plan_modules` ležel core modul `storefront` (dostal ho tam `DemoShopSeeder`, který demo tarifu přiřazoval **všechny** nasazené moduly). `PlanModuleReconciler` i `TenantPlanSwitcher` z vlny 1.9 odvozují „co smí tarif odebrat" z téhle tabulky a spoléhaly na předpoklad zapsaný jen v komentáři, takže core klíč spadl do deaktivační sady, kde `ModuleRegistry::deactivate()` vyhodí výjimku:

- superadmin uložení tarifu → 500 **po** tom, co `sync()` už `plan_modules` přepsal (napůl aplikovaná změna),
- `TenantPlanSwitcher` → throw uvnitř transakce Stripe webhooku vrátí i idempotenční claim, takže Stripe doručuje event navždy (přesně scénář, proti kterému 1.9 stavěla guard na kill-switchnuté moduly).

Opraveno: oba callery core klíče odečítají explicitně; migrace `2026_07_30_090000_remove_core_modules_from_plan_modules` řádky uklidila; `DemoShopSeeder` core přeskakuje; počítadlo v seznamu tarifů počítá jen grantable moduly. Čtyři nové testy (reconciler, switcher, seznam tarifů) — celkem 1662 zelených.

## Technický dluh

1. **Ruční ověření na demu.** `/admin/nastaveni/moduly` → `docs.auto_issue_on` na „Při odeslání“ → zaplatit objednávku (faktura nesmí vzniknout) → odeslat (musí vzniknout). Plus změna složení tarifu superadminem a kontrola, že se to na e-shopu projeví.
2. **Rekonciliace tarifu běží synchronně v requestu.** U tarifu s mnoha e-shopy poroste doba odpovědi lineárně s počtem tenantů. Kandidát na queued job s průběhem.
3. **Ručně vypnutý tarifní modul se při editaci tarifu znovu zapne** — stejný vědomý trade-off jako u `TenantPlanSwitcher` (vlna 1.9). Per-tenant ruční vypnutí tarifního modulu není podporovaný workflow MVP.
4. **`ModuleSettings.vue` nemá typované schéma polí per modul.** Formulář je generický, takže `values` je `Record<string, unknown>` — chyba v typu se ukáže až validací na serveru.
5. **Nastavení se needituje hromadně přes moduly.** Každý modul má vlastní obrazovku; nájemce s pěti moduly klikne pětkrát.

## Pre-deploy checklist

- [ ] `php artisan modules:sync` **před** `migrate` (nová manifestová pole `settings_permission`, nová schémata; sync navíc odmítne vadné schéma)
- [ ] `php artisan migrate` (přesun `variant_display` + drop sloupce)
- [ ] `npm run build` (nové Vue stránky, změněný `Appearance.vue`)
- [ ] Zkontrolovat, že tenanti s vlastní volbou `variant_display` mají po migraci řádek v `settings` (`module = products`)
- [ ] Ověřit, že `checkout.manage` má owner (odvozuje se z manifestu, nic se neseeduje)
