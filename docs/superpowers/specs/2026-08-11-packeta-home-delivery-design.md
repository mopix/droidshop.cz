# Zásilkovna — doručení na adresu

**Datum:** 2026-08-11
**Status:** approved
**Související plán:** `docs/superpowers/plans/2026-08-11-packeta-home-delivery.md`

## Kontext

Vlna 2.5 přinesla Zásilkovnu jen na výdejní místa. Zákazník, který chce zásilku
domů, ji u e-shopu na této platformě nedostane vůbec — a to je většina objednávek
běžného e-shopu.

Zásilkovna přitom doručení na adresu umí: zprostředkovává ho přes partnerské
dopravce (PPL, DPD, GLS, Česká pošta a další podle země). Nájemce k tomu
nepotřebuje novou smlouvu ani nové přihlašovací údaje — stačí mít službu zapnutou
u Zásilkovny, kterou už používá.

> **Oprava 2026-08-11 (nález při implementaci):** tahle spec původně tvrdila, že
> Z-BOXy už v katalogu jsou a chybí jen příznak. **Není to pravda** — voláme feed
> `branch.json` (v4), který obsahuje jen pobočky; boxy má Zásilkovna ve zvláštním
> feedu na jiném hostu a v jiné verzi API. Odlišení boxů proto z této vlny
> **vypadlo** (rozhodnutí vlastníka) a je popsané v `docs/future/zasilkovna-z-boxy.md`.
> Body níže označené *(vypuštěno)* se neimplementovaly.

Alternativy (agregátor typu Balíkobot, přímé integrace PPL/DPD/GLS) jsou popsané
v `docs/future/dopravci-agregator.md` a `docs/future/dopravci-prime-integrace.md`.

### Co se musí rozpojit jako první

`docs/future/zasilkovna-dalsi-dopravci.md` jmenuje tři místa přidrátovaná k
Zásilkovně. Bez nich doručení na adresu **nemůže fungovat** — nejde o úklid:

1. `shipping_snapshot` má `provider` a `weight_grams` vnořené uvnitř `pickup_point`,
   který zapisuje jen cesta s výdejním místem. Dopravce bez výdejního místa nemá kam
   je uložit a `ShipmentSubmitter` u něj vždy skončí na „dopravce není nakonfigurovaný".
2. `PickupPointCatalog::search()` nemá parametr dopravce a hledá napříč všemi katalogy.
3. `PickupPointController` hledá místo s natvrdo zadaným `'packeta'` a klíč widgetu
   čte přímo z modelu cizího modulu.

Tím se zároveň splní akceptační kritérium spec §16.5 („nový dopravce = nový driver
bez zásahu do checkoutu"), které je od vlny 2.5 nesplněné.

## Cíle

- [ ] Zákazník si v pokladně zvolí doručení na svou adresu a objednávku dokončí bez JS
- [ ] Nájemce takovou zásilku podá a vytiskne štítek ze stejné expediční fronty
- [ ] ~~Zákazník pozná box od pobočky a může si nechat vypsat jen boxy~~ *(vypuštěno — viz oprava výše)*
- [ ] `PickupPointCatalog::search()` a `PickupPointController` znají dopravce z košíku,
      ne z konstanty

## Mimo rozsah

- **Přímé integrace dopravců** (PPL, DPD, GLS, Česká pošta pod vlastní smlouvou) —
  `docs/future/dopravci-prime-integrace.md`
- **Agregátor** (Balíkobot, Foxdeli) — `docs/future/dopravci-agregator.md`
- **Výdejní místa partnerských dopravců** (PPL ParcelShop, DPD Pickup, Balíkovna
  přes Zásilkovnu) — Zásilkovna je vystavuje ve zvláštním feedu; tahle vlna řeší
  doručení na adresu, ne druhou síť výdejen. Zapsat do future.
- **Automatický polling stavu zásilky** — beze změny od 2.5
- **Zpáteční zásilky a reklamace**
- **Hmotnost per varianta** — nesený dluh z vlny 2.4, dotýká se, neřeší se tady
- **E-mail „objednávka odeslána"** — platforma takový transakční e-mail nemá vůbec

## Požadavky

### Jádro — kontrakt dopravce

`App\Core\Shipping\Contracts\Carrier::submit()` dnes bere `string $pickupPointCode`.
U doručení na adresu není co poslat a adresa se dovnitř nedostane.

```php
public function submit(
    OrderView $order,
    string $destination,      // kód výdejního místa, nebo id dopravce u doručení na adresu
    Money $codAmount,
    int $weightGrams,
    ?array $dimensionsMm = null,
    ?array $address = null,   // ['street','house_number','city','zip','country']
): ShipmentResult;
```

`$address` je povinná pro driver, který vrací `requiresPickupPoint() === false`;
driver ji sám odmítne, když chybí, protože adresu nemá odkud vzít a zásilka bez ní
by vznikla nedoručitelná.

`PickupPointCatalog::search()` dostane první parametr `string $carrier`.

### Modul `packeta` — druhý driver

Nový `PacketaHomeDelivery` s klíčem `packeta_hd`, registrovaný vedle stávajícího
`PacketaCarrier` v `EloquentCarrierRegistry`. **Dva drivery, ne jeden s přepínačem:**
registry je klíčovaná providerem a `requiresPickupPoint()` je vlastnost driveru —
jeden driver nemůže poctivě odpovědět „někdy ano, někdy ne", a checkout se ptá právě
touto metodou.

Průběh podání se od výdejního místa liší (ověřeno v dokumentaci Packety):

1. `createPacket` s `addressId` = **id partnerského dopravce** (ne id místa) a
   adresními poli `street`, `houseNumber`, `city`, `zip`
2. `packetCourierNumber` — objednání u kurýra
3. `packetCourierLabelPdf` — štítek, **jiný endpoint** než u výdejního místa

Krok 2 běží jako součást podání, ne až u tisku: štítek bez čísla kurýra vytisknout
nejde, a nájemce, kterému „Podat vybrané" projde a tisk pak selže, nemá jak zjistit
proč. Selhání kroku 2 je selhání podání.

Seznam partnerských dopravců přichází z feedu dopravců Zásilkovny. Nájemce si v
nastavení dopravní metody vybere jeden — ne my za něj, protože dostupnost a cena
závisí na jeho smlouvě.

### Snímek objednávky

`orders.shipping_snapshot` dnes:

```
{ …, pickup_point: { code, name, street, city, zip, provider, weight_grams } }
```

Nově `provider` a `weight_grams` na top-level; `pickup_point` zůstává tam, kde je,
a u doručení na adresu prostě chybí. `ShipmentSubmitter` čte z top-level a při
absenci sáhne do `pickup_point` — objednávky založené před touto změnou musí jít
podat dál. **Bez migrace snímků:** doklad ani snímek se v tomto projektu nepřepisuje
zpětně (rozhodnutí 2026-07-22), a čtení z obou míst je levnější než přepis historie.

### Katalog výdejních míst *(vypuštěno)*

`pickup_points.type` — `branch` (pobočka s obsluhou) nebo `box` (samoobslužný
Z-BOX). Plní `PickupPointSync` z feedu.

**Ověřit při implementaci, které pole feedu typ nese.** Pokud ho feed nenese vůbec,
tahle část odpadá a musí se to nahlásit, ne odhadovat podle názvu místa — „Z-BOX
Praha 4" je řetězec, ne datový typ, a heuristika nad cizím textem se rozbije, jakmile
Zásilkovna přejmenuje pobočky.

### Pokladna

- Výběr způsobu dopravy beze změny — metoda s `requiresPickupPoint() === false`
  prostě nezobrazí výběr místa
- ~~U výdejních míst přepínač „jen výdejní boxy"~~ *(vypuštěno)*
- Adresa pro doručení je ta, kterou zákazník už zadává — žádné druhé zadání

### Admin

- Nastavení dopravní metody `packeta_hd`: výběr partnerského dopravce ze seznamu
- Expediční fronta beze změny navenek — podá obojí

## Akceptační kritéria

1. Zákazník s vypnutým JS projde nákupem s doručením na adresu až po děkovnou stránku.
2. Objednávka na adresu jde podat z expediční fronty a vytisknout štítek.
3. Objednávka na výdejní místo se chová přesně jako dnes (žádná regrese).
4. Objednávka založená **před** touto změnou jde dál podat.
5. ~~Zákazník si může nechat vypsat jen boxy, a u každého místa vidí, o který typ jde.~~ *(vypuštěno)*
6. `PickupPointCatalog::search()` nevrátí místo cizího dopravce.
7. Podání bez adresy u dopravce doručujícího na adresu selže hlasitě, ne tiše.

## Technické poznámky

- Credentials se sdílejí se stávajícím `packeta` — `shipping_methods.settings` je
  `encrypted:array` a per metodu; nájemce je zadá dvakrát. Sdílet je napříč metodami
  by znamenalo vymyslet, která metoda je „ta hlavní".
- `Http::fake` ve všech testech, žádné reálné volání (vzor vlny 2.5).
- Idempotence podání beze změny: CAS claim, staleness reclaim, fail-fast guard na
  vztah timeout/práh (rozhodnutí 2026-07-27) platí pro oba drivery.
- Modul zůstává **base**. Doprava je podmínka prodeje, ne marketingový nástroj —
  stejný argument jako u modulu `feeds`.

## Reference

- `docs/future/zasilkovna-dalsi-dopravci.md` — tři místa k rozpojení
- As-is (po dokončení): `docs/as-is/2026-08-11-packeta-home-delivery.md`
- [Home delivery | Packeta API](https://docs.packeta.com/docs/packet-creation/home-delivery)
- [Carrier overview | Packeta API](https://docs.packeta.com/docs/destination-country/carrier-overview)
