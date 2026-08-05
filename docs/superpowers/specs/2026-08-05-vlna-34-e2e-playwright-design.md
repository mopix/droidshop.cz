# Vlna 3.4 — E2E testy v prohlížeči (Playwright) — zadání

Datum: 2026-08-05 · Stav: **návrh** · Fáze 2

Navazuje na: [`docs/as-is/2026-08-05-cookie-lista-mereni.md`](../../as-is/2026-08-05-cookie-lista-mereni.md) (vlna 3.3)

## Proč

Projekt má 2003 zelených testů a **ani jeden nespustí JavaScript**. Tři závazné vlastnosti produktu tím zůstávají neověřené:

1. **Měřicí skripty respektují souhlas** (vlna 3.3). PHP test umí říct jen to, že v HTML není nic, co by samo vyvolalo požadavek. Že se gtag.js po odmítnutí opravdu nenačte, je dnes ověřené **ručně** — a ruční ověření se s další vlnou rozpadne.
2. **Celý checkout funguje bez JS** (AK §16.3, závazné pravidlo storefrontu). Testováno je to tak, že server vrátí správné HTML — ne že se tím dá reálně proklikat.
3. **Přístupnost WCAG 2.2 AA** (EAA). Dnes ji kontroluje jen agent při review, tedy nepravidelně a bez záznamu.

## Rozsah

### 1. Playwright jako devDependency

`@playwright/test` + `@axe-core/playwright`. Prohlížeče se stahují do cache, ne do repa.

Konfigurace v `e2e/`, samostatná od PHPUnit sady. Spouští se `npm run e2e`.

### 2. Lokální doména `droidshop.test`

**Ne kvůli certifikátu** — to je omylný závěr, který v `STATUS.md` visí od vlny 1.x. Playwright pojede na HTTP proti `php artisan serve`, kde žádné TLS není; `curl -k` se týkal HTTPS.

Skutečný důvod: Chromium doménu bez TLD (`obchod.droidshop`) může upgradovat na HTTPS nebo poslat do vyhledávání. `.test` je podle **RFC 6761** vyhrazená TLD, kterou prohlížeče nechají být a neposílají do DNS.

Testovací prostředí tedy jede na `obchod.droidshop.test`; `/etc/hosts` a `PLATFORM_DOMAIN` to musí odrážet. Existující PHPUnit sada zůstává na `droidshop` — přepisovat 2003 testů kvůli E2E by byla změna s velkým dosahem a nulovým přínosem.

### 3. Scénáře

| Scénář | Co ověřuje | Proč to PHP test neumí |
|---|---|---|
| **Souhlas a měření** | bez rozhodnutí neodejde požadavek na `googletagmanager.com`, `c.seznam.cz`, `connect.facebook.net`; po souhlasu odejde; odmítnutí drží i po reloadu; lišta neprobliká tomu, kdo rozhodl | vyžaduje odchycení skutečných síťových požadavků |
| **Nákup bez JS** | s vypnutým JS projde katalog → produkt → košík → doprava → údaje → děkovná stránka | vyžaduje prohlížeč, který JS umí vypnout |
| **Nákup s JS** | tatáž cesta s JS, včetně výběru varianty a mini-košíku | ostrůvky se v PHP testu nespustí |
| **Přístupnost** | axe na homepage, detailu produktu, košíku, pokladně a liště cookies | statická analýza markupu nezachytí kontrast ani focus |

### 4. CI job

Samostatný job v `.github/workflows/ci.yml`, vedle `tests` a `tenant-isolation`. Co neběží v CI, shnije.

## Akceptační kritéria

1. `npm run e2e` projde lokálně proti čerstvě naseedovanému demu.
2. Scénář souhlasu **selže**, kdyby se měřicí skript načetl před rozhodnutím — ověřeno tím, že se dočasně poruší gate a test zčervená.
3. Nákup bez JS projde od katalogu po děkovnou stránku a založí objednávku.
4. Axe nehlásí žádné porušení úrovně `critical` ani `serious` na pokrytých stránkách.
5. CI job běží na PR a jeho selhání blokuje merge.
6. Sada je stabilní: tři běhy po sobě dají stejný výsledek.

## Omezení a rizika

**Flaky testy jsou horší než žádné.** E2E sada, která bliká, se začne přeskakovat a pak i ignorovat, když hlásí skutečnou chybu. Proto: žádné čekání na pevný čas, jen na stav; jeden seed před během, ne mezi testy; každý scénář si zakládá vlastní data.

**Cizí domény v CI.** Scénář souhlasu ověřuje požadavky na Google a Seznam — v CI nesmí opravdu odejít. Requesty se odchytí a zablokují na úrovni prohlížeče (`page.route`), takže se testuje **pokus o požadavek**, ne odpověď.

**Seed a stav.** E2E potřebuje běžící aplikaci s daty. Sada si sama zajistí `migrate:fresh` + `DemoShopSeeder` a spustí server; bez toho by výsledek závisel na tom, co má vývojář v databázi.

**Axe není razítko souladu.** Automatický audit zachytí zhruba třetinu problémů WCAG. Nenahrazuje ruční kontrolu ani agenta `a11y-checker`, jen zachytí regrese.

## Mimo rozsah

- **Vizuální regrese** (screenshot diffy) — křehké na fontech a rendereru, přínos u čistě funkčního storefrontu malý.
- **Testy administrace** — Inertia SPA, jiná sada rizik; storefront je to, co vydělává.
- **Mobilní viewporty a víc prohlížečů** — jeden Chromium, dokud sada nedokáže, že je stabilní.
- **Zátěžové testy.**
