# As-is: E2E testy v prohlížeči (Playwright) — vlna 3.4

Datum: 2026-08-06 · Verze: **0.39.0** (minor otevírá vlnu 3.4) · Větev: `feature/vlna-34-e2e-playwright`

Spec: [`docs/superpowers/specs/2026-08-05-vlna-34-e2e-playwright-design.md`](../superpowers/specs/2026-08-05-vlna-34-e2e-playwright-design.md)
Plán: [`docs/superpowers/plans/2026-08-05-vlna-34-e2e-playwright.md`](../superpowers/plans/2026-08-05-vlna-34-e2e-playwright.md)

## Co vlna přinesla

Projekt měl 2003 zelených testů a **ani jeden nespouštěl JavaScript**. Tři závazné vlastnosti tím zůstávaly neověřené: že měřicí skripty respektují souhlas (vlna 3.3), že checkout funguje bez JS (AK §16.3) a že storefront splňuje WCAG 2.2 AA.

**22 E2E testů** v `e2e/`, `npm run e2e`, vlastní CI job.

## Mapa změn

18 souborů, +1289/−2 (`git diff --stat 254d84e..HEAD`).

| Soubor | Změna |
|--------|-------|
| `package.json` | `@playwright/test`, `@axe-core/playwright`, skripty `e2e` a `e2e:ui` |
| `e2e/playwright.config.ts` | dva projekty (`chromium`, `no-js`), `webServer` na portu 8001 |
| `e2e/support/global-setup.ts` | vlastní databáze `droidshop_e2e`, `migrate:fresh` + `DemoShopSeeder` |
| `e2e/support/shop.ts` | URL helpery, `enableModule`, `setSettings`, `setConsent`, `seedVariantProduct` |
| `e2e/support/tracking.ts` | odchytávání a blokování požadavků na měřicí vendory |
| `e2e/tests/consent.spec.ts` | **7 scénářů** souhlasu a měření |
| `e2e/tests/checkout-no-js.spec.ts` | **2 scénáře** bez JavaScriptu |
| `e2e/tests/checkout.spec.ts` | **3 scénáře** s JavaScriptem |
| `e2e/tests/accessibility.spec.ts` | **7 auditů** axe |
| `e2e/tests/axe-sanity.spec.ts` | důkaz, že audit umí selhat |
| `e2e/tests/smoke.spec.ts` | **2** — server běží, seed proběhl, storefront renderuje |
| `e2e/README.md` | jak spustit, proč žádná zvláštní doména, pravidla proti hnilobě |
| `.github/workflows/ci.yml` | job `e2e` s cache prohlížečů a reportem při selhání |
| `.gitignore` | artefakty |

## Dvě premisy vlny, které neplatily

**„Playwright blokuje omezení certifikátu."** V `STATUS.md` to viselo od vlny 1.x. Týká se `curl` přes **HTTPS**; sada mluví prostým HTTP proti `artisan serve`, kde žádné TLS není.

**„Chromium nezvládne doménu bez TLD, potřebujeme `droidshop.test`."** Plán na tom stál a chtěl sudo-editovaný `/etc/hosts` na každém stroji. **Ověřeno, že to není potřeba:** u explicitní `http://` URL s portem Chromium doménu neupgraduje ani neposílá do vyhledávače. Sada jede na `obchod.droidshop`, tedy na téže doméně jako demo i PHPUnit sada, a nepotřebuje žádný nový záznam. `E2E_HOST` zůstává jako úniková cesta.

Task 2 z plánu tím prakticky odpadl a zbyla z něj oprava dvou vět v dokumentaci.

## Plnění akceptačních kritérií

| # | Kritérium | Stav |
|---|---|---|
| 1 | `npm run e2e` projde lokálně | splněno, 22/22 |
| 2 | Scénář souhlasu **umí selhat** | splněno, ověřeno porušením gate |
| 3 | Nákup bez JS projde a založí objednávku | splněno |
| 4 | Axe bez `critical`/`serious` | splněno, 7 stránek čistých |
| 5 | CI job blokuje merge | splněno |
| 6 | Tři běhy po sobě stejný výsledek | splněno, 22/22 × 3 (38–40 s) |

## Nálezy

### Který test je nosný

Gate v `tracking.blade.php` byl dočasně porušen (`allows('analytics')` odstraněno), aby se ověřilo, že sada zčervená. Zčervenala — ale **jiné testy, než plán předpokládal**:

- ✅ „refusing keeps every vendor silent" — **chytil**
- ✅ „the decision can be changed from the settings screen" — **chytil**
- ❌ „nothing reaches a vendor before the visitor decides" — zůstal zelený

Bez zaznamenaného rozhodnutí se snippet nespustí vůbec, takže gate uvnitř nemá šanci být špatně. Nosné jsou scénáře **odmítnutí**, ne scénář „před rozhodnutím". Poznamenáno přímo u testu — vědět, který test drží záruku, je součástí té záruky.

### Bez JS je checkout o krok delší

Platební metody se zobrazí až po odeslání dopravy, protože nabídka závisí na zvoleném dopravci (matice doprava×platba). **Není to chyba** — je to funkční checkout o jeden round trip navíc. Do té doby to nikdo neviděl, protože PHP testy posílají oba údaje najednou.

### Lišta cookies bez JS nezmizí

Očekávané chování z 3.3 (lišta je v cachovaném HTML, skrývá ji skript), ale první verze testu to považovala za chybu. Test teď ověřuje, že se **rozhodnutí uložilo**, ne že lišta zmizela. Bez JS stejně neběží žádné měření, takže lišta nic neblokuje.

### Demo nemá varianty

`DemoShopSeeder` neseeduje jedinou variantu, takže scénář ostrůvku variant se přeskakoval. Test si produkt s osou Velikost zakládá sám — scénář, který se přeskočí, když chybí fixture, je scénář, o kterém nikdo nepozná, že přestal běžet.

### Storefront je přístupnostně čistý

Axe nenašel **žádné** porušení `critical` ani `serious` na sedmi stránkách včetně lišty cookies a produktu s variantami. Aby to nebyla iluze, `axe-sanity.spec.ts` podstrčí obrázek bez `alt` a trvá na tom, že ho audit chytí (41 vyhodnocených pravidel).

## Rozhodnutí

- **Vlastní databáze `droidshop_e2e`.** `migrate:fresh` na lokální databázi by smazal data, která si vlastník proklikal v demu. Sada, která kvůli svému běhu maže cizí data, je horší než žádná. Zakládá si ji sama.
- **Port 8001**, ne demo 8000 — sada nesmí záviset na tom, jestli běží server, ani ho zabít.
- **Page cache vypnutá** (`PAGE_CACHE_ENABLED=false`). Jinak by scénář dostal HTML uložené předchozím a první červený test by byl nevysvětlitelný. Cache má vlastních 108 PHPUnit testů.
- **Vendor požadavky se odchytávají, nepouštějí ven.** Testuje se **pokus** o požadavek — přesně to, co ePrivacy řeší — a CI nesmí záviset na dostupnosti Googlu.
- **Axe blokuje jen `critical`/`serious`.** Sada, která zčervená na neopravovaném nálezu, se začne přeskakovat, a pak se přeskočí i nález, který mattered.

## Testy

**E2E: 22 testů, 3 běhy po sobě zelené** (38–40 s).
**PHPUnit: beze změny** — dotčené adresáře (`Consent`, `Legal`, `Core`, `Analytics`, `Checkout`, `Storefront`, `PageCache`, `Unit`) 679 zelených.

## Technický dluh

- **Pokrytí je úzké.** Tři cesty a sedm auditů proti dvaceti obrazovkám storefrontu. Admin není pokrytý vůbec (vědomě mimo rozsah).
- **Jeden prohlížeč.** Safari a Firefox mají vlastní chování u cookies třetích stran; sada je neuvidí.
- **Mini-košík nepokrytý** — ostrůvek `GET /api/kosik/souhrn` scénář neověřuje.
- **`seedVariantProduct` obchází UI** a volá `VariantWriter` přes tinker. Rychlé a spolehlivé, ale netestuje admin cestu k vytvoření varianty.
- **Sada je pomalá při prvním běhu** (~20 s jen seed). V CI to je celý job navíc.

## Pre-deploy checklist

Nic. Vlna nemění produkční kód — jen testy, konfiguraci sady a CI.
