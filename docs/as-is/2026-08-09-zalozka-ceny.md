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

## Dodatek 2026-08-09 (v0.44.1) — výpis produktů

Stejná logika dotažená do výpisu `/admin/m/products`, který dosud nesl jediný sloupec „Cena s DPH":

| Nájemce | Sloupce |
|---|---|
| Plátce | Nákupní cena · Akční cena · Koncová cena bez DPH · Daň (sazba) · Koncová cena (s DPH) |
| Neplátce | Nákupní cena · Akční cena · Koncová cena |

**Koncová cena je pultová, ne efektivní** (rozhodnutí vlastníka). Akce má vlastní sloupec, takže dva sloupce s týmž číslem by zakryly, z čeho se sleva počítá.

**Sloupec bez DPH počítá z pultové ceny, ne přes `Product::netPrice()`** — ta vrací čistou cenu z *efektivní* částky (vlna 2.7), takže by si sousední sloupce odporovaly v tom, kterou cenu vlastně popisují.

**Nákupní cena je oprávnění, ne skrytý sloupec.** Bez `products.costs` hodnota vůbec neopustí server, takže se z výpisu nedá udělat zadní vrátka k marži. Nevyplněná částka se zobrazuje jako pomlčka, ne jako 0 Kč.

Testy: `tests/Feature/Modules/Products/ProductListingColumnsTest.php` (6 testů včetně regrese na N+1 a na neplátce).

## Dodatek 2026-08-09 (v0.44.3) — sloučené sloupce a živý přepočet

**Výpis** dostal EAN a každá cenová dvojice se složila do jednoho sloupce: čistá cena nahoře šedě, hrubá pod ní. Dva samostatné sloupce dělaly z tabulky něco, co se muselo rolovat, kvůli údaji, který nájemce čte jako jeden fakt. Neplátce vidí u každé ceny jedinou částku.

**Na detailu se obě poloviny páru hýbou spolu**, jak se do nich píše, a přepnutí sazby přepočítá hrubou cenu z čisté vedle ní.

**Prohlížeč ale jen ukazuje.** Která polovina se editovala, jde na server jako `price_source` / `purchase_price_source` a převod se dělá **z ní**. Do téhle vlny stačilo, že druhé pole zůstalo prázdné — jenže teď jsou vyplněná obě, takže „to druhé je prázdné" už neříká, co bylo myšleno, a nechat rozhodnout prohlížeč by znamenalo, že se ukládá jeho zaokrouhlení. Chybějící značka dál znamená „hrubá cena", což je to, co posílá CSV import i každý starší volající.

Testy: 4 nové v `PriceTabTest` (editovaná polovina rozhoduje, chybějící značka, nákupní pár) a e2e scénář, že se dvojice hýbe.

## Technický dluh

1. **Procento se přepočte jen při uložení formuláře.** Změna ceny přes CSV import nebo přes variantu akční cenu nepřepočítá — import procento neposílá, takže zůstane u částky, která z něj kdysi vyšla.
2. **Varianty procento nemají.** Mají jen vlastní částku, jako dosud.
3. **Nákupní sazba se nepromítá nikam než do přepočtu pole.** Marže se nikde nepočítá, takže sloupec zatím slouží jen k tomu, aby nájemce zadal správnou částku.

## Pre-deploy

- [ ] `php artisan migrate` (dva nové sloupce na `products`)
- [ ] `npm run build`
