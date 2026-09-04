# Šablony storefrontu — výběr vzhledu nájemcem

**Datum:** 2026-09-04
**Status:** draft
**Související plán:** `docs/superpowers/plans/2026-09-04-sablony-storefrontu.md`

## Kontext

Platforma umí nájemci nabídnout jen jeden vzhled storefrontu. „Vzhled" v administraci mění
primární a akcentní barvu, logo a favicon — tedy tři CSS proměnné nad jediným natvrdo napsaným
layoutem `storefront::layouts.shop`. Dva e-shopy na platformě jsou proto k nerozeznání a nájemce
nemá čím odlišit značku.

Zadání vzniklo z proklikání dvou referenčních e-shopů, které představují dva různé žánry
českého retailu:

**bonprix.cz** — editorial móda. Promo lišta nad hlavičkou, utility lišta s odkazy, megamenu.
Sekce jdou přes celou šířku okna, obrázky se dotýkají bez mezer, karty produktů nemají žádný
rámeček ani stín. Nadpisy verzálkami s prostrkáním, centrované. Ostré rohy, černá plná tlačítka.
Výpis kategorie: levý sloupec s odkazy na podkategorie, centrovaný nadpis s počtem výrobků,
filtry v pravé zásuvce. Karta = obrázek, swatche barev, štítky, název, cena — žádné tlačítko.
Detail: svislá lišta náhledů vlevo, velká galerie, pravý nákupní sloupec s recenzním souhrnem,
tabulkou dopravců × plateb a social proofem.

**dovido.cz** — retailový katalog. Obsah v kontejneru ~1100 px, všechno zaoblené, teplý oranžový
akcent. Hero karusel v kartě, pás se čtyřmi ikonami výhod, produkty v ohraničených kartách
s badgem, hvězdami, dostupností, cenou a tlačítkem DETAIL; jinde vodorovné karty (náhled vlevo,
text vpravo). Výpis: chipy podkategorií s náhledy, vodorovná lišta rozbalovacích filtrů,
přepínač Akce, stránkování nahoře i dole. Detail: varianty jako chipy i jako obrázkové dlaždice
s příplatkem, stepper množství, oranžové VLOŽIT DO KOŠÍKU, dlaždice výhod, kotvy na sekce ve
sticky liště nesoucí i cenu a tlačítko.

Rozdíl mezi nimi není barva. Je to jiné rozvržení hlavičky, výpisu i detailu. Čistě tokenová
šablona (jen proměnné) to neuhraje, plný fork všech pohledů zase znamená, že každá oprava
v jádře se musí ručně propsat do každé šablony.

## Cíle

- [ ] Šablona je věc, kterou lze vybrat: adresář s manifestem, tokeny a **volitelnými** přepisy
      vybraných pohledů. Co šablona nepřepíše, bere ze základu.
- [ ] TENANT_ADMIN si šablonu vybere v obrazovce Vzhled. Součást tarifu **base**, tedy pro každého.
- [ ] Přepnutí šablony okamžitě zneplatní page cache.
- [ ] Vznikají dvě šablony vedle stávajícího základu: `editorial` (podle bonprix.cz)
      a `retail` (podle dovido.cz).
- [ ] Stávající nájemci vzhled nezmění: výchozí zůstává dnešní `base`.
- [ ] Obě šablony splní stejná pravidla jako dnešní storefront — Blade SSR, funkční bez
      JavaScriptu, WCAG 2.2 AA, JSON-LD, canonical.

## Mimo rozsah

- Editor šablon, vlastní CSS od nájemce, nahrávání vlastní šablony. Šablony dodává platforma.
- Vizuální stavitel stránek nad rámec dnešních bloků homepage.
- Vlastní šablona pro pokladnu a účet zákazníka. Ty dostanou tokeny a layout šablony, ale
  jejich pohledy zůstávají společné — dva různé checkouty znamenají dvakrát testovat
  progressive enhancement a dvakrát riskovat cenovou logiku.
- Přebírání obsahu, ochranných známek nebo obrázků z referenčních webů. Předlohou je
  **rozvržení a hustota**, ne aktiva.
- Vícejazyčnost, dark mode.

## Požadavky

### Model šablony

Šablona je adresář v `themes/{key}/`:

```
themes/
  base/                     # dnešní vzhled, žádné přepisy
    theme.json
  editorial/
    theme.json
    views/
      storefront/layouts/shop.blade.php
      storefront/components/product-card.blade.php
      ...
      products/storefront/show.blade.php
  retail/
    theme.json
    views/ ...
```

`theme.json` nese klíč, název, popis, náhledový obrázek a **tokeny**. Tokeny jsou ploché
`key: value` a renderují se do `<html>` jako CSS custom properties.

Povinné tokeny (kernel je validuje, neznámý klíč = chyba manifestu):

| token | význam |
|---|---|
| `container` | maximální šířka obsahu (`1152px`, `1100px`, `100%`) |
| `radius`, `radius-lg` | poloměr rohů karet a tlačítek |
| `surface`, `surface-muted`, `ink`, `ink-muted`, `line` | plochy, text, linky |
| `font-body`, `font-heading` | rodiny písma |
| `heading-transform`, `heading-tracking`, `heading-weight` | typografický charakter nadpisů |
| `card` | `plain` \| `bordered` \| `elevated` — chrom karty produktu |
| `section-gap` | svislý rytmus sekcí |
| `button-radius` | tlačítka zvlášť, protože ostrá tlačítka v zaoblené šabloně jsou legitimní volba |

**Barva značky nájemce token nepřebíjí.** `--brand-primary` a `--brand-accent` zůstávají tím,
co nastavil nájemce; šablona je smí použít, ale nedefinuje. Nájemce s červeným logem tedy
nedostane oranžový e-shop jen proto, že si vybral `retail`.

### Rozlišování pohledů

Pohledy modulů se dnes registrují jako `loadViewsFrom($dir, $key)`, tedy do namespace podle
klíče modulu (`storefront::`, `products::`, `checkout::`…). Laravel drží pro namespace **pole
cest** a bere první nález.

Šablona proto přepisuje pohled tak, že vedle základu položí soubor **se stejným jménem** —
`themes/editorial/views/storefront/components/product-card.blade.php` přepíše
`storefront::components.product-card`. Jméno pohledu se nemění, takže view composery,
`@include` i testy zůstávají beze změny.

Cesty se skládají za běhu podle šablony aktuálního nájemce. Musí se přepisovat, ne přidávat:
finder je singleton a v dlouho žijícím procesu (fronta, Octane) by se cesty jinak hromadily
a šablona jednoho nájemce by prosákla do dalšího požadavku.

### Přepisovatelné pohledy

Šablona smí přepsat jen pohledy z tohoto seznamu. Cokoli mimo něj kernel ignoruje a zaloguje —
šablona nesmí sáhnout na pohled, se kterým počítá cenová nebo bezpečnostní logika.

| pohled | co určuje |
|---|---|
| `storefront::layouts.shop` | hlavička, navigace, patička, obal stránky |
| `storefront::home` | rozvržení homepage |
| `storefront::components.blocks.*` | hero, banner, mřížka kategorií, řada produktů, text |
| `storefront::components.product-card` | karta produktu |
| `storefront::components.product-grid` | mřížka a její rozestupy |
| `storefront::components.breadcrumbs` | drobečky |
| `storefront::search` | stránka hledání |
| `categories::storefront.show` | výpis kategorie — nadpis, filtry, stránkování |
| `products::storefront.show` | detail produktu |
| `pages::show` | statická stránka |

Pokladna, účet zákazníka, doklady a chybové stránky přepsat nejdou. Dostanou layout a tokeny.

### Backend

- `App\Core\Theme\ThemeRegistry` — čte `themes/*/theme.json`, validuje, cachuje. Neznámý klíč
  v databázi = tichý pád na `base`, nikdy výjimka v požadavku zákazníka.
- `App\Core\Theme\ThemeManifest` — neměnný objekt manifestu.
- `App\Core\Theme\ThemeViewPaths` — složí a **nahradí** hinty pohledů pro daný klíč šablony.
- `ThemeData` se rozšíří o `key` a `tokens`, `ThemeResolver` je plní z registru.
- `tenant_theme.template` (string, default `base`) — nová migrace.
- `AppearanceController` dostane výběr šablony; `UpdateAppearanceRequest` validuje proti
  klíčům z registru, ne proti seznamu v kódu.
- Page cache: `PageCacheObserver` už mapuje `TenantTheme` na `Dimension::Theme` a všechny
  cachované routy razí `theme`, takže uložení šablony zneplatní stránky samo. Chce to test,
  ne novou mechaniku.

### Frontend

- `resources/css/storefront.css` zůstává zdrojem utilit. Komponentní třídy (`.btn`, `.card`,
  `.field-input`) se přepíšou na tokeny, aby na šabloně reagovaly bez `@apply` větvení.
- `resources/css/themes/{key}.css` — `@font-face` a to málo, co je pro šablonu specifické.
  Vlastní vstup ve Vite, layout ho zavádí podle šablony.
- Písma **self-hostovaná** v `public/fonts/{key}/`, `font-display: swap`, `<link rel=preload>`
  na jeden řez v hlavičce. Žádný požadavek na cizí doménu — kvůli cookie liště i kvůli LCP.
- Tailwind `content` musí zahrnout `./themes/**/views/**/*.blade.php`.
- Administrace: dlaždice s náhledy šablon v `resources/js/Pages/Tenant/Appearance.vue`,
  radio group, ne select — je to vizuální volba.

### Šablona `editorial` (podle bonprix.cz)

- Hlavička ve třech pásech: promo lišta (text z nastavení obchodu), utility lišta, hlavní řádek
  s logem, vodorovnou navigací verzálkami, hledáním a ikonami.
- Sekce homepage přes celou šířku okna, obsah uvnitř zarovnaný na kontejner.
- Karta produktu bez rámečku a stínu, obrázek na celou šířku dlaždice, název drobně,
  cena tučně. Slevová cena červeně, vedle ní přeškrtnutá původní a procento.
- Výpis kategorie: levý sloupec s podkategoriemi, centrovaný nadpis s počtem, řazení a filtry
  v pravé zásuvce (`<details>` + progressive enhancement, bez JS jako rozbalený panel).
- Detail: svislá lišta náhledů vlevo, galerie, pravý nákupní sloupec. Recenze pod ním
  s rozpadem hvězd (napojí se na modul `reviews`).
- Typografie: bezpatkový grotesk, nadpisy verzálkami s prostrkáním. Ostré rohy, tmavá tlačítka.

### Šablona `retail` (podle dovido.cz)

- Hlavička ve dvou pásech: kontakt a otevírací doba, velké hledání, ikony účtu a košíku;
  pod tím navigace kategorií s rozbalením.
- Pás výhod se čtyřmi ikonami pod hero (obsah z nastavení obchodu).
- Karta produktu ohraničená, zaoblená, s badgem stavu, hvězdami, dostupností, cenou
  a sekundárním tlačítkem. Varianta vodorovné karty pro řady na homepage.
- Výpis kategorie: chipy podkategorií s náhledy, vodorovná lišta filtrů, stránkování nahoře
  i dole.
- Detail: dvousloupec, varianty jako chipy, stepper množství, výrazné tlačítko do košíku,
  dlaždice výhod, kotvy na sekce (popis, parametry, recenze).
- Typografie: humanistický bezpatkový, nadpisy normální velikostí, tučně.

## Akceptační kritéria

1. Nájemce v administraci přepne šablonu; storefront se překreslí do nové šablony na první
   další požadavek, bez ručního mazání cache.
2. Nájemce A se šablonou `editorial` a nájemce B se šablonou `retail` obslouženi v jednom
   procesu za sebou dostanou každý svou šablonu. (Test na prosakování hintů.)
3. Nájemce, který nikdy nic nenastavil, vidí přesně dnešní vzhled.
4. Každá šablona projde stejnými storefront testy: název a cena produktu jsou v surovém HTML,
   canonical je absolutní, JSON-LD `Product`/`Offer`/`BreadcrumbList` validuje.
5. S vypnutým JavaScriptem lze v obou šablonách projít katalog, vložit do košíku a dokončit
   objednávku.
6. Kontrast textu vůči podkladu v obou šablonách splňuje 4,5:1 (velké texty 3:1), fokus je
   viditelný, navigace projitelná klávesnicí.
7. Manifest s neznámým tokenem nebo s přepisem pohledu mimo povolený seznam neprojde
   `modules:sync` ani testem manifestu.
8. Neexistující klíč šablony v databázi vyrenderuje `base` a zaloguje varování; nevyhodí 500.
9. JS bundle storefrontu zůstává pod 100 kB gzip v obou šablonách.

## Otevřené otázky

- Náhledy šablon v administraci: statické snímky obrazovky, nebo živý iframe na demo obchod?
  Návrh: statické `themes/{key}/preview.webp`, generované ručně — iframe by v administraci
  otevřel cizí doménu a zdržel načtení obrazovky.
- Promo lišta a pás výhod potřebují text. Buď nová pole v nastavení obchodu, nebo se
  vyrenderují jen když je nájemce vyplní. Návrh: druhé, aby šablona nevyžadovala migraci
  nastavení jako podmínku použití.
