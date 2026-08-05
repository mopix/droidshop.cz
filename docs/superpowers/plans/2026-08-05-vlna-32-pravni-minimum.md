# Vlna 3.2 — právní minimum platformy — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Platforma má VOP, zásady zpracování údajů a zpracovatelskou smlouvu jako dostupné dokumenty; nájemce je při registraci prokazatelně odsouhlasí; nový e-shop dostane vzory svých vlastních právních stránek místo prázdných.

**Architecture:** Texty jsou Markdown v `docs/legal/` a renderují se přes Blade z jednoho controlleru pod prefixem `/pravni/` gatovaným na platform host. Souhlas je dvojice sloupců na `users` zapisovaná při registraci. Vzory stránek nájemce jdou do `Modules\Pages\Lifecycle`, který už dnes tři prázdné stránky seeduje.

**Tech Stack:** Laravel 13, Blade SSR, PHPUnit. Žádná nová závislost — Markdown se needituje za běhu, texty se přepisují do Blade šablon při implementaci.

**Spec:** [`docs/superpowers/specs/2026-08-05-vlna-32-pravni-minimum-design.md`](../specs/2026-08-05-vlna-32-pravni-minimum-design.md)

## Global Constraints

- **Žádná nová závislost.** `composer.json` ani `package.json` se nemění. Markdown parser se nepřidává — `docs/legal/*.md` je zdroj pravdy pro člověka, Blade šablona je to, co se servíruje.
- **Kód anglicky** (názvy, komentáře, commity), **právní texty a uživatelské řetězce česky**.
- **PHP 8.3**, žádné 8.4 konstrukce.
- **`./vendor/bin/pint`** na dotčené soubory před každým commitem.
- **Prefix `/pravni/` je závazný.** Jednosegmentová platformní cesta by na hostu nájemce zastínila jeho stránku — viz spec, sekce Omezení. Test to hlídá.
- **Identifikační údaje se nikdy nepíšou natvrdo do textu** — čtou se z `config('billing.company')`, takže změna sídla nebo IČO neznamená editaci čtyř dokumentů.
- **Testy pouštěj po adresářích**, ne jednou dávkou.

---

### Task 1: Sloupce souhlasu na `users` + konfigurace verze

**Files:**
- Create: `database/migrations/2026_08_05_100000_add_terms_acceptance_to_users_table.php`
- Modify: `config/platform.php` (nebo `config/legal.php`, pokud `platform.php` nese jen doménová nastavení — rozhodni podle obsahu)
- Modify: `app/Models/User.php` (cast `terms_accepted_at` na `datetime`)
- Test: `tests/Feature/Legal/TermsAcceptanceTest.php`

**Interfaces:**
- Produces: `users.terms_accepted_at` (nullable datetime), `users.terms_version` (nullable string 20)
- Produces: `config('legal.terms_version')` — řetězec, výchozí `'2026-08-05'`

- [ ] **Step 1: Napiš padající test** — nový uživatel má obě pole null; po registraci se souhlasem jsou vyplněná a verze sedí s configem.
- [ ] **Step 2: Migrace + cast + config.**
- [ ] **Step 3:** `php artisan test tests/Feature/Legal --compact`
- [ ] **Step 4: Commit** — `feat(legal): record when and which terms version a tenant accepted`

---

### Task 2: Právní texty jako dokumenty

Nejdřív texty, teprve pak stránky, které je renderují — obsah je tu to podstatné a nemá vznikat jako výplň šablony.

**Files:**
- Create: `docs/legal/README.md` (co je zdroj pravdy, jak se mění, kde se renderuje)
- Create: `docs/legal/vseobecne-obchodni-podminky.md`
- Create: `docs/legal/zasady-zpracovani-osobnich-udaju.md`
- Create: `docs/legal/zpracovatelska-smlouva.md`
- Create: `docs/legal/zasady-cookies.md`

**Obsah — VOP platformy** (poskytovatel = OSVČ Miroslav Opletal, nájemce = podnikatel):

1. Identifikace poskytovatele, živnostenský rejstřík, kontakt
2. Vymezení služby: pronájem softwaru jako služby, ne prodej zboží
3. **Nájemce je provozovatel e-shopu.** Odpovídá za obsah, ceny, VOP vůči svým zákazníkům, daně, reklamace, dopravu. Poskytovatel není stranou kupní smlouvy mezi nájemcem a jeho zákazníkem — to je jádro celého vztahu (spec kap. 11) a musí být napsané nepřehlédnutelně.
4. Registrace, zkušební období, vznik smlouvy
5. Cena, fakturační období, splatnost, prodlení; roční i měsíční interval, změna tarifu
6. Trvání a ukončení; co se stane s daty po ukončení a jak dlouho zůstávají
7. Dostupnost služby, plánovaná údržba `> **K PRÁVNÍ REVIZI:** SLA a sankce za nedostupnost`
8. Zakázané užití: obsah v rozporu s právem, spam, zátěž ohrožující ostatní nájemce
9. Pozastavení a zrušení e-shopu — návaznost na stavy `past_due`/`suspended`/`pending_deletion`, které kód opravdu má
10. Odpovědnost `> **K PRÁVNÍ REVIZI:** limitace náhrady škody`
11. Zálohy a obnova dat
12. Změny VOP a jak se oznamují
13. Řešení sporů, rozhodné právo

**Obsah — zásady zpracování** (my správce, subjekt = nájemce): rozsah údajů, účel a právní titul (plnění smlouvy, oprávněný zájem, zákonná povinnost u dokladů), příjemci (Stripe, Comgate, Zásilkovna, hosting), doba uchování včetně 10 let u daňových dokladů, práva subjektu, kontakt, stížnost k ÚOOÚ.

**Obsah — DPA** (my zpracovatel, nájemce správce): předmět a doba, kategorie subjektů a údajů, pokyny správce, mlčenlivost, bezpečnostní opatření (šifrování credentials, izolace nájemců, zálohy), **podzpracovatelé jmenovitě** a jak se oznamuje změna, součinnost při právech subjektů a při ohlašování porušení, výmaz nebo vrácení dat po skončení, audit. `> **K PRÁVNÍ REVIZI:** lhůty součinnosti a rozsah auditního práva`

**Obsah — cookies platformy:** jen technické (session, XSRF, `cart` na storefrontu nájemce), proč se pro ně souhlas nevyžaduje, a poznámka, že cookies e-shopu nájemce jsou věcí nájemce (odkaz na vlnu 3.3).

- [ ] **Step 1:** Napiš čtyři dokumenty. Bez kódu, bez testů — čistý obsah.
- [ ] **Step 2:** `docs/README.md` — doplň rozcestník o `docs/legal/`.
- [ ] **Step 3: Commit** — `docs(legal): draft platform terms, privacy policy, DPA and cookie policy`

---

### Task 3: Renderované právní stránky platformy

**Files:**
- Create: `app/Http/Controllers/LegalController.php`
- Create: `resources/views/legal/layout.blade.php`
- Create: `resources/views/legal/{obchodni-podminky,ochrana-osobnich-udaju,zpracovani-udaju,cookies}.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Legal/LegalPagesTest.php`

**Interfaces:**
- Produces: routy `legal.terms`, `legal.privacy`, `legal.dpa`, `legal.cookies` pod `/pravni/*`, middleware `['web', 'platform.host']`
- Consumes: `config('billing.company')` pro identifikační údaje, `config('legal.terms_version')` pro datum účinnosti

- [ ] **Step 1: Napiš padající testy**

1. Každá ze čtyř cest odpovídá 200 na platform hostu bez přihlášení.
2. Stránka obsahuje název a IČO z `config('billing.company')`, ne natvrdo psaný řetězec.
3. Stránka nese `<link rel="canonical">` a **nemá** `noindex`.
4. Tytéž cesty na hostu nájemce odpovídají 404.
5. **Nájemce se stránkou `cookies` ji má dál dostupnou na `/cookies`** — přímý test proti kolizi popsané ve spec.
6. Stránka se vyrenderuje bez Vite manifestu (`withoutVite`), tedy nezávisí na buildu.

- [ ] **Step 2: Layout a šablony.** Layout minimální, samostatný — nedědí ze storefront layoutu nájemce (jiný host, jiné barvy) ani z Inertia app shellu. Nadpisová struktura `h1` → `h2`, čitelná šířka řádku, funkční bez JS.
- [ ] **Step 3: Controller.** Jedna metoda na dokument, nebo jedna metoda s mapou slug → view; mapa je server-authoritative, slug z URL se nikdy nepřevádí na název souboru přímo (path traversal).
- [ ] **Step 4: Routy** v `routes/web.php` pod `Route::prefix('pravni')->middleware('platform.host')`.
- [ ] **Step 5: Ověř**

```
php artisan test tests/Feature/Legal --compact
php artisan test tests/Feature/Modules/Pages --compact
```

- [ ] **Step 6: Commit** — `feat(legal): serve the platform's legal documents under /pravni`

---

### Task 4: Souhlas při registraci nájemce

**Files:**
- Modify: `app/Http/Controllers/Auth/RegisteredUserController.php`
- Create: `app/Http/Requests/Auth/RegisterRequest.php` (validace patří do FormRequestu, ne inline — konvence projektu)
- Modify: `resources/js/Pages/Auth/Register.vue`
- Test: `tests/Feature/Legal/TermsAcceptanceTest.php` (rozšířit z Tasku 1)

- [ ] **Step 1: Napiš padající testy**

1. Registrace bez `terms` skončí `assertSessionHasErrors('terms')` a **uživatel nevznikne**.
2. Registrace s `terms` uloží `terms_accepted_at` a `terms_version` z configu.
3. Chybová hláška je česky.

- [ ] **Step 2: FormRequest** — přesun stávajících pravidel + `'terms' => ['accepted']` s českou hláškou.
- [ ] **Step 3: Controller** — zapiš obě pole při vytvoření uživatele.
- [ ] **Step 4: Register.vue** — checkbox s odkazy na `/pravni/obchodni-podminky` a `/pravni/ochrana-osobnich-udaju` (`target="_blank"` + `rel="noopener"`), zobrazení `form.errors.terms`, a **počeštění celého formuláře** (dnes Breeze default v angličtině: „Name", „Email", „Password", „Confirm Password", „Already registered?").
- [ ] **Step 5: Ověř**

```
php artisan test tests/Feature/Legal tests/Feature/Auth --compact
npm run build
```

- [ ] **Step 6: Commit** — `feat(legal): require and record the tenant's consent at registration`

---

### Task 5: Vzory právních stránek nájemce

**Files:**
- Modify: `Modules/Pages/Lifecycle.php`
- Create: `Modules/Pages/Support/PageTemplates.php`
- Modify: `resources/js/Pages/Modules/Pages/Index.vue` (upozornění o odpovědnosti)
- Test: `tests/Feature/Modules/Pages/PageTemplatesTest.php`

**Interfaces:**
- Produces: `Modules\Pages\Support\PageTemplates::all(): array<string, array{title: string, body: string}>`

- [ ] **Step 1: Napiš padající testy**

1. Aktivace modulu založí tři stránky, každá **nepublikovaná** a s neprázdným tělem.
2. Každé tělo obsahuje aspoň jeden `[DOPLŇTE`.
3. Opakovaná aktivace **nepřepíše** tělo, které nájemce už upravil (dnešní `firstOrCreate` to drží — test to zafixuje).
4. Šablony projdou `HtmlSanitizer` beze změny, tedy neobsahují nic, co by sanitizace při uložení zahodila.

- [ ] **Step 2: `PageTemplates`** — tři šablony jako HTML (tabulka v `pages.body` nese HTML, ne Markdown):
  - **`obchodni-podminky`**: identifikace prodávajícího, uzavření smlouvy, cena a platba, dodání, **odstoupení do 14 dnů** včetně poučení, reklamace a záruka, mimosoudní řešení sporů (ČOI), účinnost. Placeholdery na všechny údaje prodávajícího a lhůty.
  - **`ochrana-osobnich-udaju`**: správce, rozsah, účel a titul, příjemci (dopravce, platební brána, náš hosting jako zpracovatel), doba uchování, práva, kontakt, ÚOOÚ.
  - **`kontakt`**: identifikační údaje, adresa, e-mail, telefon, IČO/DIČ.
  - Každá šablona začíná odstavcem, že jde o **vzor, ne právní radu**, a že ho má nájemce před publikací upravit. Ten odstavec je jedním z `[DOPLŇTE …]` bloků, takže ho nelze přehlédnout.
- [ ] **Step 3: `Lifecycle`** volá `PageTemplates::all()`; `firstOrCreate` beze změny.
- [ ] **Step 4: Admin upozornění** — nad tabulkou stránek text, že za obsah odpovídá nájemce a šablony nejsou právní radou.
- [ ] **Step 5: Ověř** — `php artisan test tests/Feature/Modules/Pages --compact`
- [ ] **Step 6: Commit** — `feat(pages): seed tenant legal pages from templates instead of blanks`

---

### Task 6: Odkazy v patičce a v pokladně

**Files:**
- Modify: `Modules/Storefront/Resources/views/layouts/shop.blade.php`
- Modify: `Modules/Checkout/Resources/views/checkout/details.blade.php`
- Test: `tests/Feature/Storefront/FooterLegalLinksTest.php`

- [ ] **Step 1: Napiš padající testy**

1. Publikovaná stránka nájemce je v patičce, nepublikovaná ne.
2. E-shop bez modulu `pages` vyrenderuje patičku bez pádu.
3. Souhlas v pokladně odkazuje na publikované `obchodni-podminky` nájemce; když stránka není publikovaná, text zůstane bez odkazu (ne mrtvý odkaz na 404).

- [ ] **Step 2: Patička** — seznam publikovaných stránek. Dotaz musí být cachovatelný pod `Dimension::Content`, kterou layout už deklaruje.
- [ ] **Step 3: Pokladna** — odkaz jen když stránka existuje a je publikovaná.
- [ ] **Step 4: Ověř**

```
php artisan test tests/Feature/Storefront tests/Feature/Modules/Checkout --compact
```

- [ ] **Step 5: Commit** — `feat(storefront): link the tenant's published legal pages from the footer and checkout`

---

### Task 7: Uzavření vlny

- [ ] **Step 1:** Testy po adresářích, zapiš skutečný počet.
- [ ] **Step 2:** `docs/as-is/2026-08-05-pravni-minimum.md`
- [ ] **Step 3:** `docs/as-is/STATUS.md` — nový řádek; ze sekce „Před spuštěním" v CLAUDE.md odškrtnout VOP, GDPR a cookies platformy (**ne** cookies storefrontu — ty jsou 3.3).
- [ ] **Step 4:** CLAUDE.md — rozhodnutí: prefix `/pravni/`, verze souhlasu na `users`, vzory místo prázdných stránek.
- [ ] **Step 5:** `VERSION` → `0.37.0`, `CHANGELOG.md`.
- [ ] **Step 6:** Merge do `main` a push.

---

## Rizika

| Riziko | Dopad | Mitigace |
|---|---|---|
| Platformní cesta zastíní stránku nájemce | Nájemcova VOP zmizí bez varování | Prefix `/pravni/` (dvousegmentový) + explicitní test na `/cookies` u nájemce (Task 3, test 5) |
| Nájemce publikuje vzor s nedoplněnými placeholdery | Jeho zákazníci vidí `[DOPLŇTE …]` v VOP | Stránky zůstávají nepublikované, upozornění v adminu, placeholder hned v prvním odstavci. Tvrdá blokace publikace zvážena a zamítnuta — nájemce může mít legitimní důvod publikovat rozpracované |
| Drafty bez právní revize | Neplatná ujednání, pokuta ÚOOÚ | Konzervativní formulace, markery `> **K PRÁVNÍ REVIZI:**`, položka v „Před spuštěním" |
| `terms_version` bez tabulky verzí | Nelze prokázat *znění* k datu | Historie je v gitu; zapsáno jako vědomé omezení ve spec |
| Změna registračního formuláře rozbije existující auth testy | Padající sada | Task 4 se pouští samostatně; Breeze testy registrace se musí upravit o `terms`, ne obejít |
