# Vlna 3.1 — technické dluhy (stale tenant, feed cache, e-mail o stavu, routa stránek) — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zavřít čtyři nesené dluhy, které blokují nebo znejasňují další práci: stale instance tenanta v `runAs()`, feed držící zrušenou dopravu až hodinu, tichá změna stavu e-shopu bez zprávy nájemci, a statické stránky na `/stranka/{slug}` místo `/{page-slug}`.

**Architecture:** Bez nové infrastruktury. `TenantContext` sjednotí všechny čtyři cesty přepnutí tenanta na jednu metodu. `PageCacheObserver` dostane jeden řádek navíc. Změna stavu tenanta začne vysílat doménový event, na který visí jediný listener s poštou — sweeper svoje dvě vlastní odeslání ztratí, aby existovala jedna cesta. Stránky se přesunou na kořenový `Route::fallback()`, stará cesta odpoví 301.

**Tech Stack:** Laravel 13, PHPUnit, Blade SSR. Žádná nová závislost.

**Spec:** není samostatná — dluhy jsou evidované v [`docs/as-is/STATUS.md`](../../as-is/STATUS.md) sekce „Známá omezení" a v rozhodnutích CLAUDE.md z 2026-08-03.

## Global Constraints

- **Žádná nová závislost.** `composer.json` ani `package.json` se nemění.
- **Kód anglicky** (názvy, komentáře, commit zprávy), uživatelské texty česky.
- **PHP 8.3**, žádné 8.4 konstrukce (property hooks, `array_find`).
- **`./vendor/bin/pint`** na dotčené soubory před každým commitem.
- **`app/Core/` nikdy neimportuje modulovou třídu** — modul se resolvuje stringem (`ModuleRegistry`, `PageCacheObserver::DIMENSION_BY_MODEL`, `TenantProvisioner`).
- **Testy dědí `Tests\TestCase`** a používají `RefreshDatabase`. Testy dotýkající se page cache nastavují `config()->set('cache.default', 'array')` a `config()->set('tenancy.platform_domain', 'droidshop')` — vzor `tests/Feature/Storefront/StorefrontCatalogTest.php`.
- **Existující sada nesmí po vlně padat.** Task 3 mění, kdo posílá lifecycle poštu — testy `SweepTenantLifecycle` se musí upravit, ne obejít.
- **Testy pouštěj po adresářích, ne jednou dávkou** — plná sada přetéká timeout a shazuje sdílenou MySQL testovací databázi.

---

### Task 1: `runAs()` a `runWithoutTenant()` přestanou obcházet `set()`

**Problém:** `TenantContext::set()` dostal ve vlně 3.0 obezličku kolem short-circuitu `spatie/laravel-multitenancy` — `makeCurrent()` se při shodném id tenanta vrátí bez výměny svázané instance, takže worker přežívající víc než jeden request servíruje atributy prvního requestu. `runAs()` a `runWithoutTenant()` ale volají `$tenant->makeCurrent()` **napřímo**, takže tam ta díra zůstala celá. Trefí to každou cestu, která běží uvnitř `runAs`: superadmin změna stavu, Stripe webhook, sweeper, `TenantProvisioner`, `AuditLog`.

**Files:**
- Modify: `app/Core/Tenancy/TenantContext.php`
- Test: `tests/Feature/Tenancy/TenantContextTest.php` (vytvořit, pokud neexistuje)

**Interfaces:**
- Consumes: `App\Models\Tenant`
- Produces: beze změny veřejného API (`set`/`forget`/`runAs`/`runWithoutTenant`)

- [ ] **Step 1: Napiš padající test**

Test má prokázat, že po `runAs()` s **čerstvě načtenou** instancí téhož tenanta vrací `current()` novou instanci s novými atributy, ne tu původní:

```php
public function test_run_as_swaps_the_bound_instance_for_the_same_tenant(): void
{
    $tenant = Tenant::factory()->create(['page_gen_catalog' => 1]);
    $this->context->set($tenant);

    Tenant::whereKey($tenant->id)->update(['page_gen_catalog' => 2]);
    $fresh = Tenant::find($tenant->id);

    $seen = $this->context->runAs($fresh, fn () => $this->context->current()->page_gen_catalog);

    $this->assertSame(2, $seen);
    // a po návratu je zpátky původní instance
    $this->assertSame(1, $this->context->current()->page_gen_catalog);
}
```

Druhý test pro `runWithoutTenant()`: uvnitř je `current()` null, po návratu je svázaná **ta instance, která byla svázaná před voláním**, ne znovu-načtená.

- [ ] **Step 2: Implementuj**

V `runAs()` nahraď obě volání `makeCurrent()` voláním `$this->set(...)`; v `runWithoutTenant()` nahraď `$previous?->makeCurrent()` za `if ($previous) { $this->set($previous); }` a `Tenant::forgetCurrent()` za `$this->forget()`.

Komentář u `set()` rozšiř o větu, že je to **jediná** cesta k přepnutí a že přímé `makeCurrent()` v této třídě už nikde nezůstalo.

- [ ] **Step 3: Ověř**

```
php artisan test tests/Feature/Tenancy --compact
```

- [ ] **Step 4: Commit** — `fix(tenancy): route every context switch through TenantContext::set()`

---

### Task 2: Změna způsobu dopravy invaliduje feed

**Problém:** Feed nese blok `DELIVERY` z `ShippingOptions`, ale `ShippingMethod` není v `PageCacheObserver::DIMENSION_BY_MODEL`. Nájemce, který zdraží nebo vypne dopravu, ji má ve feedu Heureky ještě hodinu — porovnávač zobrazuje cenu dopravy, kterou e-shop neúčtuje.

**Rozhodnutí:** `ShippingMethod` → `Dimension::Catalog`, tedy stejný bump jako produkt. Blast radius (celý katalogový cache nájemce) je tu přijatelný, na rozdíl od nastavení feedu, které si `FeedAdminController::forgetCache()` řeší cíleným `Cache::forget` — dopravu nájemce mění řádově jednou za měsíce, kdežto mapování kategorií ladí v sériích. Vlastní kontrakt jen kvůli jednomu řádku by přidal vrstvu bez druhého volajícího.

`PaymentMethod` se **nepřidává**: feed platby nenese a jediná stránka, kde se objevují, je pokladna, která je `no-store`.

**Files:**
- Modify: `app/Core/PageCache/PageCacheObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` (jen pokud registrace observeru iteruje jinou konstantu než `DIMENSION_BY_MODEL` — ověř řádek 193)
- Test: `tests/Feature/PageCache/PageCacheInvalidationTest.php` (přidat případ do existujícího)

**Interfaces:**
- Consumes: `Modules\Shipping\Models\ShippingMethod` (stringem, ne importem)

- [ ] **Step 1: Napiš padající test** — uložení `ShippingMethod` zvedne `page_gen_catalog` nájemce; ověř navíc, že feed vydaný před zápisem a po zápisu má jiný klíč (přes `FeedCache::key()`).

- [ ] **Step 2: Implementuj** — jeden řádek v mapě + odstavec do docblocku třídy vysvětlující, proč Catalog a proč ne `PaymentMethod`.

- [ ] **Step 3: Ověř**

```
php artisan test tests/Feature/PageCache --compact
php artisan test tests/Feature/Feeds --compact
```

- [ ] **Step 4: Commit** — `fix(pagecache): invalidate the catalogue generation when a shipping method changes`

---

### Task 3: Nájemce se dozví o změně stavu svého e-shopu

**Problém:** `Tenant::changeStatus()` zapíše audit a mlčí. Poštu posílá jen `SweepTenantLifecycle`, a to sám za sebe ve dvou místech. Superadmin, který e-shop pozastaví, a Stripe webhook, který ho po neuhrazené faktuře přepne na `past_due`, neinformují nikoho — nájemce zjistí pozastavení tím, že mu přestane fungovat admin.

**Rozhodnutí:** poštu vysílá **jeden doménový event z `changeStatus()`**, ne volající. Volajících je dnes pět a přibývají; instrumentovat je po jednom znamená, že šestý se zapomene (stejná úvaha, proč page cache jede na observeru a ne ve writerech). Sweeper svá dvě odeslání **ztrácí** — jinak by po přechodu trial→past_due odešly dvě zprávy.

Event se dispatchne přes `DB::afterCommit`: `StripeWebhookHandler` mění stav uvnitř transakce, která nese i idempotenční claim, takže inline odeslání by poslalo poštu i o změně, která se pak vrátila zpět (precedent `OrderWorkflow::settlePaid` a auto-vystavení dokladu).

Mapování `(from, to)` → zpráva, protože stejný cílový stav znamená různou věc:

| from | to | zpráva |
|---|---|---|
| `trial` | `past_due` | `TrialExpiredMail` (existuje) |
| jiný | `past_due` | `PaymentFailedMail` (nový) |
| libovolný | `suspended` | `ShopSuspendedMail` (existuje) |
| `past_due`/`suspended` | `active` | `ShopReactivatedMail` (nový) |
| libovolný | `pending_deletion` | `PendingDeletionMail` (nový) |
| libovolný | `deleted` | žádná — v tu chvíli už není komu psát a smazání předchází `pending_deletion` |
| `trial` | `active` | žádná — první zaplacení už potvrzuje Stripe vlastním dokladem |

Vždy `MailKind::Transactional` — nedoplatek nájemce nesmí utnout zprávu o tom, že má nedoplatek.

**Files:**
- Create: `app/Core/Tenancy/Events/TenantStatusChanged.php`
- Create: `app/Core/Billing/Listeners/SendTenantStatusMail.php`
- Create: `app/Core/Billing/Mail/PaymentFailedMail.php`
- Create: `app/Core/Billing/Mail/ShopReactivatedMail.php`
- Create: `app/Core/Billing/Mail/PendingDeletionMail.php`
- Create: `resources/views/billing/mail/payment-failed.blade.php`
- Create: `resources/views/billing/mail/shop-reactivated.blade.php`
- Create: `resources/views/billing/mail/pending-deletion.blade.php`
- Modify: `app/Models/Tenant.php` (`changeStatus` dispatchne event)
- Modify: `app/Console/Commands/SweepTenantLifecycle.php` (odstranit obě vlastní odeslání a `MailService` závislost, pokud po odstranění nezbude jiné použití)
- Modify: `app/Providers/AppServiceProvider.php` (registrace listeneru)
- Test: `tests/Feature/Tenancy/TenantStatusMailTest.php`
- Test: `tests/Feature/Billing/SweepTenantLifecycleTest.php` (upravit — sweeper už neposílá sám)

**Interfaces:**
- Produces: `App\Core\Tenancy\Events\TenantStatusChanged` — `public function __construct(public Tenant $tenant, public TenantStatus $from, public TenantStatus $to, public string $reason)`
- Consumes: `App\Core\Mail\Contracts\MailService`, `App\Core\Mail\MailKind`

- [ ] **Step 1: Napiš padající testy**

1. Superadmin `PATCH /superadmin/tenanti/{tenant}/stav` na `suspended` odešle vlastníkovi `ShopSuspendedMail` (`Mail::fake()`).
2. Stripe `invoice.payment_failed` odešle `PaymentFailedMail`, ne `TrialExpiredMail`, když tenant nebyl v trialu.
3. Sweeper trial→past_due odešle **právě jednu** zprávu (`TrialExpiredMail`), ne dvě.
4. `changeStatus()` na stejný stav (no-op) neodešle nic.
5. Event se nedispatchne, když se transakce vrátí zpět (obal `DB::transaction` s výjimkou).
6. Tenant bez vlastníka (žádný řádek `tenant_users` s rolí owner) nespadne, jen nepošle.

- [ ] **Step 2: Implementuj event a dispatch**

V `changeStatus()` za `$this->save()` a za zápis auditu:

```php
DB::afterCommit(fn () => event(new TenantStatusChanged($this, $from, $to, $reason)));
```

- [ ] **Step 3: Implementuj listener a mailables**

Listener drží mapu, vlastníka dohledá `users()->wherePivot('role', TenantRole::Owner->value)->value('email')`, běží v `runAs($tenant)` (MailService čte identitu odesílatele z tenanta). Prázdný příjemce = tichý návrat.

Šablony markdown ve stylu `resources/views/billing/mail/trial-expired.blade.php` — česky, s odkazem na `/admin/predplatne`, u pozastavení s větou, že data zůstávají a e-shop se obnoví po zaplacení.

- [ ] **Step 4: Odstraň duplicitu ve sweeperu**

- [ ] **Step 5: Ověř**

```
php artisan test tests/Feature/Tenancy --compact
php artisan test tests/Feature/Billing --compact
php artisan test tests/Feature/Platform --compact
```

- [ ] **Step 6: Commit** — `feat(tenancy): notify the shop owner whenever its status changes`

---

### Task 4: Statické stránky na `/{page-slug}`

**Problém:** Pravidlo storefrontu žádá `/{page-slug}`; modul jede na `/stranka/{slug}`, protože catch-all v kořeni by spolkl ostatní storefront routy a pořadí registrace napříč moduly nebylo vyřešené. Vlna 3.3 na tom stojí: VOP nájemce nemá skončit na `/stranka/obchodni-podminky`.

**Rozhodnutí:** `Route::fallback()`, ne catch-all `/{slug}` a ne blacklist rezervovaných segmentů. Laravel řadí fallback vždy až za všechny ostatní routy bez ohledu na pořadí registrace modulů (`ModuleRouteRegistrar` iteruje `glob()`, tedy abecedně — `pages` je před `products` i `storefront`), takže se sám přizpůsobí každé nové storefront routě. Blacklist by naopak musel někdo doplňovat při každé nové cestě a chyběl by tiše.

Fallback ale matchuje **jakoukoli** neobslouženou cestu, včetně víceúrovňových a včetně `/admin/neco`. Controller proto odmítne všechno, co obsahuje lomítko, a padne na `abort(404)` — čímž se dostane na `RedirectResponder`, který 404 obsluhuje a cachuje (vlna 3.0). Ta cesta se nesmí rozbít; je na ni test.

Gating zůstává modulový: fallback je registrovaný ve storefront skupině modulu, takže nese `module:pages`. Platform host i e-shop bez modulu dostanou 404 jako dnes.

Stará cesta `/stranka/{slug}` **zůstává** a odpovídá `301` na nový tvar. Odkazy na ni existují v e-mailech a možná v obsahu nájemců; tichým smazáním by se z nich staly 404.

**Files:**
- Modify: `Modules/Pages/routes/storefront.php`
- Modify: `Modules/Pages/Http/Controllers/PageController.php`
- Modify: `Modules/Pages/Http/Controllers/PageAdminController.php` (náhled URL v adminu, pokud tam URL skládá)
- Modify: `Modules/Storefront/Http/Controllers/SitemapController.php` (URL stránek v sitemapě)
- Modify: `Modules/Storefront/Resources/views/layouts/shop.blade.php` (odkazy v patičce, pokud tam jsou)
- Test: `tests/Feature/Pages/PageStorefrontTest.php`

**Interfaces:**
- Produces: pojmenovaná routa `storefront.pages.show` beze změny jména, nově s parametrem `{slug}` na kořeni
- Produces: `storefront.pages.legacy` — 301 z `/stranka/{slug}`

- [ ] **Step 1: Napiš padající testy**

1. Publikovaná stránka `kontakt` odpoví 200 na `/kontakt`.
2. `/stranka/kontakt` odpoví 301 na `/kontakt`.
3. Nepublikovaná stránka odpoví 404.
4. `/kosik`, `/hledani`, `/produkt/x`, `/kategorie/x`, `/registrace`, `/ucet`, `/sitemap.xml`, `/robots.txt` **nadále obsluhují své vlastní controllery** — parametrizovaný test nad seznamem cest, aby se kolize projevila hlasitě.
5. `/tohle-neexistuje` odpoví 404 **a projde `RedirectResponder`em** — přejmenovaný slug produktu dál 301uje.
6. `/admin/neexistujici-cesta` odpoví 404, ne stránkou.
7. Cesta se dvěma segmenty, kde první je slug existující stránky (`/kontakt/neco`), odpoví 404.
8. Sitemap obsahuje `/kontakt`, ne `/stranka/kontakt`.
9. Fallback nese `page-cache:content,theme` — druhý request na `/kontakt` se servíruje z cache.

- [ ] **Step 2: Implementuj routu**

```php
Route::get('/stranka/{slug}', [PageController::class, 'legacy'])->name('legacy');

Route::fallback([PageController::class, 'show'])
    ->middleware('page-cache:content,theme')
    ->name('show');
```

Komentář v souboru nahraď: dosavadní odstavec vysvětluje, proč routa **není** na kořeni — po této vlně vysvětluje, proč je to fallback a ne catch-all, a proč `catalog` v dimenzích dál chybí (stránka nerenderuje sdílený layout — pokud to Task 4 mění, musí se `catalog` doplnit současně).

- [ ] **Step 3: Implementuj controller**

```php
public function show(Request $request): View
{
    $slug = trim($request->path(), '/');

    // Fallback matches every unrouted path, including /admin/neco and
    // multi-segment ones. A page slug is a single segment; anything else
    // must fall through to the 404 handler, which is where RedirectResponder
    // answers renamed slugs.
    if ($slug === '' || str_contains($slug, '/')) {
        abort(404);
    }

    return view('pages::show', ['page' => $this->find($slug)]);
}

public function legacy(string $slug): RedirectResponse
{
    return redirect()->to('/'.$slug, 301);
}
```

`legacy()` **needotazuje databázi** — redirect platí i pro nepublikovanou stránku, kde nový tvar sám vrátí 404. Dotaz navíc by jen prozradil, které slugy existují.

- [ ] **Step 4: Přepiš zdroje URL** — sitemap, admin náhled, patička layoutu. Grepni `stranka/` napříč repem, ať nezůstane natvrdo psaná cesta.

- [ ] **Step 5: Ověř**

```
php artisan test tests/Feature/Pages --compact
php artisan test tests/Feature/Storefront --compact
php artisan test tests/Feature/PageCache --compact
```

- [ ] **Step 6: Commit** — `feat(pages): serve static pages from /{slug} with a 301 from the old path`

---

### Task 5: Uzavření vlny

- [ ] **Step 1:** Doběhni testy po adresářích (`tests/Unit`, pak `tests/Feature` po podsložkách) a zapiš skutečný počet do as-is.
- [ ] **Step 2:** `docs/as-is/2026-08-05-technicke-dluhy.md` — mapa změn, plnění, odchylky, zbylý dluh.
- [ ] **Step 3:** `docs/as-is/STATUS.md` — vyškrtnout zavřené položky ze sekce „Známá omezení".
- [ ] **Step 4:** CLAUDE.md — rozhodnutí k Tasku 1 (jediná cesta přepnutí), 3 (event, ne volající) a 4 (fallback, ne catch-all).
- [ ] **Step 5:** `VERSION` → `0.36.0`, `CHANGELOG.md`.
- [ ] **Step 6:** Merge do `main` a push.

---

## Rizika

| Riziko | Dopad | Mitigace |
|---|---|---|
| Fallback pohltí cestu, kterou dnes obsluhuje jiný modul | Storefront route přestane fungovat, tiše | Parametrizovaný test nad seznamem všech jednosegmentových cest (Task 4, test 4). Fallback se vyhodnocuje až po všech routách, takže kolize může vzniknout jen u cesty, která nikdy neexistovala. |
| Fallback rozbije `RedirectResponder` (301 z přejmenovaných slugů) | Ztráta SEO hodnoty přejmenovaných produktů | Test 5 v Tasku 4. `abort(404)` z controlleru vyhodí tutéž výjimku, na které responder visí. |
| Přesun pošty do listeneru zdvojí zprávy v cestách, které dnes posílají samy | Nájemce dostane dvě zprávy | Task 3 krok 4 odstraňuje odeslání ze sweeperu; test 3 to hlídá počtem. |
| `DB::afterCommit` mimo transakci | Event se nedispatchne | Laravel mimo transakci volá callback okamžitě — chování ověřuje test 1 (superadmin cesta netransakční). |
| Změna `runAs()` odhalí testy, které se opíraly o starou (stale) instanci | Padající sada | Task 1 se pouští první a samostatně; padající testy jsou nález, ne škoda. |
| Bump `Catalog` při každém uložení dopravy vyprázdní cache katalogu | Krátký propad hit rate | Vědomé; doprava se mění řádově jednou za měsíce. Zapsáno v rozhodnutí. |
