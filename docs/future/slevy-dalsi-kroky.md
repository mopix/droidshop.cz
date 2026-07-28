# Slevy — další kroky

Vlna 2.6 dodala kódový kupón a automatické pravidlo nad košíkem (`app/Core/Discounts/`, modul `discounts`). Co zůstává mimo rozsah, viz `docs/superpowers/specs/2026-07-28-vlna-26-slevovy-engine-design.md` sekce „Mimo rozsah" a as-is [`2026-07-28-slevovy-engine.md`](../as-is/2026-07-28-slevovy-engine.md).

## Vlna 2.7 — akční ceny produktu

Přeškrtnutá cena a `sale_price` na produktu a variantě, plus evidence nejnižší ceny za 30 dní podle novely o ochraně spotřebitele (§579 produktové spec). Samostatná vlna, protože mění `ProductCatalog::price()` — cenovou autoritu, přes kterou dnes jede košík, objednávka i doklady. Slevový engine z 2.6 sedí **nad** touto autoritou (druhá vrstva), takže akční cena a kupón/pravidlo se budou muset umět skládat: sleva na kategorii aplikovaná na už zlevněný produkt musí počítat ze skutečné (akční) ceny, ne z původní.

Otevřené otázky pro plán 2.7:
- Kde se ukládá historie ceny pro dokládání „nejnižší cena za posledních 30 dní" — nová tabulka s časovou řadou, nebo denní snapshot?
- Zobrazuje se na produktu jak přeškrtnutá původní cena, tak případná slevová cena z kupónu, nebo se sčítají do jednoho čísla?

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
- `CartPricer::vatBreakdown()` tiše zahazuje poplatek za dopravu/platbu bez `tax_rate_id` — existující dluh z dřívějších vln, dotýká se i slev na dopravu.
- `EloquentDiscountRedemption::release()` píše `released_at` bezpodmínečným UPDATE (ne compare-and-swap) a `OrderEditor::cancel()` nebere zámek na řádku objednávky — teoretický dvojitý odpočet `used_count` při dvou opravdu souběžných vstupních bodech. Stojí za doplnění testu na souběh, až vznikne příležitost.
- Chybí objednávkový test, který naskládá `PERCENTAGE` slevu s další slevou a přímo asertuje `sum(order_discounts.amount) === orders.discount_total` — invariant drží strukturálně, ale bez explicitního pojistkového testu.
