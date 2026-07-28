# Vlna 2.8 — CSV import a export produktů — design

Datum: 2026-07-28 · Fáze 2 · Navazuje na: `products` (`ProductWriter`, `VariantWriter`, `ProductsLimitCounter`), `categories` (strom kategorií), `docs` (`VatCsvWriter` jako CSV precedent), jádro (`FileStorage`, `LimitsService`, tenant-aware fronta).

**Status:** approved

## Cíl

Nájemce naplní a udržuje katalog hromadně: stáhne si svůj katalog jako CSV, upraví ho v Excelu a nahraje zpět. Import zakládá i aktualizuje produkty a varianty, zařazuje je do existujících kategorií a respektuje limit tarifu. Export produkuje **přesně ten formát, který import přijme** (round-trip).

Bez toho nájemce s větším katalogem nezačne — dnes musí každý produkt naklikat ručně.

## Mimo rozsah (→ `docs/future/`)

- **Obrázky z URL** — server by tahal cizí adresy (SSRF plocha), k tomu timeouty, limity velikosti a MIME kontrola; samostatná vlna
- **Mapování sloupců v adminu** (libovolný dodavatelský feed) — celá další obrazovka a uložené profily mapování
- XML / XLSX vstup, plánovaný pravidelný import z feedu dodavatele
- Import zákazníků, objednávek a dokladů
- Hromadné operace nad výběrem v adminu (bez CSV)
- Mazání produktů importem — soubor produkt nikdy neodstraní, jen zakládá a aktualizuje

## Role

| Role | Co smí |
|------|--------|
| `TENANT_ADMIN` s `products.edit` | spustit import, vidět historii běhů, stáhnout chybový report, exportovat katalog |
| `TENANT_ADMIN` s `products.costs` | navíc dostane v exportu sloupec nákupní ceny |
| `TENANT_STAFF` | nic navíc (práva mu lze udělit, až role vznikne) |
| `SUPERADMIN` | **nic navíc** — katalog je věc nájemce |
| `CUSTOMER` / anonym | nic; celá oblast je admin, `noindex` |

Nové právo `products.import` **nezavádíme** — nehlídalo by nic, co `products.edit` už nehlídá, a klamná autorizační plocha je horší než chybějící (stejný závěr jako u `packeta.manage` ve vlně 2.5). Write-freeze na `suspended`/`past_due` platí přes `CheckTenantStatus` beze změny.

## Rozhodnutí z brainstormingu (závazná)

| Otázka | Rozhodnutí |
|--------|-----------|
| Rozsah | Produkty + varianty + zařazení do **existujících** kategorií; obrázky ne |
| Klíč pro aktualizaci | **SKU**; prázdné nebo neznámé SKU zakládá nový produkt |
| Duplicitní SKU | Chyba řádku (v souboru i proti DB), ne tichý výběr prvního |
| Běh | **Fronta** + záznam `product_imports` s protokolem; na `sync` driveru proběhne v requestu |
| Chybová politika | **Řádek po řádku**, chybné do reportu; navíc **suchý běh** („jen zkontrolovat") |
| Formát | **Pevná hlavička**, pořadí sloupců volné; export produkuje týž formát |
| Kategorie | Neexistující cesta = **chyba řádku**, import kategorie nezakládá |
| Limit tarifu | Platí; vyčerpaná kvóta shodí **jen svůj řádek**, ne celý běh |
| Zápis | Výhradně přes `ProductWriter` / `VariantWriter` |
| Transakce | **Jedna transakce na řádek**, ne kolem celého běhu |

## Formát souboru

Oddělovač `;`, kódování UTF-8 s BOM, desetinná **čárka** u peněz (česká lokalizace Excelu). Parser přijme i `,` jako oddělovač a `.` jako desetinnou tečku — import má být shovívavý ke vstupu, export přísný na výstup.

### Sloupce

| Sloupec | Typ řádku | Význam |
|---|---|---|
| `typ` | oba | `produkt` \| `varianta` |
| `sku` | oba | Klíč pro aktualizaci; u varianty její vlastní SKU |
| `varianta_rodic_sku` | varianta | SKU produktu, pod který varianta patří |
| `varianta_hodnoty` | varianta | `Velikost:M\|Barva:černá` — osy a hodnoty |
| `nazev` | produkt | Povinný při zakládání |
| `slug` | produkt | Prázdné = odvodí se z názvu |
| `stav` | produkt | `koncept` \| `aktivni` \| `skryty` |
| `cena` | oba | Hrubá cena v korunách (`1 290,00`) |
| `akcni_cena`, `akce_od`, `akce_do` | oba / produkt | Akční cena (vlna 2.7); okno jen na řádku produktu |
| `dph` | produkt | Sazba v procentech (`21`), musí existovat v `tax_rates` |
| `ean` | oba | Validace přes existující `Ean` rule |
| `hmotnost_g` | produkt | Celé gramy |
| `sklad_sleduje`, `sklad_ks`, `sklad_politika` | oba | `ano`/`ne`, celé číslo, `skryt`/`vyprodano`/`na_objednavku` |
| `kategorie` | produkt | `Elektronika > Klávesnice\|Akce` — cesty oddělené `\|` |
| `vyrobce` | produkt | Název; neexistující se založí (na rozdíl od kategorií nejde o strom) |
| `kratky_popis`, `popis` | produkt | HTML v `popis` se sanitizuje při zápisu |
| `seo_titulek`, `seo_popis` | produkt | |
| `nakupni_cena` | produkt | **Jen v exportu a jen s `products.costs`**; v importu se bez práva ignoruje |

Prázdná buňka u **aktualizace** znamená „neměnit", ne „vymazat". Vymazání hodnoty se dělá v adminu — jinak by nedopatřením prázdný sloupec v Excelu smazal popisy celého katalogu.

## Architektura

Vše v modulu `products`, každá třída s jednou odpovědností:

| Třída | Odpovědnost |
|---|---|
| `Support/ProductCsvSchema` | Jediná pravda o sloupcích; čte ji import i export, takže se round-trip nemůže rozejít |
| `Support/ProductCsvParser` | Surové řádky → normalizovaná pole (BOM, oddělovač, desetinná čárka, mapa hlaviček) |
| `Support/ProductRowValidator` | Validace jednoho řádku → `list<string>` chyb |
| `Services/ProductImporter` | Aplikuje jeden řádek přes `ProductWriter`/`VariantWriter`; drží upsert dle SKU |
| `Support/ProductCsvExporter` | Streamuje katalog ve stejném formátu (generátor, precedent `VatCsvWriter`) |
| `Jobs/RunProductImport` | Čte soubor po dávkách, volá importer, aktualizuje záznam běhu |
| `Models/ProductImport` | Záznam běhu |

### Tabulka `product_imports`

| Sloupec | Typ | Poznámka |
|---|---|---|
| `id` | `id` | |
| `tenant_id` | FK `tenants` cascade | |
| `user_id` | FK `users` nullOnDelete, nullable | kdo import spustil |
| `original_name` | string | název nahraného souboru |
| `path` | string | privátní disk |
| `status` | string(16) | `pending` \| `running` \| `done` \| `failed` |
| `dry_run` | boolean | suchý běh nic nezapisuje |
| `rows_total`, `rows_ok`, `rows_failed` | unsignedInteger | průběžně aktualizované |
| `report_path` | string nullable | chybové CSV, vzniká až jsou chyby |
| `started_at`, `finished_at` | timestamp nullable | |
| `timestamps` | | |

Index `(tenant_id, created_at)`.

### `VariantWriter::upsertVariant()`

Dnešní `generate()` umí jen kartézský součin všech os. Import zakládá **konkrétní kombinaci**, proto přibude:

```php
public function upsertVariant(Product $product, array $optionValueIds, array $attributes): ProductVariant
```

Osy a hodnoty, které v produktu nejsou, se založí (`addOption`/`addValue`) — na rozdíl od kategorií nejde o sdílený strom, ale o vlastnost jednoho produktu, takže překlep poškodí jen ten produkt a je vidět na jeho detailu.

## Chování

### Import

1. Upload → validace (přípona `csv`/`txt`, limit velikosti, čitelná hlavička) → soubor na `tenant_private` → záznam `pending` → dispatch jobu.
2. Job čte po dávkách. Každý řádek: parser → validator → (pokud `dry_run` false) importer v **jedné transakci**.
3. Chybný řádek se přeskočí a zapíše do reportu s číslem řádku, SKU a důvodem. Report je CSV se stejnou hlavičkou + sloupec `chyba`, takže nájemce ho opraví a nahraje rovnou zpět.
4. Na konci `done` (i když nějaké řádky selhaly) nebo `failed` (soubor nešel přečíst).

Upsert dle SKU: existující SKU → aktualizace, prázdné nebo neznámé → nový produkt. Duplicitní SKU v souboru i proti DB je chyba řádku.

### Export

Streamovaná odpověď (žádné držení celého katalogu v paměti), stejná hlavička, produkty i varianty. Textové sloupce neutralizované proti formula injection; peněžní vědomě ne, aby se nezalomily na text (stejný kompromis jako `VatCsvWriter`).

## Akceptační kritéria

1. Nájemce nahraje CSV a založí produkty; běh je vidět v historii se součtem úspěšných a chybných řádků.
2. Opakovaný import téhož souboru se stejnými SKU **aktualizuje**, nezaloží duplikáty.
3. Chybný řádek nezastaví běh; chybové CSV nese číslo řádku a srozumitelný důvod.
4. Suchý běh nezapíše do katalogu nic a vrátí stejný protokol jako ostrý.
5. Export → import beze změny souboru nechá katalog **beze změny** (round-trip).
6. Řádek varianty založí variantu pod produktem podle `varianta_rodic_sku` včetně os a hodnot.
7. Neexistující cesta kategorie je chyba řádku; strom se importem nemění.
8. Vyčerpaný limit tarifu shodí jen svůj řádek, zbytek importu doběhne.
9. Import zapíše cenu do historie (`product_price_history`), takže Omnibus evidence z 2.7 zůstává úplná.
10. Uživatel bez `products.costs` nedostane v exportu nákupní cenu.
11. Nahraný soubor ani report nejsou dostupné bez přihlášení; cizí běh vrací 404.
12. HTML v popisu se při importu sanitizuje.

## Testy

- **Unit:** parser (BOM, `;` i `,`, desetinná čárka i tečka, přeházené pořadí sloupců), validator (chybějící povinné pole, neexistující sazba DPH, akční cena nad běžnou), schéma.
- **Feature:** upsert dle SKU, varianta na existující produkt, neexistující kategorie, limit tarifu, suchý běh, sanitizace HTML, zápis do historie ceny.
- **Round-trip:** export → import → katalog shodný. Tenhle test drží formát v konzistenci mezi oběma směry.
- **Bezpečnost:** formula injection v exportu, nákupní cena bez práva, tenant izolace běhu i dat, 404 na cizí report.

## Technické poznámky

- Migrace: `product_imports`.
- Dotčené soubory: `Modules/Products/{Support,Services,Jobs,Models,Http}`, `Modules/Products/routes/admin.php`, `resources/js/Pages/Modules/Products/Import.vue`, `Modules/Products/module.json` (nav položka).
- Job je tenant-aware (precedent `ExpireUnpaidOrder`); na `sync` driveru proběhne inline, což je pro dev v pořádku.
- Velikost souboru a velikost dávky jako konfigurace v `config/products.php` (nový soubor), ne natvrdo v kódu.

## Reference

- Produktová spec: `docs/specs/2026-07-17-eshop-platforma-specifikace.md` §6.2 (katalog), §16.1
- CSV precedent: `Modules/Docs/Support/VatCsvWriter.php`
- Předchozí vlna: `docs/superpowers/specs/2026-07-28-vlna-27-akcni-ceny-design.md`
- Plán: `docs/superpowers/plans/2026-07-28-vlna-28-csv-import.md`
- As-is (po dokončení): `docs/as-is/2026-07-28-csv-import.md`
