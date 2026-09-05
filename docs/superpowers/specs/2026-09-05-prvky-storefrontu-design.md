# Prvky storefrontu a šablona `katalog`

**Datum:** 2026-09-05
**Status:** done
**Související plán:** `docs/superpowers/plans/2026-09-05-prvky-storefrontu.md`

## Kontext

Vlna 4.1 dala platformě šablony. Ukázalo se ale, že šablona sama rozdíl neudělá: referenční
e-shop (dovido.cz) stojí na **prvcích**, které platforma nemá vůbec — karuselu, pásu výhod,
záložkách produktů, fasetových filtrech, doplňcích k produktu s příplatkem. Šablona `retail`
se trefila do nálady a hustoty, ale vedle předlohy je prázdná, protože nemá co zobrazit.

Tahle vlna proto nestaví další vzhled. Staví **stavební prvky**, které si vezme kterákoli
šablona — včetně `base` a `retail` — a teprve nad nimi vzniká třetí šablona.

Co konkrétně referenci odlišuje (z proklikání 2026-09-04):

| prvek | kde | dnešní stav |
|---|---|---|
| Hero karusel (5 slidů, tečky, šipky) | titulka | blok `hero` = jeden obrázek |
| Pás výhod (4× ikona + nadpis + podtitul) | titulka, výpis, detail | neexistuje |
| Záložky produktů nad jednou sekcí | titulka | `product_row` = jeden seznam |
| Asymetrická mozaika kategorií | titulka | `category_grid` je uniformní |
| Fasetové filtry (barva, umístění, rozměr, počet dílů, kolekce) | výpis | jen řazení + „skladem" |
| Doplněk s příplatkem (rám „od 269 Kč") | detail | varianty tohle neumí |
| „Ušetříte 86 Kč" | detail | máme přeškrtnutou cenu a % |
| Počet na stránku, stránkování nahoře i dole | výpis | fixních 24 |
| Kotvy sekcí + sticky lišta s cenou | detail | neexistuje |
| Galerie v lightboxu | detail | náhledy jen přepnou hlavní obrázek |

## Cíle

- [ ] Pět nových typů bloků titulky: `slider`, `usp_strip`, `product_tabs`, `category_mosaic`,
      `banner_grid` — každý editovatelný v administraci a renderovaný **bez JavaScriptu**.
- [ ] Vlastnosti produktu jako datový model a **fasetové filtry** nad výpisem kategorie
      i hledáním, funkční bez JavaScriptu, bezpečné vůči page cache a neškodící SEO.
- [ ] **Doplňky k produktu s příplatkem** — vybrané doplňky se propíšou do košíku, objednávky
      i daňového dokladu jako samostatné položky.
- [ ] Drobnosti nákupní cesty: částka úspory, volba počtu na stránku, stránkování nahoře,
      kotvy sekcí se sticky lištou, galerie v lightboxu.
- [ ] Třetí šablona `katalog` postavená na těchto prvcích — rozvržením a hustotou podle
      dovido.cz, **paletou pod kontrolou nájemce** jako u ostatních šablon.

## Mimo rozsah

- **Wishlist / oblíbené.** Vyžaduje účet nebo trvalou identitu návštěvníka a vlastní obrazovky;
  je to samostatné téma, ne prvek vzhledu.
- **Vícejazyčnost a jazykové přepínače.** Post-MVP, viz specifikace §4.1.
- **Odznaky srovnávačů (Heureka, Favi, Biano).** Jde o účty třetích stran; patří k měření
  (vlna 3.3), ne sem.
- **Sklad na doplňcích.** Doplněk se v první verzi neodepisuje ze skladu.
- **Vlastní ikony pro pás výhod.** Vybírá se z pevné sady ikon platformy, nenahrává se SVG —
  SVG je aktivní obsah a nahrávání by otevřelo stejnou díru, kterou favicon vědomě zavírá.

## Požadavky

### A. Nové bloky titulky

Všechny renderuje server. JavaScript smí být **jen** vylepšení nad hotovým HTML.

| blok | payload | chování bez JS |
|---|---|---|
| `slider` | `slides[]`: obrázek, nadpis, podtitul, CTA (label, url), alt | Všechny slidy v HTML, vodorovný pás se `scroll-snap`; tečky jsou kotvy (`#slide-2`). Ostrůvek přidá šipky a automatické posouvání. |
| `usp_strip` | `items[]`: ikona (z pevné sady), nadpis, podtitul | Statický pás, nic k vylepšování |
| `product_tabs` | `tabs[]`: název, zdroj (kategorie / ruční výběr), počet | Aktivní záložka ze `?zalozka=`, ostatní jsou odkazy. Ostrůvek přepíná bez reloadu. |
| `category_mosaic` | `category_ids[]`, `layout` (`2-2`, `1-2-1`) | Statická mřížka; velikost dlaždice určuje pozice, ne data |
| `banner_grid` | `banners[]`: obrázek, url, alt (2–3 kusy) | Statická mřížka |

`?zalozka=` musí do whitelistu parametrů page cache (`PageCacheKey`), jinak by dvě záložky
sdílely jeden cachovaný záznam.

Limit bloků na homepage zůstává; nové bloky mají vlastní limity položek (slidy 1–8, USP 2–6,
záložky 2–5, bannery 2–3), aby editor nevyrobil stránku, kterou nikdo nedonačte.

### B. Vlastnosti produktu a fasetové filtry

**Model.** Vlastnost je číselník nájemce, ne volný text — filtr nad volným textem je filtr, který
nikdy nic nenajde.

- `product_attributes` — `tenant_id`, `code`, `name`, `position`, `is_filterable`
- `product_attribute_values` — `attribute_id`, `value`, `slug`, `position`
- `product_attribute_value_product` — pivot s `tenant_id`

Kód i slug jsou stabilní; přejmenování názvu nemění URL.

**Dotaz.** `ProductQuery` dostane `attributeValues: array<string, list<string>>` (kód vlastnosti →
slugy hodnot). Uvnitř jedné vlastnosti platí **NEBO**, mezi vlastnostmi **A ZÁROVEŇ** — tak filtry
chápe zákazník i každý konkurenční e-shop.

**URL.** `?vlastnost[barva]=modra,cerna`. Slugy, ne id: id se při reimportu mění a odkaz sdílený
na Facebooku by ukázal jiné zboží.

**Page cache.** Parametr `vlastnost` patří do whitelistu, ale **normalizovaný**: neznámý kód nebo
hodnota se zahodí, hodnoty se seřadí. Bez normalizace by `?vlastnost[barva]=modra,cerna`
a `?vlastnost[barva]=cerna,modra` byly dva záznamy s identickým HTML, a neznámá hodnota by
z každého skenu udělala nový záznam v cache.

**SEO.** Filtrovaná kombinace dostane `noindex` a `canonical` na nefiltrovanou kategorii
(pravidlo storefrontu). Whitelist indexovatelných kombinací je mimo rozsah téhle vlny.

**Administrace.** Číselník vlastností (CRUD) v nastavení katalogu; přiřazení hodnot na kartě
produktu. Vlastnost, kterou používá aspoň jeden produkt, nejde smazat.

### C. Doplňky k produktu s příplatkem

Doplněk je **věc, kterou nájemce prodává** — musí být na faktuře, musí mít sazbu DPH a musí jít
dohledat v objednávce. Proto se nepočítá do ceny řádku, ale zakládá **vlastní řádek**.

- `product_addon_groups` — `tenant_id`, `product_id`, `label`, `required`, `position`
- `product_addons` — `group_id`, `label`, `image_path`, `price`, `tax_rate_id`, `position`
- `cart_item_addons` — `cart_item_id`, `addon_id`, snapshot ceny
- `order_items.parent_item_id` — doplňkový řádek ukazuje na řádek produktu

Množství doplňku se vždy rovná množství nadřazené položky. Skupina označená `required` musí být
vybraná, jinak formulář neprojde — server to kontroluje znovu, nezávisle na UI.

Ceny počítá server, nikdy JavaScript (specifikace §16.3). Doklad tiskne doplněk jako samostatný
řádek s vlastní sazbou.

### D. Drobnosti nákupní cesty

- **Částka úspory** vedle procenta: „Ušetříte 86 Kč", počítaná ze stejné referenční ceny jako
  procento (§ 12a — nejnižší cena za 30 dní), aby obě čísla mluvila o téže věci.
- **Počet na stránku** 24 / 48 / 96, whitelistovaný v `ProductQuery` i v klíči cache.
- **Stránkování nahoře** i dole na výpisu.
- **Kotvy sekcí na detailu** (popis, parametry, recenze) a sticky lišta s cenou a tlačítkem —
  lišta je ostrůvek nad hotovým HTML, bez JS stránka funguje bez ní.
- **Galerie v lightboxu** přes `<dialog>`; bez JS zůstávají náhledy odkazy na obrázky.

### E. Šablona `katalog`

Rozvržení a hustota podle dovido.cz, **paleta zůstává nájemcova** (`--brand-*` nad tokeny, jako
u všech šablon; oranžová je jen výchozí ukázka v manifestu).

- Titulka: karusel → mozaika kategorií → pás výhod → záložky produktů → tlačítko „Další produkty"
- Výpis: chipy podkategorií s náhledy, toolbar (počet, řazení, počet na stránku, stránkování),
  fasetový panel, karty s badgem, hvězdami, dostupností, cenou a tlačítkem Detail, SEO text dole
- Detail: galerie s lightboxem, chipy variant, obrázkové dlaždice doplňků s příplatkem, stepper,
  výrazné tlačítko, dlaždice výhod, kotvy se sticky lištou, parametry vedle popisu

## Akceptační kritéria

1. Nájemce poskládá titulku z nových bloků v administraci, bez zásahu do kódu.
2. Každý nový blok se vyrenderuje a je použitelný s vypnutým JavaScriptem.
3. Zákazník zúží výpis podle dvou vlastností současně, sdílí výsledný odkaz a druhý zákazník
   vidí totéž zboží.
4. Filtrovaná stránka je `noindex` a její canonical míří na nefiltrovanou kategorii.
5. Dvě různá pořadí týchž hodnot filtru sdílí jeden záznam page cache; neznámá hodnota nový
   záznam nevyrobí.
6. Objednávka s doplňkem má doplněk jako samostatný řádek s vlastní sazbou DPH — v košíku,
   v objednávce i na faktuře, a součet sedí na to, co zákazník viděl.
7. Povinná skupina doplňků nejde obejít úpravou formuláře.
8. Celý nákup včetně výběru doplňku projde bez JavaScriptu.
9. Šablona `katalog` prochází stejným smluvním testem jako ostatní šablony.
10. Nájemce s modrou značkou má v šabloně `katalog` modrý e-shop.

## Otevřené otázky

- **Sada ikon pro pás výhod.** Návrh: dvanáct ikon z Lucide zapečených do Blade komponenty,
  stejný přístup jako `NavIcon` v administraci.
- **Doplněk jako produkt, nebo vlastní entita?** Spec volí vlastní entitu s vlastní sazbou.
  Alternativa (doplněk = běžný produkt v skryté kategorii) by dala sklad a doklady zadarmo, ale
  zaplevelila katalog a feedy. Rozhodnutí k revizi, až bude první nájemce prodávat doplněk,
  který má vlastní skladovou evidenci.
- **Fasety a varianty.** Produkt s variantami (velikost) a vlastnostmi (barva) má dva podobné
  číselníky. Sjednocení je lákavé a nebezpečné: varianta mění cenu a sklad, vlastnost ne.
  Zůstávají oddělené.
