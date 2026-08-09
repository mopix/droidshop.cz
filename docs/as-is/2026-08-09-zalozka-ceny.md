# As-is: záložka Ceny na kartě produktu (vlna 3.9)

Datum: 2026-08-09 · Verze: **0.44.0** · Větev: `feature/vlna-39-zalozka-ceny`

Zadání: [plán](../superpowers/plans/2026-08-09-vlna-39-zalozka-ceny.md) (konverzace 2026-08-09)

## Co bylo špatně

Záložka Ceny míchala pole bez ladu: cena s DPH, pod ní cena bez DPH, pod tím sazba, někde mezi tím akční cena a úplně dole nákupní. Nájemce přemýšlí opačně — vezme cenu bez daně, přidá sazbu a vyjde mu cena, kterou vidí zákazník.

Chyběly dvě věci úplně: **sleva v procentech** a **vlastní sazba pro nákupní cenu**.

## Kde to leží

| Vrstva | Soubory |
|---|---|
| Sloupce | migrace `products.purchase_tax_rate_id`, `products.sale_percent` |
| Model | `Modules/Products/Models/Product.php` (`purchaseRate()`) |
| Přepočet | `Modules/Products/Http/Requests/StoreProductRequest.php` |
| Obrazovka | `resources/js/Pages/Modules/Products/Show.vue`, `ProductAdminController` |

## Nové rozvržení

Tři sekce pod sebou, v každé pořadí **bez DPH → daň (sazba) → s DPH**:

1. **Prodejní cena**
2. **Nákupní cena** — jen s právem `products.costs`, vlastní sazba s volbou „Stejná jako u prodeje"
3. **Akce** — akční cena **nebo** sleva v %, plus okno kampaně

Neplátce DPH vidí v každé sekci jediné pole s částkou.

## Rozhodnutí, která stojí za připomenutí

**Procento se ukládá, ne jen přepočítá.** To je celý smysl: když nájemce zdraží, sleva zůstane dvacetiprocentní místo toho, aby se tiše proměnila na dvanáctiprocentní. Uložená částka v `sale_price` zůstává tím, co čte katalog, doklady i zákonná evidence nejnižší ceny za 30 dní z vlny 2.7.

**Zadaná částka vyhrává nad procentem** a procento se přitom zahodí. Ručně napsaná částka je vlastní pokyn; přepočítávat z procenta při každém uložení by cenu posouvalo pokaždé, kdy někdo otevře formulář a stiskne Uložit, aniž by cokoli změnil. Stejné pravidlo, jaké platí pro cenu s DPH vs. bez ní.

**Rozsah 1–99 %.** Sto procent je „zdarma", což je jiný nástroj — slevový engine umí objednávku za 0 Kč settlovat bez brány — a nula není sleva vůbec.

**Nákupní cena má vlastní sazbu s dědičností.** Dodavatel může účtovat jinou sazbu, než nájemce prodává (dovoz, zboží přeřazené mezi sazbami), a přepočet prodejní sazbou by hlásil marži, která je tiše špatně. Prázdné pole znamená „ta obvyklá", ne „bez daně".

**Přepočet z procenta jde přes `ProductWriter`**, takže se zapíše do `product_price_history`. Kdyby ho obešel, přestala by platit zákonná evidence podle § 12a — to má vlastní regresní test.

## Testy

| Soubor | Co hlídá |
|---|---|
| `tests/Feature/Modules/Products/PriceTabTest.php` | 7 testů: procento → částka, zdražení drží procento, částka vyhrává, rozsah 1–99, nákupní sazba, dědičnost, historie ceny |
| `e2e/tests/product-prices.spec.ts` | pořadí polí měřené geometricky, neplátce vidí jen částky, náhled procenta |

Celá sada: 2164 PHPUnit, 46 Playwright.

**Co našel prohlížeč a co ne.** Při odstraňování mrtvého náhledu ceny bez DPH jsem smazal i dvě funkce, které šablona pořád používá. Stránka se rozpadla na `korunas is not a function` a pak tiskla `NaN Kč` — obojí odhalila až prohlížečová sada, ne čtení diffu ani PHPUnit. Kdyby tahle vlna neměla e2e, šlo by to na produkci.

## Technický dluh

1. **Procento se přepočte jen při uložení formuláře.** Změna ceny přes CSV import nebo přes variantu akční cenu nepřepočítá — import procento neposílá, takže zůstane u částky, která z něj kdysi vyšla.
2. **Varianty procento nemají.** Mají jen vlastní částku, jako dosud.
3. **Nákupní sazba se nepromítá nikam než do přepočtu pole.** Marže se nikde nepočítá, takže sloupec zatím slouží jen k tomu, aby nájemce zadal správnou částku.

## Pre-deploy

- [ ] `php artisan migrate` (dva nové sloupce na `products`)
- [ ] `npm run build`
