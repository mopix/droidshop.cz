# Pravidlo: rendering storefrontu — Blade SSR povinně (SEO)

**Závazné. Priorita nad pohodlím implementace.** Porušení = ztráta organického trafficu tenantů = ztráta produktu.

## Proč

Storefront je marketingový a SEO majetek **nájemce**. Klientsky renderovaný katalog znamená horší indexaci, pomalejší LCP, rozbité sdílení odkazů (OG/Twitter meta), nefunkční Heureka/Zboží crawlery a nulovou kontrolu nad TTFB. Page cache (spec §15.6) dává smysl jen nad hotovým HTML.

## Rozdělení

### A) MUSÍ být Blade SSR — plné HTML v první odpovědi serveru

| Oblast | URL (MVP) |
|--------|-----------|
| Homepage | `/` |
| Výpis kategorie | `/kategorie/{slug}` |
| Detail produktu | `/produkt/{slug}` |
| Vyhledávání | `/hledani?q=` |
| Statické stránky (VOP, GDPR, kontakt, o nás) | `/{page-slug}` |
| Výrobce / štítek (fáze 2) | `/vyrobce/{slug}` |
| Blog (fáze 2) | `/blog`, `/blog/{slug}` |
| Sitemap, robots, feedy | `/sitemap.xml`, `/robots.txt`, feedy |
| Chybové stránky | 404, 410, 503 |

### B) MUSÍ být Blade SSR + progressive enhancement (nejde o SEO, ale o robustnost)

Košík a pokladna. Rozhodnutí 2026-07-19.

| Oblast | URL |
|--------|-----|
| Košík | `/kosik` |
| Pokladna — doprava a platba | `/pokladna/doprava` |
| Pokladna — údaje a rekapitulace | `/pokladna/udaje` |
| Děkovná stránka | `/dekujeme/{order-uuid}` |
| Návrat z platební brány | `/platba/navrat` |

Důvod: spec §16.3 AK *„celý checkout funkční bez JS"*, stav v DB `carts`, **veškerá cenová logika na serveru**. Tyto stránky mají `noindex`, ale SPA z nich nedělat.

### C) SMÍ být Vue / Inertia SPA

- Admin nájemce (`/admin/*`)
- Superadmin (`admin.droidshop.cz`)
- Registrace, onboarding průvodce, fakturace nájemce
- Vše za přihlášením v adminu

Tyto oblasti dostávají `noindex, nofollow`.

## Vue/Alpine ostrůvky na storefrontu — povolené použití

Ostrůvek = komponenta hydratovaná nad **již vyrenderovaným** HTML. Nikdy nesmí být jediným zdrojem obsahu.

Povoleno: výběr varianty (přepočet ceny/dostupnosti), galerie obrázků, mini-košík v hlavičce, přidání do košíku bez reloadu, našeptávač vyhledávání, widget výdejních míst Zásilkovny, ARES autofill, filtry v kategorii (musí mít i server-side fallback přes query parametry).

## Zakázáno na storefrontu

- Client-side router (`vue-router`, Inertia link routing) pro oblasti A a B
- Načítání produktů, cen, popisů nebo kategorií přes `fetch`/XHR po načtení stránky
- Prázdný `<div id="app">` jako jediný obsah
- `document.title` / meta tagy nastavované z JS
- Cenová logika v JS (spec §16.3)
- Obsah viditelný až po interakci, který má být indexován

## Povinné SEO výstupy každé stránky typu A

Renderované serverem, ne z JS:

- `<title>`, `<meta name="description">` (per-entita, z `seo_*` polí; fallback generovaný)
- `<link rel="canonical">` — absolutní URL na doméně tenanta
- OG + Twitter meta (`og:title`, `og:description`, `og:image`, `og:url`, `og:type`)
- JSON-LD: `Product` + `Offer` + `AggregateRating` (až budou recenze) na detailu; `BreadcrumbList` všude; `Organization` / `WebSite` na homepage; `ItemList` na výpisu kategorie
- `<link rel="prev/next">` nebo canonical strategie na stránkování
- `noindex` na: košík, pokladnu, děkovnou stránku, účet zákazníka, výsledky hledání s 0 výsledky, filtrované kombinace nad rámec whitelistu
- `hreflang` — až s modulem `multilang` (fáze 3)
- 301 z historických slugů (tabulka `redirects`, spec §15.3), 410 u smazaných produktů (spec §16.1)
- `sitemap.xml` per tenant — produkty, kategorie, stránky; generovaný jobem, servírovaný z cache

## Page cache a osobní obsah

**Cachované HTML nesmí obsahovat žádný per-návštěvníkový obsah.** Page cache je sdílená a klíčovaná jen podle tenanta a cesty (`page:{tenant}:{path}:{qs-hash}`) — cokoli osobního v HTML dostane i další anonymní návštěvník. Je to únik dat mezi zákazníky, stejná třída chyby jako únik mezi tenanty.

Prakticky:
- Mini-košík v hlavičce = prázdný placeholder v HTML, obsah dohydratuje ostrůvek přes `GET /api/kosik/souhrn` (`Cache-Control: private, no-store`)
- Stejně: „naposledy prohlížené", jméno přihlášeného zákazníka, personalizovaná doporučení
- **Necachovat pravidlem routy** (ne cookie): `/kosik`, `/pokladna/*`, `/dekujeme/*`, `/platba/*`, účet zákazníka
- Cookie `has_cart` jako vypínač cache se nepoužívá — zabíjela by cache katalogu právě aktivním nakupujícím (spec §15.6)

## Výkonové cíle (spec §8, §6.1)

TTFB < 200 ms z page cache, plné načtení < 2 s na 4G, Lighthouse výkon ≥ 90. JS bundle storefrontu drž pod 100 kB gzip — ostrůvky, ne framework na každé stránce.

## Kontrola před merge UI změny na storefrontu

1. `curl -s <url> | grep` — je produkt/cena/popis v surovém HTML?
2. JS vypnutý v prohlížeči — jde projít katalog a dokončit objednávku?
3. Je canonical absolutní a správný?
4. Validuje JSON-LD (Rich Results Test)?
5. Lighthouse SEO + Performance na vzorovém shopu.

## Když se pravidlo nehodí

Neobcházej. Otevři diskuzi a zapiš rozhodnutí do CLAUDE.md sekce Rozhodnutí.
