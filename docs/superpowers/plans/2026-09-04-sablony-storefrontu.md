# Šablony storefrontu — implementační plán

> **Pro agenta:** Použij `superpowers:subagent-driven-development` nebo `superpowers:executing-plans`. Kroky mají `- [ ]`.

**Cíl:** Nájemce si v administraci vybere šablonu storefrontu. Vedle dnešního vzhledu (`base`) vzniknou dvě: `editorial` podle bonprix.cz a `retail` podle dovido.cz.

**Architektura:** Šablona je adresář `themes/{key}/` s manifestem, tokeny a **volitelnými** přepisy vybraných pohledů. Přepis znamená soubor stejného jména položený před základ v hintech Blade namespace, takže jméno pohledu, view composery ani testy se nemění; co šablona nepřepíše, bere ze základu a oprava v jádře doputuje do všech šablon. Tokeny jdou do `<html>` jako CSS custom properties; barva značky nájemce zůstává nad nimi.

**Tech stack:** Dle `docs/PROJECT-PROFILE.md` — Laravel 13, MySQL 8, Blade SSR, Vue 3 + Inertia (administrace), Tailwind, PHPUnit, Playwright.

**Spec:** [`docs/superpowers/specs/2026-09-04-sablony-storefrontu-design.md`](../specs/2026-09-04-sablony-storefrontu-design.md)

**Stav: dokončeno 2026-09-04.** As-is včetně odchylek: [`docs/as-is/2026-09-04-sablony-storefrontu.md`](../../as-is/2026-09-04-sablony-storefrontu.md).

---

## Global Constraints

- **Storefront je Blade SSR.** Obě šablony musí mít název, cenu i popis produktu v HTML první odpovědi a musí projít nákupem bez JavaScriptu (`.claude/rules/storefront-rendering.md`).
- **Šablona nesmí prosáknout mezi nájemci.** Hinty pohledů se **nahrazují**, nikdy nepřidávají — finder je singleton a v dlouho žijícím procesu by se cesty hromadily. Ke každé etapě test, že dva požadavky za sebou dostanou každý svou šablonu.
- **Šablona nesmí přepsat pohled mimo povolený seznam.** Pokladna a účet zákazníka mají jedinou implementaci; cenová logika se nesmí větvit podle vzhledu.
- **Barvu značky určuje nájemce, ne šablona.** `--brand-primary` a `--brand-accent` plní `ThemeResolver` z `tenant_theme`, tokeny šablony je nedefinují.
- **Hex do CSS jen přes `ThemeResolver::sanitizeHex()`.** Tokeny šablony jdou do stejného `<style>` bloku, takže potřebují stejnou obranu — Blade `{{ }}` neutralizuje HTML, ne CSS syntaxi.
- **Výchozí zůstává dnešní vzhled.** Nájemce, který nikdy nic nenastavil, nesmí po nasazení vidět změnu.
- **Žádná aktiva z referenčních webů.** Předlohou je rozvržení a hustota, ne obrázky, texty ani ochranné známky.
- **Před commitem PHP:** `./vendor/bin/pint` na dotčené soubory.
- **Testy pouštěj po adresářích, ve foregroundu.** Plná sada jedním příkazem přeteče timeout a sdílená MySQL testovací databáze kolabuje.
- **Po každé změně Blade nebo CSS:** `npm run build` (Tailwind musí vidět nový `content` glob).

---

## Struktura souborů

**Jádro**

| soubor | odpovědnost |
|---|---|
| `app/Core/Theme/ThemeManifest.php` | neměnný manifest šablony |
| `app/Core/Theme/ThemeRegistry.php` | čtení `themes/*/theme.json`, validace, cache, fallback na `base` |
| `app/Core/Theme/ThemeViewPaths.php` | složení a **nahrazení** hintů pohledů pro danou šablonu |
| `app/Core/Theme/Exceptions/InvalidThemeManifest.php` | |
| `app/Core/Theme/ThemeData.php` | rozšíření o `key` a `tokens` |
| `app/Core/Theme/ThemeResolver.php` | doplní klíč a tokeny z registru |
| `app/Http/Middleware/ApplyTenantTheme.php` | aplikuje hinty na začátku požadavku storefrontu |
| `database/migrations/…_add_template_to_tenant_theme.php` | sloupec `template` |
| `app/Http/Controllers/Tenant/AppearanceController.php` | výběr šablony |
| `app/Http/Requests/Tenant/UpdateAppearanceRequest.php` | validace proti registru |
| `resources/js/Pages/Tenant/Appearance.vue` | dlaždice s náhledy |

**Šablony**

| soubor | |
|---|---|
| `themes/base/theme.json` | dnešní vzhled, žádné přepisy |
| `themes/editorial/theme.json`, `themes/editorial/views/**`, `themes/editorial/preview.webp` | |
| `themes/retail/theme.json`, `themes/retail/views/**`, `themes/retail/preview.webp` | |
| `resources/css/themes/editorial.css`, `retail.css` | `@font-face` a specifika |
| `public/fonts/{key}/…` | self-hostovaná písma |

**Testy**

| soubor | |
|---|---|
| `tests/Feature/Theme/ThemeRegistryTest.php` | manifest, validace, fallback |
| `tests/Feature/Theme/ThemeViewResolutionTest.php` | přepis, fallback, izolace mezi nájemci |
| `tests/Feature/Theme/ThemeSelectionTest.php` | administrace, oprávnění, cache |
| `tests/Feature/Theme/ThemeStorefrontContractTest.php` | stejná SEO a SSR kritéria pro každou šablonu |
| `e2e/theme-editorial.spec.ts`, `e2e/theme-retail.spec.ts` | nákup bez JS |

---

## Etapa 1 — jádro šablon

Na konci etapy existuje mechanika a jedna šablona `base`, která nic nepřepisuje. Storefront vypadá přesně jako dnes.

### Task 1: Manifest a registr

**Files:**
- Create: `app/Core/Theme/ThemeManifest.php`, `ThemeRegistry.php`, `Exceptions/InvalidThemeManifest.php`, `themes/base/theme.json`
- Test: `tests/Feature/Theme/ThemeRegistryTest.php`

**Interfaces:**
- Produces: `ThemeRegistry::all(): Collection<string, ThemeManifest>`, `ThemeRegistry::find(?string $key): ThemeManifest` (neznámý klíč → `base` + `Log::warning`), `ThemeManifest::tokens(): array<string, string>`, `ThemeManifest::overrides(): list<string>`

- [ ] **Step 1: Napiš padající test**

Pokrytí:
- registr najde `base` a vrátí jeho tokeny,
- neznámý klíč vrátí `base` a **nevyhodí** výjimku (zákazník nesmí dostat 500, protože někdo smazal adresář),
- manifest s neznámým tokenem vyhodí `InvalidThemeManifest`,
- manifest s přepisem pohledu mimo povolený seznam vyhodí `InvalidThemeManifest`,
- registr je cachovaný a `flush()` cache zahodí.

Manifest do testu se zapisuje do dočasného adresáře (`Storage::fake` nepomůže, registr čte z disku — použij `base_path()` přes konfiguraci `config('themes.path')`, aby test mohl cestu podstrčit).

- [ ] **Step 2: Spusť test a ověř, že padá**

Run: `php artisan test tests/Feature/Theme/ThemeRegistryTest.php`

- [ ] **Step 3: Napiš `ThemeManifest`**

`final readonly class` s `key`, `name`, `description`, `previewPath`, `tokens`, `overrides`, `cssEntry`. Statická `fromArray()` jako u `App\Core\Modules\Manifest`.

- [ ] **Step 4: Napiš seznam povolených tokenů a pohledů**

Konstanty na `ThemeManifest`: `ALLOWED_TOKENS` (viz spec) a `OVERRIDABLE_VIEWS`. Validace patří sem, ne do registru — manifest, který se nedá vytvořit, se nedostane nikam dál.

> **Proč je seznam pohledů uzavřený:** pokladna a účet zákazníka mají jedinou implementaci, protože progressive enhancement a cenová logika se nesmí větvit podle vzhledu. Bez uzavřeného seznamu stačí, aby autor šablony položil vedle základu `checkout/cart.blade.php`, a od té chvíle existují dva košíky, z nichž jeden nikdo netestuje.

- [ ] **Step 5: Napiš `ThemeRegistry`**

Čte `config('themes.path')`, validuje každý manifest, výsledek drží v `Cache::remember`. `find()` na neznámý klíč loguje a vrací `base`.

- [ ] **Step 6: Napiš `themes/base/theme.json`**

Tokeny odpovídají dnešnímu vzhledu (`container: 1152px`, `radius: 0.75rem`, `card: elevated`, systémový font), `overrides: []`.

- [ ] **Step 7: Spusť testy, pint, commit**

```bash
php artisan test tests/Feature/Theme/
./vendor/bin/pint app/Core/Theme tests/Feature/Theme
git commit -m "feat(theme): read storefront themes from a validated manifest"
```

---

### Task 2: Rozlišování pohledů

**Files:**
- Create: `app/Core/Theme/ThemeViewPaths.php`, `app/Http/Middleware/ApplyTenantTheme.php`
- Modify: `bootstrap/app.php` (registrace middleware do storefront skupiny)
- Test: `tests/Feature/Theme/ThemeViewResolutionTest.php`

**Interfaces:**
- Consumes: `ThemeRegistry::find()`, `TenantContext::current()`
- Produces: `ThemeViewPaths::apply(ThemeManifest $theme): void`

- [ ] **Step 1: Napiš padající test**

Pokrytí:
1. Se šablonou, která přepisuje `storefront::components.product-card`, vrátí `view()->getFinder()->find('storefront::components.product-card')` cestu v `themes/…`.
2. Pohled, který šablona nepřepisuje, se stále najde v `Modules/…`.
3. **Izolace:** požadavek nájemce A (`editorial`) a hned po něm požadavek nájemce B (`retail`) na stejnou routu vrátí každý svou šablonu. Test musí sáhnout na finder mezi požadavky a ověřit, že hinty **neobsahují** cestu předchozí šablony — jinak by prošel i kód, který cesty jen přidává a náhodou dá správné pořadí.
4. Nájemce bez řádku v `tenant_theme` renderuje základ.

- [ ] **Step 2: Spusť test a ověř, že padá**

- [ ] **Step 3: Napiš `ThemeViewPaths`**

Při bootu si zapamatuje **základní** hinty (`View::getFinder()->getHints()`), aby je uměl kdykoli obnovit. `apply()` pro každý namespace složí `[themePath/{namespace}, ...baseHints[namespace]]` a zavolá `View::replaceNamespace()`. Pro šablonu bez přepisů obnoví základ.

> Cesty se skládají pro **všechny** namespace, ne jen pro ty, které šablona přepisuje: adresář `themes/editorial/views/products/` má vzniknout tehdy, když ho autor šablony založí, ne až někdo doplní mapování. Uzavřený seznam v manifestu hlídá, co se smí přepsat; hinty jsou jen mechanika.

Po `replaceNamespace()` zavolej `View::getFinder()->flush()` — finder si v rámci požadavku pamatuje už nalezené pohledy a bez vyprázdnění by druhý nájemce dostal cestu prvního.

- [ ] **Step 4: Napiš middleware `ApplyTenantTheme`**

Běží po middleware, který určuje nájemce, a před renderem. Zaregistruj do skupiny, kterou používá storefront (`bootstrap/app.php`), **ne** globálně — administrace šablonu nájemce nesmí použít, jinak si Inertia stránky sáhnou do cizích pohledů.

- [ ] **Step 5: Spusť testy, pint, commit**

```
feat(theme): resolve storefront views through the tenant's theme
```

---

### Task 3: Tokeny v layoutu

**Files:**
- Modify: `app/Core/Theme/ThemeData.php`, `ThemeResolver.php`, `Modules/Storefront/Resources/views/layouts/shop.blade.php`, `resources/css/storefront.css`, `tailwind.config.js`
- Test: rozšíření `tests/Feature/Theme/ThemeViewResolutionTest.php` + existující testy layoutu

- [ ] **Step 1: Napiš padající test**

- HTML nese `--container`, `--radius` a další tokeny vybrané šablony.
- Barva značky nájemce token **nepřebije**: nájemce s `#ff0000` má `--brand-primary: #ff0000` i v šabloně, jejíž tokeny mluví o jiné barvě.
- Token s hodnotou `red; } body{display:none}` se do HTML nedostane (stejná obrana jako `sanitizeHex`).

- [ ] **Step 2: Rozšiř `ThemeData` a `ThemeResolver`**

`ThemeData` dostane `key` a `tokens`. Resolver plní tokeny z `ThemeRegistry::find($theme?->template)`.

Sanitizace: číselné a rozměrové tokeny proti whitelistu vzorů, `font-*` proti seznamu povolených rodin, barvy přes `sanitizeHex`. Neznámá hodnota → hodnota z `base`. Šablony dodává platforma, takže tohle není obrana proti nájemci — je to obrana proti překlepu v manifestu, který by jinak tiše rozbil každou stránku obchodu.

- [ ] **Step 3: Rozšiř `<style>` blok v layoutu**

`:root` dostane tokeny nad dosavadními třemi proměnnými. Pořadí: tokeny šablony první, barvy nájemce po nich.

- [ ] **Step 4: Přepiš komponentní třídy na tokeny**

`.btn`, `.card`, `.field-input`, `.badge` v `resources/css/storefront.css` mají brát `var(--radius)`, `var(--line)`, `var(--surface)` místo natvrdo psaných slate odstínů. Bez toho by každá šablona musela forkovat každý pohled jen kvůli rohům.

- [ ] **Step 5: Doplň Tailwind `content` a `theme.extend`**

`./themes/**/views/**/*.blade.php` do `content`. Do `extend.colors` a `extend.borderRadius` přidej tokeny jako `var(--…)`, aby šly psát jako utility (`bg-surface`, `rounded-token`).

- [ ] **Step 6: Spusť testy a build**

```bash
php artisan test tests/Feature/Theme/ tests/Feature/Storefront/
npm run build
```

- [ ] **Step 7: Pint a commit**

```
feat(theme): drive storefront chrome from theme tokens
```

---

### Task 4: Výběr šablony v administraci

**Files:**
- Create: `database/migrations/…_add_template_to_tenant_theme.php`
- Modify: `app/Http/Controllers/Tenant/AppearanceController.php`, `app/Http/Requests/Tenant/UpdateAppearanceRequest.php`, `resources/js/Pages/Tenant/Appearance.vue`, `app/Core/Export/TenantTableRegistry.php` (pokud vypisuje sloupce `tenant_theme`)
- Test: `tests/Feature/Theme/ThemeSelectionTest.php`

**Role:** TENANT_ADMIN, tarif **base** — obrazovka Vzhled už je za `tenant.member`, žádné nové oprávnění nevzniká. Superadmin nic nenastavuje. Storefront je veřejný.

- [ ] **Step 1: Napiš padající test**

- Nájemce uloží `template=editorial`, v databázi je `editorial`.
- Neznámý klíč validace odmítne (422), v databázi zůstane původní hodnota.
- Uložení šablony **zvýší** `Dimension::Theme` generaci nájemce, takže cachované stránky padnou.
- Nájemce A nemůže uložit šablonu nájemci B (obrazovka čte `TenantContext`, ne id z requestu — pokrýt testem, ať to tak zůstane).
- `edit()` vrací seznam šablon s klíčem, názvem, popisem a cestou k náhledu.

- [ ] **Step 2: Napiš migraci**

`tenant_theme.template` — `string(32)`, default `'base'`, `nullable(false)`. Default v migraci, ne v modelu: stávající řádky musí po migraci vypadat jako dnes.

- [ ] **Step 3: Doplň Form Request**

`Rule::in(app(ThemeRegistry::class)->all()->keys())` — proti registru, ne proti seznamu v kódu, jinak přidání šablony znamená úpravu na dvou místech a to druhé se zapomene.

- [ ] **Step 4: Doplň controller**

`template` do whitelistu v `update()`, seznam šablon do props v `edit()`. Bump generace řeší `PageCacheObserver` na modelu `TenantTheme` — ověř to testem ze Step 1, nepiš bump ručně.

- [ ] **Step 5: Doplň `Appearance.vue`**

Radio group dlaždic s náhledem, názvem a popisem (ne `<select>` — je to vizuální volba). `role="radiogroup"`, ovladatelné šipkami, viditelný fokus, vybraná dlaždice označená i jinak než barvou.

- [ ] **Step 6: Spusť testy a build**

- [ ] **Step 7: Pint a commit**

```
feat(theme): let a tenant pick a storefront template
```

---

### Task 5: Smluvní test pro každou šablonu

**Files:**
- Create: `tests/Feature/Theme/ThemeStorefrontContractTest.php`

Tenhle test je důvod, proč se dají šablony přidávat bez strachu. Běží jako data provider přes **všechny** klíče z registru a na každou šablonu pouští stejná kritéria.

- [ ] **Step 1: Napiš test**

Pro každou šablonu, na seedovaném obchodě:
- detail produktu: název, cena a popis jsou v surovém HTML (`assertSee` bez `escape: false` triků, ne přes JSON),
- canonical je absolutní a míří na doménu nájemce,
- JSON-LD obsahuje `Product`, `Offer` a `BreadcrumbList`,
- výpis kategorie: dlaždice produktu nese název i cenu,
- homepage: `Organization`/`WebSite`,
- košík a pokladna se vyrenderují (společné pohledy pod layoutem šablony),
- 404 stránka se vyrenderuje.

- [ ] **Step 2: Spusť a commit**

```
test(theme): hold every theme to the same storefront contract
```

> Do konce etapy 1 test běží jen nad `base`. Etapy 2 a 3 do něj přidávají šablony tím, že vzniknou — žádná změna testu.

---

## Etapa 2 — šablona `editorial` (podle bonprix.cz)

### Task 6: Kostra, tokeny a písma

**Files:**
- Create: `themes/editorial/theme.json`, `resources/css/themes/editorial.css`, `public/fonts/editorial/*`
- Modify: `vite.config.js` (nový vstup)

- [ ] **Step 1: Vyber a stáhni písmo**

Bezpatkový grotesk s dobrou diakritikou (Inter, Archivo, Manrope — vše OFL). **Self-hostované**, dva řezy (400, 600), latin-ext podmnožina. `font-display: swap`, `<link rel=preload>` na řez pro nadpisy.

- [ ] **Step 2: Napiš `theme.json`**

`container: 1280px`, `radius: 0`, `button-radius: 0`, `card: plain`, `heading-transform: uppercase`, `heading-tracking: 0.08em`, `section-gap: 0` (sekce na sebe navazují), neutrální šedé plochy.

- [ ] **Step 3: Ověř, že smluvní test prošel i pro `editorial`**

Run: `php artisan test tests/Feature/Theme/ThemeStorefrontContractTest.php`

V téhle chvíli šablona nic nepřepisuje — dostane dnešní rozvržení v jiné typografii. To je záměrná mezistanice: kdyby test spadl teď, chyba je v tokenech, ne v šabloně.

- [ ] **Step 4: Commit**

```
feat(theme): add the editorial theme's tokens and typography
```

### Task 7: Layout — hlavička, navigace, patička

**Files:**
- Create: `themes/editorial/views/storefront/layouts/shop.blade.php`

- [ ] **Step 1: Zkopíruj základ a přestav hlavičku**

Tři pásy: promo lišta (renderuje se **jen** když má nájemce vyplněný text — viz otevřená otázka ve spec), utility lišta s odkazy z patičky, hlavní řádek logo + vodorovná navigace verzálkami + hledání + ikony.

- [ ] **Step 2: Zachovej všechno, co layout dnes dělá**

Kontrolní seznam, protože kopie layoutu je nejsnazší místo, kde se něco ztratí:
`skip link`, `data-consent-version`, inline skript skrývající cookie lištu, `x-storefront::seo-meta`, `@stack('head')`, `@vite`, mini-košík jako prázdný placeholder (**nikdy** počet položek v cachovaném HTML), odkaz na nastavení cookies v patičce, `@include('analytics::tracking')`, `@stack('tracking')`, `<x-consent-banner />`.

- [ ] **Step 3: Napiš test na regresi placeholderu košíku**

Cachovaná stránka nesmí nést počet položek ani jméno přihlášeného zákazníka. Test patří k šabloně, ne jen k základu — je to přesně ta chyba, kterou kopie layoutu udělá.

- [ ] **Step 4: Spusť testy, build, commit**

### Task 8: Karta produktu, mřížka, homepage

**Files:**
- Create: `themes/editorial/views/storefront/components/product-card.blade.php`, `product-grid.blade.php`, `themes/editorial/views/storefront/home.blade.php`, `themes/editorial/views/storefront/components/blocks/*.blade.php`

- [ ] **Step 1: Karta bez chromu**

Obrázek na celou šířku dlaždice, bez rámečku a stínu. Název drobně pod obrázkem, cena tučně. Slevová cena barevně **a zároveň** s textovou informací (`Sleva`), ne barvou samotnou — WCAG 1.4.1. Přeškrtnutá původní cena musí zůstat čitelná screen readerem (`<s>` + `<span class="sr-only">původní cena</span>`).

- [ ] **Step 2: Bloky homepage přes celou šířku**

`hero` jako dvousloupec obrázek/panel, `banner` full-bleed, `product-row` jako pás dlaždic bez mezer, `category-grid` s popiskem pod obrázkem.

- [ ] **Step 3: Hvězdy z modulu `reviews`**

Karta zobrazí souhrn hodnocení přes `App\Core\Reviews\Contracts\ReviewAggregates`. S vypnutým modulem se nezobrazí nic — null binding to zařídí, ale ověř testem, ať šablona nespadne na obchodě bez recenzí.

- [ ] **Step 4: Spusť testy, build, commit**

### Task 9: Výpis kategorie

**Files:**
- Create: `themes/editorial/views/categories/storefront/show.blade.php`

- [ ] **Step 1: Levý sloupec, centrovaný nadpis, pravá zásuvka filtrů**

Filtry a řazení v `<details>`/`<summary>` — bez JS zůstanou rozbalitelné, s JS z nich ostrůvek udělá zásuvku. **Server-side fallback přes query parametry je povinný** (`.claude/rules/storefront-rendering.md`).

- [ ] **Step 2: Test bez JS**

Odeslání formuláře filtrů čistým HTTP GET zúží výpis. Test na úrovni PHPUnit, E2E až v etapě 4.

- [ ] **Step 3: Stránkování**

`rel=prev/next` nebo canonical strategie musí zůstat stejná jako v základu — SEO se šablonou neměří jinak.

- [ ] **Step 4: Spusť testy, build, commit**

### Task 10: Detail produktu

**Files:**
- Create: `themes/editorial/views/products/storefront/show.blade.php`

- [ ] **Step 1: Galerie se svislou lištou náhledů**

Náhledy jsou odkazy s `?photo=` fallbackem, hydratace je jen zrychlení. Hlavní obrázek má `fetchpriority="high"` a rozměry, aby nedošlo k posunu layoutu.

- [ ] **Step 2: Nákupní sloupec**

Cena, sleva, **nejnižší cena za 30 dní** (Omnibus — je v základu, nesmí vypadnout), varianty, dostupnost, tlačítko do košíku jako `<form method="post">`.

- [ ] **Step 3: Recenze a JSON-LD**

Souhrn a seznam recenzí; `AggregateRating` v JSON-LD jen když recenze existují.

- [ ] **Step 4: Spusť smluvní test, build, commit**

```
feat(theme): lay out the editorial product page
```

---

## Etapa 3 — šablona `retail` (podle dovido.cz)

Stejná posloupnost jako etapa 2, jiné rozvržení. Kroky se neopakují dopodrobna — platí kontrolní seznamy z Tasku 7 a 10.

### Task 11: Kostra, tokeny a písma

`container: 1120px`, `radius: 0.75rem`, `button-radius: 0.5rem`, `card: bordered`, `heading-transform: none`, `heading-weight: 700`, teplé neutrální plochy. Humanistický bezpatkový (Source Sans 3, Public Sans — OFL), self-hostovaný.

- [ ] Ověř smluvní test, commit.

### Task 12: Layout

Dva pásy: kontakt a otevírací doba + hledání + ikony; pod tím navigace kategorií. **Projdi kontrolní seznam z Tasku 7 Step 2** — všechno, co layout dnes dělá, musí zůstat.

Pás výhod (doprava zdarma od…, rychlé dodání, …) se renderuje jen z vyplněných hodnot nastavení obchodu; prázdné pole = pás se nezobrazí.

### Task 13: Karta produktu a homepage

Ohraničená zaoblená karta: badge stavu, hvězdy, dostupnost, cena, sekundární tlačítko na detail. Druhá varianta vodorovné karty pro řady na homepage. Tlačítko „Detail" je odkaz, ne `button` — je to navigace.

### Task 14: Výpis kategorie

Chipy podkategorií s náhledy, **vodorovná** lišta filtrů (`<details>` v řadě), stránkování nahoře i dole. Přepínač „Akce" je odkaz s query parametrem, ne JS toggle.

### Task 15: Detail produktu

Dvousloupec, varianty jako chipy (radio inputy stylované jako chipy — klávesnice musí fungovat), stepper množství (`<input type=number>` + tlačítka jako progressive enhancement), dlaždice výhod, kotvy na sekce.

Sticky lišta s cenou a tlačítkem je **ostrůvek** nad hotovým HTML; bez JS stránka funguje bez ní.

---

## Etapa 4 — uzavření

### Task 16: E2E a přístupnost

**Files:**
- Create: `e2e/theme-editorial.spec.ts`, `e2e/theme-retail.spec.ts`

- [ ] **Step 1: Nákup bez JavaScriptu**

Playwright s `javaScriptEnabled: false`: katalog → detail → do košíku → pokladna → objednávka. Pro každou šablonu.

- [ ] **Step 2: Axe na tři stránky každé šablony**

Homepage, výpis, detail. Nula porušení úrovně A a AA.

- [ ] **Step 3: Agent `a11y-checker`**

Pusť ho na `themes/editorial/views` a `themes/retail/views`. Nálezy oprav, nebo zapiš do `security_warnings.md`, pokud jde o vědomé rozhodnutí.

- [ ] **Step 4: Kontrast tokenů**

Test, že každá dvojice `ink`/`surface` a `ink-muted`/`surface-muted` v manifestu splňuje 4,5:1. Kontrast už umí `App\Core\Theme\Contrast`.

### Task 17: Dokumentace a rozhodnutí

- [ ] `docs/as-is/2026-09-04-sablony-storefrontu.md` — mapa změn, plnění spec, odchylky, technický dluh.
- [ ] `docs/decisions/05-storefront-a-seo.md` — proč přepisy pohledů místo forku a proč je seznam přepisovatelných pohledů uzavřený.
- [ ] `docs/decisions/08-admin-a-ui.md` — proč dlaždice místo selectu.
- [ ] `docs/as-is/STATUS.md` — stav oblasti.
- [ ] `CLAUDE.md` → sekce „Před spuštěním": `php artisan migrate` (sloupec `template`) + `npm run build`; po nasazení ověřit, že stávající nájemci mají `base`.
- [ ] Verze: minor bump přes skill `versioning`.

---

## Strategie testů

| úroveň | co hlídá |
|---|---|
| `ThemeRegistryTest` | manifest, validace, fallback, cache |
| `ThemeViewResolutionTest` | přepis, fallback, **izolace mezi nájemci v jednom procesu** |
| `ThemeSelectionTest` | administrace, validace proti registru, bump page cache |
| `ThemeStorefrontContractTest` | SSR, SEO a JSON-LD — stejná kritéria pro každou šablonu, data provider přes registr |
| existující `tests/Feature/Storefront/` | nesmí spadnout; základ se chová jako dosud |
| Playwright | nákup bez JS v obou šablonách |
| axe | A + AA na třech stránkách každé šablony |

---

## Rizika a mitigace

| riziko | mitigace |
|---|---|
| **Šablona prosákne mezi nájemce** — hinty se hromadí ve sdíleném finderu. | Hinty se **nahrazují** a finder se vyprázdní; test dvou požadavků za sebou kontroluje i **obsah** hintů, ne jen výsledek. |
| **Kopie layoutu ztratí cookie lištu, měřicí kódy nebo skip link.** | Kontrolní seznam v Tasku 7 Step 2 + test na placeholder košíku. |
| **Cachovaná stránka s osobním obsahem.** | Mini-košík zůstává placeholderem ve všech šablonách; test to hlídá. |
| **Tailwind vyhodí třídy, které používá jen jedna šablona.** | `content` glob přes `themes/**`; `npm run build` je krok každého tasku, ne až na konci. |
| **Rozjezd základu a šablon při budoucí opravě.** | Šablona přepisuje jen to, co musí; uzavřený seznam přepisů drží povrch malý. Smluvní test běží nad všemi šablonami, takže oprava v základu, kterou šablona přebila, spadne. |
| **Písma zpomalí LCP.** | Self-hostováno, dva řezy, latin-ext podmnožina, `swap`, preload jednoho řezu. Lighthouse ≥ 90 zůstává kritériem. |
| **Nájemce si vybere šablonu a rozbije si kontrast vlastní barvou značky.** | `Contrast::textOn()` už počítá čitelný text nad primární barvou; obrazovka Vzhled ukazuje poměr. Rozšířit varování i na tokeny šablony. |
| **Neznámý klíč šablony po odstranění adresáře.** | `ThemeRegistry::find()` loguje a vrací `base`; test to pokrývá. |

---

## Co tenhle plán vědomě nedělá

- Nedělá editor šablon ani vlastní CSS od nájemce.
- Nedělá vlastní pokladnu na šablonu — pokladna dostane layout a tokeny, pohledy zůstávají společné.
- Nekopíruje aktiva referenčních webů. Předlohou je rozvržení a hustota.
