# Feedy pro porovnávače — další kroky

Vlna 2.9 dodala feedy pro Heureku a Zboží.cz (`Modules/Feeds/`). Detail: [`docs/as-is/2026-07-28-feedy.md`](../as-is/2026-07-28-feedy.md).

## Google Merchant

Jiný formát než oba české porovnávače: RSS 2.0 s namespace `g:`, vlastní číselník `google_product_category`, jiná pravidla dostupnosti (`in_stock`/`out_of_stock` místo dodací lhůty) a povinné `gtin`/`mpn` u značkového zboží. `FeedItem` a `FeedItemBuilder` se dají použít beze změny, přibude třetí šablona a třetí sloupec v mapování kategorií.

## Glami, Favi a oborové porovnávače

Módní a nábytkářské porovnávače chtějí navíc parametry jako velikost, materiál a barvu ve svých vlastních číselnících. Dává smysl až pro nájemce, kteří v těch oborech skutečně prodávají.

## Import číselníků s našeptávačem

Dnes je mapování volný text a překlep nikdo nezachytí. Stažení číselníků (Heureka jich má přes 4 000) do netenantové tabulky + výběr v adminu by to vyřešilo, ale znamená další sync command s guardem proti prázdnému feedu — precedent je `packeta:sync-points` a jeho práh `feed_min_points`.

## Vyřazení produktu z feedu

Dnes jde do feedu celý viditelný katalog. Per-product přepínač by potřeboval sloupec vlastněný modulem `feeds` (ne na `products`, ať jde modul vypnout beze zbytku) — obdoba `feed_category_mappings`.

## Heureka „Ověřeno zákazníky"

Konverzní pixel na děkovné stránce a odeslání objednávky do dotazníkového API. Jiná věc než feed: dotýká se checkoutu, souhlasu se zpracováním e-mailu a ePrivacy.

## Drobnosti z implementace

- **Invalidace cache při editaci katalogu** — dnes se cache maže jen při uložení v adminu feedů, jinak se změna projeví do hodiny. Patří k page cache, která zatím neexistuje.
- **Hmotnostní pásma dopravy** — blok `DELIVERY` volá `ShippingOptions::available(0)`, takže neodráží pásma ani „doprava zdarma od".
- **Žádný strop počtu položek** — sitemap má limit 50 000 URL a hlasitý log, feed nic obdobného.
- **Feed se renderuje celý do paměti** před uložením do cache; streamování by šlo, ale pak nejde cachovat stejným způsobem.
