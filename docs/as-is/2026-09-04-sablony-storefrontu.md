# Šablony storefrontu — as-is

**Datum:** 2026-09-04
**Vlna:** 4.1
**Spec:** [`docs/superpowers/specs/2026-09-04-sablony-storefrontu-design.md`](../superpowers/specs/2026-09-04-sablony-storefrontu-design.md)
**Plán:** [`docs/superpowers/plans/2026-09-04-sablony-storefrontu.md`](../superpowers/plans/2026-09-04-sablony-storefrontu.md)

## Co je hotové

Nájemce si v administraci (Nastavení → Vzhled) vybere šablonu storefrontu. Vedle dosavadního
vzhledu (`base`) jsou k dispozici dvě další: `editorial` a `retail`. Výběr je v tarifu **base**,
tedy pro každého nájemce.

## Mapa změn

### Jádro

| soubor | co dělá |
|---|---|
| `config/themes.php` | kde šablony leží, která je výchozí, TTL cache registru |
| `app/Core/Theme/ThemeManifest.php` | parsovaný `theme.json`; uzavřený seznam tokenů a přepisovatelných pohledů |
| `app/Core/Theme/ThemeRegistry.php` | čte `themes/*/theme.json`, validuje, cachuje; neznámý klíč = `base` + `Log::warning` |
| `app/Core/Theme/ThemeViewPaths.php` | **nahrazuje** hinty pohledů podle šablony a vyprázdní finder |
| `app/Core/Theme/ThemeData.php` | + `key`, `tokens`, `cssEntry`, `css()`, `assets()` |
| `app/Core/Theme/ThemeResolver.php` | doplní manifest, sanitizuje tokeny, ověří Vite entry |
| `app/Http/Middleware/ApplyTenantTheme.php` | aplikuje šablonu na začátku každého požadavku skupiny `web` |
| `database/migrations/2026_09_04_090000_add_template_to_tenant_theme.php` | `tenant_theme.template`, default `base` |
| `app/Http/Controllers/Tenant/AppearanceController.php` | výběr šablony + seznam šablon do props |
| `app/Http/Requests/Tenant/UpdateAppearanceRequest.php` | `template` validován proti registru, volitelný |
| `resources/js/Pages/Tenant/Appearance.vue` | radiogroup dlaždic s náhledem z tokenů |
| `resources/css/storefront.css` | fallback tokeny, komponentní třídy nad tokeny, `.shop-container`, `.bleed` |
| `tailwind.config.js` | `themes/**` v `content`; tokeny jako utility (`bg-surface`, `rounded-token`, …) |
| `vite.config.js` | vstupy `resources/css/themes/{editorial,retail}.css` |
| `Modules/Storefront/Resources/views/components/product-count.blade.php` | „1 výrobek / 3 výrobky / 12 výrobků" |

### Šablony

```
themes/base/theme.json                    – jen tokeny, žádné přepisy
themes/editorial/theme.json + views/      – 9 přepsaných pohledů
themes/retail/theme.json + views/         – 10 přepsaných pohledů
resources/css/themes/{editorial,retail}.css
public/fonts/editorial/archivo-{latin,latin-ext}.woff2
public/fonts/retail/source-sans-3-{latin,latin-ext}.woff2
```

## Jak to funguje

**Šablona = adresář.** `theme.json` nese klíč, název, popis, volitelný Vite vstup, tokeny
a seznam přepsaných pohledů. Přepis znamená soubor téhož jména v `themes/{key}/views/{namespace}/`.
Jméno pohledu se nemění, takže view composery, `@include` ani existující testy o šabloně nevědí.

**Cesty se nahrazují, ne přidávají.** View finder je singleton; v procesu, který přežije víc
požadavků (fronta, Octane), by přidávání navrstvilo šablonu jednoho nájemce na dalšího a druhý
návštěvník by dostal cizí storefront. Test kontroluje **obsah hintů**, ne výsledek renderu —
implementace, která cesty jen předřazuje, projde aserci na HTML pokaždé, když se novější šablona
náhodou seřadí první.

**Manifest a adresář se musí shodovat oběma směry.** Nedeklarovaný soubor v `views/` registr
odmítne. Bez toho by whitelist hlídal jen to, co šablona *říká*, a nic by nehlídalo, co doopravdy
veze — soubor v `themes/{key}/views/checkout/` by tiše přebil skutečný košík.

**Tokeny jdou do `<style>` v `<head>`, až za `@vite`.** `storefront.css` nese `:root`
s fallbacky a mezi dvěma `:root` stejné specificity vyhrává pozdější. To se během vlny opravdu
stalo: šablona vykreslila svoje značkování ve výchozí paletě a **žádná HTML aserce to nechytla**,
protože tokeny na stránce byly — jen přehlasované. Hlídá to `ThemeLayoutOrderTest` nad zdrojem
šablon.

**Cache se řeší sama.** `PageCacheObserver` už mapuje `TenantTheme` na `Dimension::Theme` a všechny
cachované routy razí `theme`, takže uložení šablony zneplatní stránky bez nové mechaniky.
Middleware čte šablonu z cache klíčované toutéž generací, aby cache hit nestál dotaz do databáze.

## Plnění spec po sekcích

| požadavek spec | stav |
|---|---|
| Šablona jako adresář s manifestem, tokeny a volitelnými přepisy | hotovo |
| Výběr v administraci, TENANT_ADMIN, tarif base | hotovo |
| Přepnutí zneplatní page cache | hotovo (test) |
| Dvě šablony vedle `base` | hotovo |
| Stávající nájemci beze změny vzhledu | hotovo (default `base` v migraci) |
| Blade SSR, funkční bez JS, JSON-LD, canonical, WCAG | hotovo (smluvní test + E2E + axe) |
| Self-hostovaná písma | hotovo |
| Uzavřený seznam přepisovatelných pohledů | hotovo, navíc kontrola adresáře |

## Testy

| soubor | co hlídá | počet |
|---|---|---|
| `tests/Feature/Theme/ThemeRegistryTest.php` | manifest, validace, fallback, cache | 10 |
| `tests/Feature/Theme/ThemeViewResolutionTest.php` | přepis, fallback, izolace mezi nájemci (i přes HTTP) | 7 |
| `tests/Feature/Theme/ThemeTokenTest.php` | tokeny v HTML, přednost barvy nájemce, sanitizace | 5 |
| `tests/Feature/Theme/ThemeSelectionTest.php` | administrace, validace, bump cache, izolace | 5 |
| `tests/Feature/Theme/ThemeStorefrontContractTest.php` | SSR, SEO, JSON-LD, košík, 404, no-JS řazení, POST do košíku, layout | 10 × počet šablon |
| `tests/Feature/Theme/ThemeContrastTest.php` | kontrast tokenů 4,5:1 | 1 × počet šablon |
| `tests/Feature/Theme/ThemeLayoutOrderTest.php` | tokeny až za stylesheetem | 1 × počet layoutů |
| `e2e/tests/themes-no-js.spec.ts` | nákup bez JS ve všech třech šablonách | 3 |
| `e2e/tests/themes.spec.ts` | axe na homepage, výpisu a detailu ve dvou šablonách | 6 |

Smluvní test i kontrastní test berou šablony **z disku**, takže nová šablona se do nich zapojí
tím, že vznikne — ne tím, že si na ni někdo vzpomene.

## Odchylky od specifikace

1. **Náhledy šablon nejsou obrázky.** Spec navrhoval `themes/{key}/preview.webp`. Dlaždice
   v administraci místo toho kreslí malý model z tokenů šablony. Důvod: v době výběru šablony
   nemá nájemce co vyfotit a snímek staršího stavu šablony je horší než žádný. Pole `preview`
   v manifestu zůstává; jakmile obrázek vznikne, dlaždice ho použije přednostně.

2. **Pás výhod u `retail` a promo lišta u `editorial` nemají vlastní pole nastavení.** Renderují
   se z toho, co nájemce už vyplnil (`tagline`, `contact_phone`, `opening_hours`), a když
   nevyplnil nic, nezobrazí se. Šablona tak nevyžaduje migraci nastavení jako podmínku použití.
   Vlastní pole (čtyři dlaždice výhod, text promo lišty) jsou kandidát na příští vlnu.

3. **Hvězdy hodnocení nejsou v kartě produktu žádné šablony.** Základní karta je zatím nemá
   (Task 7 vlny recenzí), a přidat je jen do jedné šablony by znamenalo funkci schovanou ve
   vzhledu. Až přibudou do základu, doplní se do obou šablon.

4. **Výpis kategorie v `retail` nemá vodorovnou lištu rozbalovacích filtrů podle vzoru.**
   Platforma zatím nemá filtry podle vlastností (barva, rozměr, kolekce) — má řazení a „pouze
   skladem". Lišta tedy obsahuje to, co existuje. Až přibudou fasety, rozšíří se.

5. **`.bleed` vyžaduje `overflow-x: hidden` na `body`.** Okraje počítané z `100vw` zahrnují
   šířku svislého posuvníku, takže bez toho by celý dokument o pár pixelů ujížděl do strany.

## Technický dluh a co si hlídat

- **Kopie layoutu je nejkřehčí místo téhle architektury.** Každá šablona má vlastní kopii
  hlavičky a patičky, takže změna v základním layoutu (nový povinný `@stack`, jiná cookie lišta)
  se do šablon nepropíše sama. Smluvní test hlídá skip link, verzi souhlasu, banner, odkaz na
  nastavení cookies a nepřítomnost počtu v košíku — cokoli nového je potřeba do něj přidat.
- **Tailwind v4 zplošťuje `@layer`.** Fallbacky v `storefront.css` proto nejde spolehnout na
  vrstvu; rozhoduje pořadí v `<head>`. Drží to `ThemeLayoutOrderTest`.
- **Písma nejsou podmnožinovaná na češtinu.** Jsou to originální latin/latin-ext subsety od
  Googlu (32–59 kB). Vlastní subset by ušetřil možná třetinu, ale znamenal by build krok navíc.
- **Zamykání šablon tarifem** není implementované — všechny šablony jsou v base. Až vznikne
  prémiová šablona, bude potřeba gate v tarifu **i** ve validaci requestu.

## Nález mimo rozsah vlny: lišta souhlasu překrývá konec krátké stránky

E2E ukázalo, že s vypnutým JavaScriptem lišta souhlasu (`#cookie-banner`, `position: fixed`
dole) překryje poslední prvek stránky, která se nemá kam odrolovat — konkrétně tlačítko
**Pokračovat** na krocích pokladny. Není to vada šablony: lišta je v jádře, renderuje se do
každé cachované stránky bezpodmínečně a odstraňuje ji jen JavaScript (vlna 3.0 vědomě
nechtěla cachovat podle cookie). Bez JS ji tedy nikdy nic neodstraní a rolování nepomůže,
protože pod tlačítkem už nic není.

Klávesnicí to projde (Enter na tlačítku), takže to není úplná blokace, ale myší se návštěvník
bez JavaScriptu na tlačítko nedostane. Testy proto odesílají kroky pokladny z klávesnice a
komentář u nich na tuhle příčinu ukazuje.

**Návrh opravy (jiná vlna, jádro):** dát `<body>` spodní odsazení o výšku lišty, dokud
rozhodnutí neexistuje — buď třídou, kterou lišta sama přidá, nebo `scroll-padding-bottom`.
Chce to vlastní zadání, protože se to dotýká každé stránky každého e-shopu.

## Pre-deploy checklist

- [ ] `php artisan migrate` — přibývá `tenant_theme.template` (default `base`)
- [ ] `npm run build` — nové Vite vstupy `themes/{editorial,retail}.css`
- [ ] Ověřit, že `public/fonts/editorial/` a `public/fonts/retail/` se nasadily (nejsou v buildu,
      jsou to statické soubory)
- [ ] Po nasazení zkontrolovat, že stávající nájemci mají `template = 'base'` a jejich e-shop
      vypadá stejně jako před nasazením
- [ ] Projít jeden e-shop v každé šabloně s vypnutým JavaScriptem
