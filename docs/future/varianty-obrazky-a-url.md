# Budoucí rozšíření variant produktu (po vlně 2.4)

Vlna 2.4 dodala víceosé varianty (Velikost × Barva) s vlastní cenou, skladem a SKU — server-authoritative výběr bez JS, JS ostrůvek jen pro živý přepočet. Spec (`docs/superpowers/specs/2026-07-26-vlna-24-varianty-produktu-design.md`) i plán vlnu záměrně ohraničily; níže je pět položek, které zůstaly mimo rozsah, a proč je teď nemá smysl řešit spolu s MVP variant.

## Obrázky per varianta

**Proč teď ne:** vyžaduje vazbu `product_images` ↔ `product_option_values` (typicky jedna z os, obvykle barva) a přepínání galerie při výběru — to je druhá, nezávislá datová vrstva nad tím, co vlna 2.4 stavěla. `ProductImageService` dnes zná jen produkt, ne kombinaci. MVP varianty zvládne s jedním sdíleným setem fotek na produkt; e-shop s barevnými variacemi bude muset fotky popsat textem/labelem, dokud tahle vrstva nepřibude.

**Co by to vyžadovalo:** nový pivot nebo sloupec `product_images.option_value_id` (nullable — obrázek buď patří ke konkrétní hodnotě osy, nebo je společný), úprava galerie na storefrontu (JS ostrůvek by musel přepnout aktivní sadu obrázků při změně výběru, ne jen cenu), admin UI pro přiřazení obrázku k hodnotě osy.

## URL per varianta

**Proč teď ne:** samostatná indexace variant znamená duplicitní obsah (stejný produkt, jiná URL) a nutnost řešit canonical strategii, redirecty a sitemap položky navíc — to je vlastní SEO téma, ne rozšíření datového modelu. Rozhodnutí 2026-07-19 drží URL produktu plochou (`/produkt/{slug}`) záměrně kvůli stabilitě při reorganizaci katalogu; přidání `?varianta=` nebo `/produkt/{slug}/{varianta-slug}` by tuhle stabilitu narušilo bez jasného SEO přínosu pro MVP katalog.

**Co by to vyžadovalo:** rozhodnutí o URL schématu (query param vs. path segment), `canonical` mířící buď na produkt, nebo na variantu (ne obojí), JSON-LD `Offer.url` per varianta, sitemap položky per kombinace (může být stovky URL na jeden produkt u víceosých matic — zvážit, jestli to vůbec chceme indexovat).

## Hmotnost per varianta

**Proč teď ne:** dopravné dnes počítá váhu z `products.weight_g` (modul `shipping` čte přes `CatalogProduct`), a varianta v datovém modelu vlny 2.4 vědomě nemá `weight_g` (stejně jako nemá vlastní sazbu DPH — spec §Datový model, `product_variants`). Přidání hmotnosti na variantu znamená zásah do `shipping` modulu (výpočet ceny dopravy dnes bere jednu hodnotu na produkt, ne na řádek košíku s variantou) — mimo rozsah vlny, která se týkala jen `products`/`checkout`/`orders`.

**Co by to vyžadovalo:** nový nullable sloupec `product_variants.weight_g` (fallback na `products.weight_g` jako u ceny), úprava `CartShape`/váhového výpočtu v `shipping`, aby uměl číst hmotnost per řádek košíku (dnes pravděpodobně sčítá váhu podle produktu × množství).

## Filtrování katalogu podle osy

**Proč teď ne:** „zobraz jen červené" potřebuje fasetovou vyhledávací vrstvu (agregace dostupných hodnot os napříč aktuálně filtrovaným výpisem, s počty), ne prostý `WHERE`. Katalog dnes stejně vyhledává přes `products.search_text` + `LIKE` (rozhodnutí 2026-07-20 — vědomá odchylka od fulltextu kvůli češtině a SQLite v testech), a fasetový filtr nad `product_variant_values` by potřeboval vlastní indexovanou vrstvu (typicky denormalizovaný `product_id × option_value_id` s agregací počtů), aby byl použitelný na desítkách tisíc produktů. Přidávat to teď by znamenalo stavět druhou vyhledávací technologii vedle `LIKE`, dřív než první stihla dokázat, že nestačí.

**Co by to vyžadovalo:** indexovaná fasetová tabulka nebo přechod na plnohodnotný search engine (Meilisearch/Typesense — zvažováno už pro `LIKE` limit), UI filtru v kategorii s server-side fallbackem (query parametry, jak vyžaduje `.claude/rules/storefront-rendering.md` pro filtry obecně), agregace „kolik produktů má tuto hodnotu" v rámci aktuálního filtru.

## Hromadný import variant

**Proč teď ne:** modul `products` dnes nemá obecný hromadný import produktů vůbec (CSV import je v STATUS.md veden jako chybějící u samotného produktu, ne jen u variant). Stavět import specificky pro varianty dřív, než existuje import pro produkty, by znamenalo řešit stejné problémy (parsing, validace řádek po řádku, mapování sloupců, rollback při chybě) dvakrát a nekonzistentně. Až vznikne obecný CSV import produktů, varianty se do něj přidají jako rozšíření formátu (řádek s `parent_sku` + hodnoty os), ne jako samostatný dovozní kanál.

**Co by to vyžadovalo:** návrh CSV/XLSX formátu, který umí popsat matici (parent produkt + N řádků kombinací), validace kolizí s ručně vytvořenými variantami, dry-run náhled před zápisem, zpracování ve frontě (velké katalogy).

## Související již vyřešené (ne budoucí)

Pro úplnost — vlna 2.4 už řeší „nastavit cenu/sklad všem variantám najednou" jako otevřenou mezeru (viz `docs/as-is/2026-07-26-varianty-produktu.md` sekce Technický dluh), ne jako odloženou položku do budoucna — mřížka ukládá po řádcích a hromadná akce je malé UI rozšíření nad existujícím endpointem, ne nová datová vrstva.
