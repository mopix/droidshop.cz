# Vlna 3.8 — koruny v administraci, rozměry produktu, obrázky — zadání

Datum: 2026-08-09 · Stav: **návrh** · Fáze 2

Zadání: konverzace 2026-08-09 (nálezy vlastníka na `/admin/m/products/{slug}`).

## Proč

Čtyři nálezy z jedné obrazovky.

**Ceny se zadávají v haléřích.** Vnitřní jednotka se protlačila až do formuláře. Nájemce, který chce prodávat za 1 790 Kč, píše `179000` a doufá, že se nespletl o řád. CSV import přitom od vlny 2.8 pracuje s korunami (`1790,00`), takže administrace je jediné místo, kde se to nedodrželo.

**Produkt nemá rozměry.** Zná jen hmotnost. Zákazník je nenajde nikde a Zásilkovna dostává při podání jen váhu.

**Obrázky nejde seřadit.** Služba `ProductImageService::reorder()` i routa existují od vlny 1.2, ale ovládání k nim nikdy nevzniklo — pořadí je tedy dané pořadím nahrání a nejde změnit.

**Tlačítka Uložit a Smazat produkt se kreslí i tam, kam nepatří.** Sedí uvnitř formuláře, ale mimo panely záložek, takže visí i nad Obrázky a Variantami, kde formulář nic neukládá. Právě proto vlastník přehlédl, že „Nastavit jako hlavní" existuje.

## Rozsah

### 1. Koruny v celé administraci

Rozhodnutí vlastníka: **celá administrace**, ne jen produkty.

| Kde | Pole |
|---|---|
| Produkt | cena, akční cena, nákupní cena, cena bez DPH |
| Varianty | cena, akční cena, cena bez DPH |
| Doprava | cena, práh dopravy zdarma |
| Platby | poplatek |
| Slevy | pevná částka, minimální hodnota košíku |
| Pokladna | minimální hodnota objednávky |

Ukládá se dál v haléřích. Převod sedí **na hranici požadavku**, ne v JavaScriptu — stejné pravidlo jako u ceny bez DPH z vlny 3.7.

Formát vstupu: `1790`, `1790,50` i `1790.50`. Prázdné pole zůstává prázdné (ne nula).

### 2. Rozměry produktu

Délka × šířka × výška v milimetrech, nepovinné.

Rozhodnutí vlastníka, k čemu slouží:

- **Zákazníkovi na detailu produktu** — nový blok „Parametry" (rozměry + hmotnost). Dnes technické parametry nemá kde být; zákazník je najde jen v popisu, pokud si je tam nájemce napíše.
- **Zásilkovně při podání** — dnes posíláme jen hmotnost. U nadrozměrné zásilky rozhodují rozměry o ceně i o přijetí.

Varianty vlastní rozměry nedostávají (stejně jako dnes nemají vlastní hmotnost) — to je `docs/future/`.

### 3. Obrázky

- **Řazení tlačítky** (nahoru/dolů), ovladatelné klávesnicí. Služba i routa existují.
- **Plocha pro přetažení** souborů jako **doplněk** k tlačítku „Vybrat soubor", nikdy jako jediná cesta.
- „Nastavit jako hlavní" zůstává, jen bude vidět.

### 4. Rozvržení obrazovky

Tlačítka Uložit a Smazat produkt se zobrazí jen na záložkách, kde formulář opravdu něco ukládá.

## Akceptační kritéria

1. Nájemce zadá `1790,50` a uloží se `179050` haléřů.
2. Prázdné pole ceny zůstane prázdné, ne nula.
3. Cena zobrazená ve formuláři po znovunačtení je táž, jakou nájemce zadal.
4. Rozměry jde vyplnit i nechat prázdné.
5. Vyplněné rozměry vidí zákazník na detailu; nevyplněné se nezobrazí vůbec.
6. Zásilkovna dostane rozměry, pokud jsou vyplněné.
7. Obrázky jde přeskládat tlačítky a pořadí se projeví na storefrontu.
8. Přetažení souboru nad plochu ho nahraje; tlačítko „Vybrat soubor" funguje dál.
9. Uložit/Smazat se nekreslí na záložkách Obrázky a Varianty.

## Omezení a rizika

**Převod jednotek je past na peníze.** Desetinné číslo se nikdy nesmí dostat do uložené hodnoty: převádí se řetězec na celé číslo haléřů, ne float na float. Chybný převod je tichá chyba v ceně, kterou nikdo nenajde dřív než zákazník.

**Formát vstupu je český.** Desetinná čárka je to, co nájemce napíše; tečka se přijímá taky, protože ji vrací kopírování z tabulky.

**CSV formát se nemění.** Už koruny používá; tahle vlna k němu administraci jen srovnává.

**Rozměry na storefrontu jsou obsah v cachované stránce** — per produkt, ne per návštěvník, takže cache-safe.

## Mimo rozsah

- Rozměry per varianta.
- Drag&drop řazení obrázků tažením (tlačítka jsou závazná cesta, tažení by bylo nadstavba).
- Volitelné parametry produktu jako obecný číselník (materiál, barva…).
- Přepočet měn.
