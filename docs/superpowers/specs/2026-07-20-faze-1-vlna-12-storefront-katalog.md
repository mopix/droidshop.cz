# Fáze 1, vlna 1.2 — Storefront katalogu (Blade SSR + SEO)

**Status:** návrh
**Autor:** Miroslav Opletal (+ agent)
**Navazuje na:** vlnu 1.1 (`docs/superpowers/plans/2026-07-20-faze-1-vlna-11-katalog-jadro.md`)
**Produktová spec:** §3.1 (SEO základ), §4.1.1, §6.2, §6.3, §15.3, §16.1, §16.2
**Závazné pravidlo:** [`.claude/rules/storefront-rendering.md`](../../../.claude/rules/storefront-rendering.md)

## Proč

Katalog (kategorie, produkty, obrázky, ceny, SEO pole) je hotový, ale nemá jedinou veřejnou routu — e-shop nájemce je zvenku neviditelný. Zároveň `redirects` se zapisují a nic je neservíruje: přejmenování slugu dnes vrací 404 a ztrácí link equity. Tahle vlna zpřístupní katalog veřejně a splní SEO výstupy, které jsou v pravidlu storefrontu závazné.

## Rozsah

**V rozsahu:** homepage, výpis kategorie, detail produktu, vyhledávání, chybové stránky, servírování redirectů, 410 u smazaných produktů, SEO výstupy (title/description, canonical, OG/Twitter, JSON-LD, `sitemap.xml`, `robots.txt`, `rel=prev/next`), layout šablony jako modul, storefront asset bundle.

**Mimo rozsah:** košík a pokladna (vlna s modulem `checkout`), page cache §15.6 (samostatná vlna, závisí na Redisu), varianty produktů, filtry podle parametrů, výrobci, blog, XML feedy, editor barev šablony (`theme`), vícejazyčnost.

## Rozhodnutí této vlny

1. **Šablona = modul `storefront`.** Drží layout, homepage, hledání, sitemap, robots, chybové stránky a sdílené Blade komponenty. Katalogové routy zůstávají v `products` a `categories` a jen dědí layout přes `storefront::layouts.shop`. Důvod: CLAUDE.md „šablona = modul"; druhá šablona pak nevyžaduje refaktor.
2. **Redirecty se servírují až při 404, ne middlewarem na každém requestu.** Zásah do renderování `NotFoundHttpException`: dotaz do `redirects` proběhne jen na cestě, která stejně končí chybou. Middleware v `web` skupině by přidal DB dotaz ke každému zobrazení produktu.
3. **Vyhledávání přes normalizovaný sloupec + `LIKE`, ne MySQL fulltext.** Odchylka od §16.1. Fulltext InnoDB neumí češtinu (bez stemmingu a normalizace diakritiky) a nejede na SQLite, kterou používáme v testech. Normalizovaný sloupec `search_text` (lowercase, bez diakritiky, název + SKU + krátký popis) plněný při uložení je stejně nutná podmínka podle §4.1; fulltext index nad ním se dá doplnit později bez změny API.
4. **Storefront má vlastní asset bundle**, oddělený od admin Inertia bundlu. Cíl < 100 kB gzip, Alpine ostrůvky, žádný Vue runtime na veřejných stránkách.

## Akceptační kritéria

### Routy a viditelnost

- [ ] `GET /` na doméně tenanta vrátí 200 s vyrenderovanou homepage; na platformní doméně chová beze změny.
- [ ] `GET /kategorie/{slug}` vrátí 200 pro `is_visible = true`, 404 pro skrytou nebo cizí kategorii.
- [ ] `GET /produkt/{slug}` vrátí 200 pro `status = active`, 404 pro `draft`/`hidden`.
- [ ] `GET /hledani?q=` s dotazem < 2 znaky vrátí stránku s výzvou, ne chybu.
- [ ] Kategorie i produkt tenanta A jsou na hostu tenanta B 404 (test izolace).
- [ ] Vypnutý modul `products` znamená 404 na `/produkt/*`, ne redirect na login.

### SEO výstupy (surové HTML, ne z JS)

- [ ] Název, cena s DPH a popis produktu jsou v HTML první odpovědi (`curl -k`, bez JS).
- [ ] `<title>` a `<meta name="description">` z `seo_*` polí, fallback generovaný z názvu.
- [ ] `<link rel="canonical">` absolutní, na doméně tenanta, vždy plochá URL (`/produkt/{slug}`) bez query parametrů řazení a stránkování.
- [ ] OG + Twitter meta včetně `og:image` (SEO obrázek, jinak hlavní obrázek produktu).
- [ ] JSON-LD: `Product` + `Offer` na detailu, `BreadcrumbList` na detailu i kategorii, `ItemList` na výpisu kategorie, `Organization` + `WebSite` na homepage. Výstup validuje Rich Results Test.
- [ ] Stránkování: `rel=prev/next`, canonical na konkrétní stránku (ne na první).
- [ ] `noindex, follow` na `/hledani` s nula výsledky a na kombinace filtrů mimo whitelist.
- [ ] `GET /sitemap.xml` vrací per-tenant sitemap (produkty `active`, kategorie `visible`, publikované stránky) s `lastmod`; obsah cachovaný.
- [ ] `GET /robots.txt` per tenant, s odkazem na sitemap; tenant ve stavu, kdy storefront neběží, dostane `Disallow: /`.

### Redirecty a chybové stavy

- [ ] Změna slugu kategorie nebo produktu (zapsaná `RedirectRegistry` už ve vlně 1.1) vrací na staré cestě **301** na novou.
- [ ] Řetěz redirectů se neservíruje po krocích — `RedirectRegistry` je kolabuje, servírování dělá jeden skok.
- [ ] Redirect tenanta A neplatí na hostu tenanta B.
- [ ] Soft-deleted produkt vrací **410** se stránkou „produkt již není v nabídce" a odkazem na kategorii (§16.1).
- [ ] 404 stránka je vyrenderovaná v šabloně tenanta, ne výchozí Laravel.
- [ ] Neexistující tenant / suspendovaný tenant chová jako dnes (`tenancy/unavailable`).

### Výpis kategorie

- [ ] Grid produktů, stránkování 24/stránku, řazení (nejnovější, cena vzestupně/sestupně, název) přes query parametry — **funguje bez JS**.
- [ ] Filtr „skladem" jako query parametr se server-side aplikací.
- [ ] Popis nad a pod výpisem, obrázek kategorie, podkategorie jako navigace.
- [ ] Produkty podkategorií se do výpisu započítávají (materializovaná cesta `path`).
- [ ] Prázdná kategorie vrací 200 se srozumitelnou hláškou, ne 404.

### Detail produktu

- [ ] Galerie obrázků (hlavní + náhledy), breadcrumbs podle primární kategorie, cena s DPH i bez, dostupnost podle `stock`.
- [ ] Bez modulu `checkout` je místo tlačítka „Do košíku" neutrální stav — stránka nesmí odkazovat na neexistující routu.
- [ ] Popis renderovaný z už sanitizovaného HTML (`HtmlSanitizer` čistí při zápisu), bez dvojité sanitizace při renderu.

### Výkon a přístupnost

- [ ] JS bundle storefrontu < 100 kB gzip.
- [ ] Žádný požadavek na katalogová data po načtení stránky.
- [ ] WCAG 2.2 AA: skip link, viditelný focus, kontrast, `alt` u obrázků produktů, `<h1>` právě jednou, stránkování ovladatelné klávesnicí.
- [ ] Stránky, které mají být cachovatelné, neobsahují žádný per-návštěvníkový obsah (příprava na §15.6).

## Rizika

| Riziko | Mitigace |
|--------|----------|
| `/` koliduje s dnešní Inertia `Welcome` routou | Welcome omezit na platformní host; pořadí registrace routů modulů ověřit testem |
| N+1 při výpisu kategorie (obrázky, sazby DPH) | eager loading + test počtu dotazů |
| Sitemap u tenanta s desítkami tisíc produktů | limit 50 000 URL na soubor, jinak index; zatím jen kontrola limitu a poznámka |
| Tailwind nevidí Blade v `Modules/` | doplnit content cesty, ověřit buildem |
| `curl` na subdoméně vyžaduje `-k` (známé omezení) | akceptovat pro tuto vlnu; lokální doména `droidshop.test` je samostatný úkol |
