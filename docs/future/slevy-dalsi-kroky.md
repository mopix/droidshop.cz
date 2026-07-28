# Slevy — další kroky

Vlna 2.6 dodala kódový kupón a automatické pravidlo nad košíkem (`app/Core/Discounts/`, modul `discounts`). Co zůstává mimo rozsah, viz `docs/superpowers/specs/2026-07-28-vlna-26-slevovy-engine-design.md` sekce „Mimo rozsah" a as-is [`2026-07-28-slevovy-engine.md`](../as-is/2026-07-28-slevovy-engine.md).

## Akční ceny produktu — HOTOVO (vlna 2.7)

Dodáno: `sale_price` + okno kampaně na produktu i variantě, časová řada `product_price_history` s plánovanými intervaly, `LowestPriceCalculator` a povinný řádek o nejnižší ceně za 30 dní. Detail: [`docs/as-is/2026-07-28-akcni-ceny.md`](../as-is/2026-07-28-akcni-ceny.md).

Obě otevřené otázky zodpovězeny: historie je **časová řada změn** (ne denní snapshot) a na produktu se zobrazuje akční cena, přeškrtnutá **nominální** cena a zákonná 30denní reference; sleva z kupónu se do tohoto páru nemíchá (kupón se počítá z akční ceny až v košíku).

Co ze slevové oblasti zůstává otevřené:

- **Řádek nejnižší ceny ve výpisu kategorie** — dnes je povinný údaj jen na detailu produktu, ve výpisu je pouze přeškrtnutá cena. Právně nenulové riziko, čeká na právní review.
- **Omnibus u automatických pravidel z 2.6** — veřejně oznámené pravidlo „−10 % na kategorii" je věcně také oznámení slevy, ale nemá `sale_price` a nesahá na historii ceny. Chce vlastní rozbor.
- **Hromadné nastavení akcí** (vybrat N produktů, zlevnit o X %) a import cen z CSV.
- **Filtr „ve slevě" ve storefront katalogu** — otevírá fasety a canonical/`noindex` politiku filtrů, kterou zatím nemáme.
- **Akční ceny ve feedech** Heureka/Zboží/Google, až feedy vzniknou.

## Dárkové poukazy

Poukaz s předplaceným zůstatkem je jiný účetní i daňový režim než procentní/pevná sleva — poukaz je v podstatě platební prostředek (zákazník za něj předem zaplatil), zatímco kupón jen snižuje cenu. Vyžaduje:
- vlastní evidenci zůstatku (částečné čerpání přes víc objednávek),
- účetní řešení přijaté platby předem (výnos příště vs. teď),
- pravděpodobně samostatný modul, ne rozšíření `discounts`.

## Dávkové generování kódů

`discounts.code` je dnes jeden řádek = jeden kód, ručně zadaný v adminu. Dávkové generování (import/export CSV, N unikátních jednorázových kódů pod jednou kampaní) potřebuje:
- rodičovskou entitu „kampaň" nad víc řádky `discounts`, nebo
- `usage_limit = 1` po řádku a hromadný insert — jednodušší, ale ztrácí společné reporty čerpání napříč kódy kampaně.

## Sleva na dopravu procentem

Vlna 2.6 umí jen `free_shipping` (nulová doprava). Procentní sleva na dopravu (např. „−50 % na dopravu") by potřebovala nový `type` a úpravu `CartPricer::shippingCost()`, který dnes zná jen booleovský příznak `freeShipping`, ne částečnou slevu.

## Kombinace více kupónů najednou

`carts.coupon_code` je jeden sloupec (`string nullable`). Zákazník smí zadat nejvýš jeden kód; víc kupónů najednou by změnilo na `many-to-many` přes novou pivotní tabulku a `DiscountEvaluator` by musel řešit pořadí a kombinovatelnost mezi kupóny navzájem, ne jen kupón × automatické pravidlo jako dnes.

## Přepočet slevy při editaci objednávky v adminu

Rozhodnutí 2026-07-28: admin edit **zachovává** podíl slevy na přeživších řádcích a znovu odvozuje `orders.discount_total`, engine se nespouští podruhé. Nescaluje dobře, když admin změní množství na řádku (sleva zůstává stejná v haléřích, jen ořezaná na nový součet řádku) a nereaguje, pokud se mezitím kupón deaktivoval nebo změnil podmínky. Skutečný přepočet by musel:
- rozhodnout, jestli editace smí sáhnout na `discount_redemptions` (dnes se při editaci nedotýká),
- ošetřit případ, kdy přepočet zvýší slevu nad rámec toho, co bylo v okamžiku placení schváleno zákazníkem.

## Odložené drobnosti z implementace (k případnému carry-forward)

Z `progress.md` ledgeru — menší nálezy z code review, které nebránily uzavření vlny, ale stojí za zvážení v budoucí práci na slevách:

- `discounts.currency` je nepoužitý sloupec (MVP je jen CZK) — buď zahodit, nebo časem validovat proti měně košíku, až přibude vícemenová podpora.
- `checkout/shipping.blade.php` u každé radio volby dopravy/platby tiskne nominální cenu i při běžící slevě na dopravu zdarma — vizuálně rozporuplné vedle přeškrtnuté ceny v rekapitulaci; oprava je menší UI úprava, ne architektonická změna.
- ~~`CartPricer::vatBreakdown()` tiše zahazuje poplatek za dopravu/platbu bez `tax_rate_id`~~ — **uzavřeno vlnou 2.7**: sazba je pro plátce DPH povinná a existující metody ji dostaly backfillem.
- `EloquentDiscountRedemption::release()` píše `released_at` bezpodmínečným UPDATE (ne compare-and-swap) a `OrderEditor::cancel()` nebere zámek na řádku objednávky — teoretický dvojitý odpočet `used_count` při dvou opravdu souběžných vstupních bodech. Stojí za doplnění testu na souběh, až vznikne příležitost.
- Chybí objednávkový test, který naskládá `PERCENTAGE` slevu s další slevou a přímo asertuje `sum(order_discounts.amount) === orders.discount_total` — invariant drží strukturálně, ale bez explicitního pojistkového testu.
