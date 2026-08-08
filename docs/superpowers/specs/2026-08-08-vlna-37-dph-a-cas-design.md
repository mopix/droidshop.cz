# Vlna 3.7 — plátcovství DPH a čas obchodu — zadání

Datum: 2026-08-08 · Stav: **návrh** · Fáze 2

Zadání: konverzace 2026-08-08.

## Proč

Dvě věci, které dnes e-shop tvrdí, aniž by je nájemce nastavil.

**Neplátce DPH vidí všude daň.** `tenants.vat_payer` už existuje a řídí doklady i poplatky za dopravu — ale ne katalog. Neplátce, který zakládá produkt, vyplňuje „Cena s DPH" a povinně vybírá sazbu, kterou nemá jak uplatnit; na detailu produktu se jeho zákazníkovi tiskne „s DPH · bez DPH 826 Kč". To je nepravdivý údaj na veřejné stránce.

**Plátce nemůže zadat cenu bez DPH.** Velkoobchodní ceníky a ceny od dodavatelů jsou bez daně; nájemce je dnes musí přepočítávat v kalkulačce, než je opíše do pole.

**Sazby DPH se nenastavují nikde.** `tax_rates` naplnila migrace (21 %, 12 %, 0 %) a od té doby na ni nikdo nesahal. Až se sazba změní zákonem, není kde to udělat jinak než migrací.

**A nesený dluh z vlny 3.6:** časové pásmo a formát data se ukládají, ale nikde se nepoužívají.

## Rozsah

### 1. Sazby DPH — superadmin (`/superadmin/dph`)

Rozhodnutí vlastníka: sazby zůstávají **platformní**, spravuje je superadmin. Sazba je zákon, ne volba obchodníka; nájemce si u produktu vybírá z hotového seznamu. Jedna změna platí pro všechny e-shopy najednou.

Obrazovka: seznam sazeb (kód, název, procento, výchozí), přidání, editace, změna výchozí. Zatím jen Česká republika — sloupec „země" nevzniká, dokud nebude druhá.

**Sazbu, kterou někdo používá, nejde smazat.** Doklad se na ni odkazuje `tax_rate_id` a snímek řádku nese `rate` — smazaný řádek by z historické faktury udělal doklad, u kterého nejde dohledat, co se počítalo.

**Změna procenta nemění vystavené doklady.** Ty nesou snímek sazby, ne odkaz na živou hodnotu (vlna 1.5). Změna se projeví na budoucích objednávkách.

### 2. Plátce DPH — administrace produktu

| Nájemce | Co vidí na kartě produktu |
|---|---|
| Plátce | Cena s DPH **i** cena bez DPH (dvě propojená pole — vyplním jedno, druhé se dopočítá), výběr sazby. Ukládá se cena s DPH, jako dosud |
| Neplátce | Jen jedno pole „Cena". Žádná sazba, žádné „bez DPH", žádný rozpis |

Sazba uložená u produktu neplátce **zůstává v databázi** (rozhodnutí vlastníka) — jen se nikde nezobrazuje ani neúčtuje. Až se nájemce stane plátcem, jeho katalog dává hned smysl.

Totéž platí pro varianty a hromadné úpravy cen.

### 3. Plátce DPH — storefront

| Nájemce | Detail produktu |
|---|---|
| Plátce | Jako dnes: cena, „s DPH", cena bez DPH, sazba |
| Neplátce | Jen částka. Bez „s DPH", bez ceny bez DPH |

Stejně v košíku a pokladně: **rozpis DPH se neplátci nezobrazí vůbec** (dnes se vykreslí, jen prázdný nebo nulový).

### 4. Dluh z 3.6 — časové pásmo a formáty

Nastavené hodnoty se začnou používat: datum a čas objednávky v administraci, na dokladech pro zákazníka a v účtu zákazníka na storefrontu.

**Neplatí pro strojové formáty.** Pohoda XML, ISDOC, feedy a sitemap tisknou `Y-m-d` podle normy, ne podle vkusu nájemce — tam se nesahá.

## Akceptační kritéria

1. Superadmin založí, upraví a přepne výchozí sazbu; sazbu, kterou někdo používá, nesmaže.
2. Změna procenta se projeví na nové objednávce a **nezmění** už vystavený doklad.
3. Plátce zadá cenu bez DPH a uloží se správná cena s DPH (a naopak).
4. Neplátce nevidí v administraci produktu ani slovo o DPH.
5. Neplátce nevidí DPH ani na detailu produktu, ani v košíku, ani v pokladně.
6. Plátce vidí na detailu produktu vše, co dnes.
7. Přepnutí plátcovství nesmaže žádnou uloženou sazbu.
8. Nastavené časové pásmo a formát data se projeví na datu objednávky v administraci.
9. Strojové formáty (ISDOC, Pohoda, feedy, sitemap) zůstávají `Y-m-d`.

## Omezení a rizika

**Plátcovství je jeden přepínač s dosahem do celé aplikace.** Sedí ve fakturačním profilu a čte ho katalog, košík, doklady i storefront. Musí existovat jedno místo, které odpověď dává — ne `$tenant->vat_payer` roztroušené po šablonách.

**Dvě propojená cenová pole jsou past na haléře.** Ukládá se dál jen cena s DPH; pole „bez DPH" je pomůcka pro zadání, ne druhá pravda. Přepočet dělá server při uložení, ne JavaScript — JS smí jen napovídat (stejné pravidlo jako u varianty ve vlně 2.4).

**Časové pásmo nesmí změnit uložená data.** Ukládá se dál v UTC; mění se jen zobrazení.

## Mimo rozsah

- Sazby DPH pro jiné země než ČR (OSS, reverse charge, přeshraniční prodej).
- Ceny bez DPH na storefrontu jako režim pro B2B nájemce.
- Zpětný přepočet katalogu při změně sazby zákonem.
