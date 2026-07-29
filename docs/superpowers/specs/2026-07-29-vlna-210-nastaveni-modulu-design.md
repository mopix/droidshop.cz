# Vlna 2.10 — Nastavení modulů (nájemce) a správa tarifů (superadmin) — design

Datum: 2026-07-29 · Fáze 2 · Navazuje na: jádro (`SettingsService`, `ModuleRegistry`, `Manifest`, `TenantPermissions`), `docs` (jediný modul s dnešním `settings_schema`), `products` (`variant_display` z vlny 2.4), `checkout` a `orders` (nová nastavení), platformní `plan_modules` (vlny 1.1 a 2.9).

**Status:** approved

## Cíl

Nájemce si konečně nastaví, co mu modul dělá — bez zásahu do kódu a bez migrace. Jedna generická obrazovka vygenerovaná ze schématu, které modul deklaruje ve svém manifestu, plus doplnění schémat tam, kde chybí. Superadmin zároveň dostane obrazovku, kde přiřadí modul tarifu, což je dnes jediná operace platformy, která jde udělat výhradně migrací.

Dnešní stav: `SettingsService` má `get()` i `set()`, ale `set()` **nevolá nikdo** — v celém repozitáři existuje jen čtení (Docs listenery, issuery, PDF job). Sedm nastavení modulu `docs` (kdy se vystaví faktura, splatnost, patička, prefixy číselných řad) je tedy nastavitelných jen tak, že někdo změní default v kódu. Rozhodnutí z 2026-07-26 (`variant_display` na `tenant_theme`) tenhle dluh pojmenovalo přímo: „`SettingsService` dnes nemá žádnou admin obrazovku."

## Mimo rozsah (→ `docs/future/`)

- **Nastavení per modul pro superadmina** (platformní defaulty, které nájemce nesmí přepsat) — dnes žádný modul takové nastavení nepotřebuje
- **Historie změn nastavení** nad rámec `AuditLog` (kdo co kdy přepsal, rollback)
- **Podmíněná pole** ve formuláři (zobraz X, jen když Y = true) — schéma je plochý seznam
- **Nové typy polí** mimo `text`, `textarea`, `number`, `boolean`, `select` (barva, soubor, opakovatelná skupina)
- **Zakládání a mazání tarifů** v superadminu — vlna edituje jen složení existujících tarifů, ne ceník (ten sedí na `plan_prices` a Stripe Price objektech, viz rozhodnutí 2026-07-23)
- **Grandfathering** modulu odebraného z tarifu (nechat ho běžet stávajícím e-shopům) — vyžadovalo by `overridden_at` marker, který dnes nemáme

## Role

| Role | Co smí |
|------|--------|
| `TENANT_ADMIN` s právem, které modul určí v `settings_permission` | číst a měnit nastavení toho modulu |
| `TENANT_STAFF` | nic navíc (právo lze udělit, až role vznikne) |
| `SUPERADMIN` | měnit složení tarifu (`plan_modules`) a tím i moduly všech nájemců toho tarifu; **nevidí ani nemění nastavení konkrétního nájemce** |
| `CUSTOMER` / anonym | nic — obrazovky jsou v adminu, `noindex` |

**Jednotné `<klíč>.manage` neexistuje** a nedá se předstírat: `docs`, `shipping`, `discounts` a `feeds` ho mají, ale `products` má `products.view|edit|costs`, `orders` má `orders.view|edit|cancel` a `checkout` nemá **žádné právo** — nikdy neměl admin obrazovku. Konvence odvozená z klíče modulu by proto u poloviny modulů gatovala na právo, které neexistuje, a `Gate` by ho odmítl každému.

Manifest tedy dostane nový nepovinný klíč `settings_permission`: modul sám řekne, které ze svých práv obrazovku hlídá.

| Modul | `settings_permission` | Pozn. |
|-------|----------------------|-------|
| `docs` | `docs.manage` | existuje |
| `products` | `products.edit` | existuje; `variant_display` je katalogová prezentace |
| `orders` | `orders.edit` | existuje |
| `checkout` | `checkout.manage` | **nové právo** |

`checkout.manage` je jediné nové právo ve vlně a zavádí se proto, že hlídá reálnou plochu (minimum objednávky a nákup bez registrace mění, kdo a za kolik smí objednat). Tím se liší od zamítnutého `packeta.manage` z vlny 2.5, které nehlídalo nic — a to je hranice, kterou tady držíme dál.

Modul se schématem, ale bez `settings_permission`, je chyba manifestu (`ManifestValidator` ji odmítne) — ne obrazovka bez zámku.

## Rozhodnutí z brainstormingu (závazná)

| Otázka | Rozhodnutí |
|--------|-----------|
| Rozsah vlny | **všechny tři body**: generická obrazovka, doplnění schémat, UI pro `plan_modules` |
| Kde obrazovka leží | `/admin/nastaveni/moduly/{modul}` v jádře, **ne** `/admin/m/{modul}/nastaveni` |
| Formát schématu | `settings.json` = objekt per klíč (`rules`, `label`, `type`, `default`, `help`, `options`); starý tvar (holý string) zůstává platný |
| Kdo smí měnit | právo, které modul určí v novém `settings_permission`; jediné nové právo je `checkout.manage` |
| Sada nastavení | `docs` (7 stávajících) + `products.variant_display` (přesun) + **nová** `checkout.min_order_total`, `checkout.guest_checkout`, `orders.number_prefix` |
| Odebrání modulu z tarifu | **okamžitá rekonciliace všech tenantů tarifu**, po náhledu dopadu a potvrzení |
| Číslo vlny | **2.10** — 3.0 zůstává rezervovaná pro nasazení na VPS |

## Formát schématu

### Dnes

```json
{
    "due_days": "integer|min:0|max:90",
    "auto_issue_on": "in:paid,shipped,manual"
}
```

### Nově

```json
{
    "due_days": {
        "rules": "integer|min:0|max:90",
        "label": "Splatnost faktury (dny)",
        "type": "number",
        "default": 14,
        "help": "Počet dnů od vystavení do data splatnosti."
    },
    "auto_issue_on": {
        "rules": "in:paid,shipped,manual",
        "label": "Faktura se vystaví",
        "type": "select",
        "default": "paid",
        "options": {
            "paid": "Při zaplacení",
            "shipped": "Při odeslání objednávky",
            "manual": "Jen ručně"
        }
    }
}
```

Holý string zůstává platným zápisem a znamená `{"rules": "…"}` bez popisku — jinak by změna formátu rozbila validaci každému modulu, který schéma ještě nepřepsal, a vlna by musela přepsat všech třináct najednou.

`type` je **prezentační**, ne validační: autoritou nad tím, co se smí uložit, zůstávají `rules`. Formulář, který pošle text do `number` pole, spadne na validaci, ne na typu pole.

## Jádro

### `Manifest` a `ManifestValidator` (rozšíření)

Nová nepovinná vlastnost `settingsPermission` (v JSONu `settings_permission`, pravidlo `sometimes|nullable|string`). `ManifestValidator` navíc v `after()` ověří dvojici: modul se `settings_schema` **musí** mít `settings_permission` a to právo musí být v jeho vlastním seznamu `permissions` — jinak by manifest deklaroval zámek, který `TenantPermissions` nikdy neudělí, a obrazovka by byla nedostupná všem včetně vlastníka.

### `App\Core\Settings\SettingsSchema` (nový)

Readonly hodnotový typ. `SettingsSchema::fromArray(array $raw)` znormalizuje oba tvary zápisu na seznam `SettingsField` (`key`, `rules`, `label`, `type`, `default`, `help`, `options`). Chybějící `label` degraduje na klíč, chybějící `type` se odvodí z pravidel (`boolean` → checkbox, `in:` → select, `integer`/`numeric` → number, jinak text), aby modul s legacy schématem měl použitelný formulář.

### `SettingsService` (rozšíření)

| Metoda | Změna |
|--------|-------|
| `schemaFor(string $module)` | vrací `?SettingsSchema` místo pole |
| `all(string $module)` | **slévá uložené hodnoty s `default` ze schématu** |
| `setMany(string $module, array $values)` | **nová**: zvaliduje všechny klíče, teprve pak zapíše, celé v jedné transakci |
| `set()`, `get()`, `forget()` | beze změny |

`all()` sléváním defaultů uzavírá druhou pravdu: dnes žije výchozí hodnota v každém volání zvlášť (`get('docs', 'due_days', config('documents.default_due_days'))`), takže schéma a kód mohou tvrdit každý něco jiného.

`setMany()` existuje kvůli formuláři: ukládání po klíčích by při chybě na šesté hodnotě nechalo prvních pět zapsaných a nájemce by měl polovinu nastavení změněnou a polovinu ne.

## Obrazovka nájemce

| Routa | Název | Co dělá |
|-------|-------|---------|
| `GET /admin/nastaveni/moduly` | `admin.settings.modules.index` | seznam zapnutých modulů, které mají schéma |
| `GET /admin/nastaveni/moduly/{module}` | `admin.settings.modules.edit` | formulář ze schématu |
| `PATCH /admin/nastaveni/moduly/{module}` | `admin.settings.modules.update` | `setMany()` + `AuditLog` |

Skupina `routes/tenant.php` pod `['web', 'tenant.member']` — vedle `/admin/nastaveni/fakturace` (1.7), `/domena` (2.1) a `/vzhled` (2.2).

**Proč ne `/admin/m/{modul}/nastaveni`:** `admin/m/products/{product}` váže produkt slugem a je registrovaná při bootu, takže `/admin/m/products/nastaveni` by Laravel hledal jako produkt jménem „nastaveni" a vrátil 404. Stejná past, kvůli které CSV import a export leží **nad** `/{product}` (rozhodnutí 2026-07-28).

Gate v controlleru, ne middlewarem: `module:{klíč}` je statický alias per routa a `{module}` je tady parametr. Controller proto sám ověří `ModuleRegistry::isEnabled()` (vypnutý nebo neznámý modul → 404, ne 403 — 403 by prozradilo, které moduly e-shop neběží) a pak `Gate::authorize()` na právo z manifestového `settings_permission`.

Frontend: jedna generická Inertia stránka `resources/js/Pages/Tenant/ModuleSettings.vue`, pole podle `type`, chyby z `form.errors`. Odkaz na seznam patří do stejného místa adminu jako ostatní `/admin/nastaveni/*` obrazovky.

## Co se nastavuje

### `docs` — jen přepis schématu

Sedm klíčů (`auto_issue_on`, `email_invoice`, `invoice_footer`, `due_days`, `number_prefix`, `credit_note_prefix`, `proforma_prefix`) dostane popisky a defaulty. Logika je čte už dnes, takže se nemění nic než `Modules/Docs/settings.json`.

### `products.variant_display` — přesun

Hodnota se stěhuje z `tenant_theme.variant_display` do `settings`. Migrace přepíše existující hodnoty, pole zmizí z `/admin/nastaveni/vzhled` (`AppearanceController`, `UpdateAppearanceRequest`, `Appearance.vue`) a `App\Core\Theme\VariantDisplay::forCurrentTenant()` začne číst `SettingsService`. Sloupec se po backfillu dropne.

Tím se plní slib z rozhodnutí 2026-07-26: pole sedělo u vzhledu jen proto, že obrazovka nastavení modulu neexistovala.

### `checkout` — nová logika

| Klíč | Typ | Chování |
|------|-----|---------|
| `min_order_total` | number (haléře, default `0`) | pod minimem nejde odeslat objednávku; hláška se ukáže v košíku i v rekapitulaci pokladny, tlačítko platební povinnosti je neaktivní |
| `guest_checkout` | boolean (default `true`) | při `false` `CheckoutController::details()` přesměruje hosta na `storefront.customers.login` s uloženou intended URL |

Obojí se vynucuje **na serveru** a musí projít bez JS. Minimum se porovnává proti součtu položek po slevě (ne proti celkové ceně s dopravou) — jinak by drahá doprava dostala nájemce přes vlastní limit.

### `orders.number_prefix` — nová logika

`OrderPlacer` složí číslo objednávky z prefixu ze settings, stejnou cestou, jakou `InvoiceIssuer` skládá číslo faktury (`SequenceService::next()` vrací číslo, prefix přidává volající). Prefix se **nemění zpětně** — už vystavená čísla zůstávají, mění se jen další v řadě.

## Superadmin — složení tarifů

| Routa | Název | Co dělá |
|-------|-------|---------|
| `GET /superadmin/tarify` | `platform.plans.index` | seznam tarifů, počet e-shopů, počet modulů |
| `GET /superadmin/tarify/{plan}` | `platform.plans.show` | checkboxy modulů |
| `GET /superadmin/tarify/{plan}/dopad` | `platform.plans.impact` | náhled dopadu pro navrženou sadu |
| `PATCH /superadmin/tarify/{plan}/moduly` | `platform.plans.modules` | zápis + rekonciliace |

Skupina `['auth:platform', 'platform.2fa']` v `routes/platform.php`, jako zbytek superadminu.

### `App\Core\Modules\PlanModuleReconciler` (nový)

1. **Dopad**: pro navrženou sadu klíčů spočítá, kolik e-shopů tarifu se dotkne, které moduly by se jim zapnuly a které vypnuly. Vrací to samostatný endpoint, aby obrazovka mohla ukázat čísla dřív, než někdo klikne (vzor `platform.tenants.plan-impact` z 1.9).
2. **Potvrzení** je povinné u odebrání — bere e-shopům funkci, kterou používají. Přidání potvrzení nepotřebuje (vzor kill switche: zapnutí bez důvodu, vypnutí s povinným zdůvodněním).
3. **Zápis** přepíše `plan_modules` a projde všechny tenanty tarifu:
   - `activate = klíče tarifu − zapnuté`, protnuto s `ModuleRegistry::available()` — globálně stažený modul se **přeskočí**, nevyhodí výjimku,
   - `deactivate = zapnuté ∩ (klíče odebrané z tarifu)`; core moduly v `plan_modules` nejsou, takže se nikdy nevypnou.
   Je to táž rekonciliace, jakou dělá `TenantPlanSwitcher` na `customer.subscription.updated` (rozhodnutí 2026-07-23) — musí být idempotentní a nezávislá na pořadí.
4. **Audit**: záznam per tenant, ne jen jeden za celou operaci.

Tím se zavírá past z vlny 2.9: modul, který není v žádném tarifu, fakticky neexistuje (nájemce dostane `PlanDoesNotIncludeModule`) a dnes to jde napravit jen migrací.

## Akceptační kritéria

1. Nájemce s `docs.manage` otevře `/admin/nastaveni/moduly/docs`, přepne „Faktura se vystaví" na „Při odeslání", uloží — a faktura se od té chvíle vystaví při přechodu objednávky na odesláno, ne při zaplacení.
2. Nájemce **bez** práva z `settings_permission` dostane 403; na modul, který jeho e-shop neběží, dostane **404**.
2a. Manifest se `settings_schema`, ale bez `settings_permission` (nebo s právem, které modul nedeklaruje), neprojde `modules:sync`.
3. Nastavení jednoho nájemce se nikdy neprojeví u druhého (test izolace nad `settings`).
4. Neznámý klíč v požadavku je odmítnutý; neplatná hodnota vrátí chybu u konkrétního pole a **žádná** hodnota z toho formuláře se neuloží.
5. Modul se schématem ve starém tvaru (holý string) má funkční formulář i validaci.
6. Hodnota, kterou nájemce nikdy neuložil, se čte jako `default` ze schématu — na obrazovce i v kódu, který ji používá.
7. `variant_display` se po migraci čte ze `settings`, na `/admin/nastaveni/vzhled` už není a přepis per produkt funguje beze změny.
8. Košík pod `min_order_total` nedovolí odeslat objednávku a řekne proč — s vypnutým JavaScriptem.
9. Při `guest_checkout = false` skončí host na přihlášení zákazníka a po přihlášení pokračuje tam, kde byl.
10. Objednávka založená po změně `orders.number_prefix` nese nový prefix; už existující čísla se nezmění.
11. Superadmin vidí u tarifu před uložením, kolika e-shopů se změna dotkne a co přesně se jim zapne či vypne.
12. Odebrání modulu z tarifu ho vypne všem e-shopům toho tarifu a zapíše se to do `AuditLog` per tenant; přidání ho zapne, ale **přeskočí** modul stažený globálním kill switchem.
13. Rekonciliace nikdy nevypne core modul.

## Testy

- **Jádro**: `SettingsSchema` nad oběma tvary zápisu, odvození typu z pravidel, `setMany()` all-or-nothing, slévání defaultů, izolace tenantů, manifest se schématem bez platného `settings_permission` neprojde validací.
- **Obrazovka nájemce**: 403 bez práva, 404 na vypnutý modul, uložení a efekt (`docs`), neznámý klíč, neplatná hodnota.
- **Přesun `variant_display`**: migrace hodnot, fallback na default, storefront render obou režimů.
- **`checkout`**: minimum blokuje odeslání bez JS, `guest_checkout = false` přesměruje a vrátí zpět; obojí i cestou, kterou jde `OrderPlacer`, ne jen přes UI.
- **`orders`**: prefix se projeví na dalším čísle, staré objednávky beze změny.
- **Tarify**: dopad počítá správně, přidání i odebrání rekonciliuje, kill-switch výjimka, core modul nedotčen, audit per tenant.

## Rizika a technický dluh

- **Rekonciliace tarifu je destruktivní akce nad cizími e-shopy.** Proto povinný náhled dopadu, potvrzení a audit per tenant. Bez toho by jeden odškrtnutý checkbox tiše vypnul modul stovce nájemců.
- **Ruční per-tenant zapnutí tarifního modulu vlna nezavádí.** Trade-off z 2026-07-23 platí dál: rekonciliace vyhrává nad ručním stavem, takže modul zapnutý mimo tarif se při další rekonciliaci vypne.
- **`min_order_total` v haléřích** je konzistentní se zbytkem peněz v projektu, ale ve formuláři je to matoucí. Pole dostane nápovědu; převod na koruny v UI je kandidát na doplnění, ne součást vlny.
- **Typ pole odvozený z pravidel** se u složitějších pravidel netrefí přesně. Modul to řeší explicitním `type`; odvození je jen záchranná síť pro legacy schéma.

## Reference

- Plán: `docs/superpowers/plans/2026-07-29-vlna-210-nastaveni-modulu.md`
- As-is (po dokončení): `docs/as-is/2026-07-29-nastaveni-modulu.md`
- Navazující rozhodnutí: 2026-07-26 (`variant_display` na `tenant_theme`), 2026-07-23 (`TenantPlanSwitcher` a rekonciliace modulů), 2026-07-28 (routy nad `/{product}`, zamítnuté `packeta.manage`)
