# Dopravci přes agregátor (Balíkobot, Foxdeli)

**Stav:** návrh, nerealizováno
**Vzniklo:** 2026-08-11, při rozhodování o dalších dopravcích
**Alternativa k:** `dopravci-prime-integrace.md`

## O co jde

Jeden modul, jedno API, ~30 dopravců: PPL, DPD, GLS, Česká pošta, Balíkovna,
Z-BOX, AlzaBox, PPL ParcelShop, DPD Pickup, GLS ParcelShop a další. Agregátor
stojí mezi námi a dopravci a sjednocuje jejich API do jednoho.

Nájemce má jednu smlouvu s agregátorem a v jeho administraci si zapne dopravce,
které chce; my píšeme a udržujeme jeden driver místo šesti.

## Proč to zatím není

Agregátor si účtuje měsíční paušál (Balíkobot řádově od stovek korun měsíčně),
takže malý e-shop platí navíc za něco, co u Zásilkovny dostane v ceně zásilky. A
mezi nás a dopravce vstupuje třetí strana: její výpadek je náš výpadek a její
změna ceníku je náš problém.

Doručení na adresu, které bylo nejpalčivější, umí Zásilkovna sama — proto se
2026-08-11 šlo touto cestou (`docs/superpowers/specs/2026-08-11-packeta-home-delivery-design.md`).

## Kdy se k tomu vrátit

- Když nájemci začnou chtít **konkrétní značku** („chci DPD, ne co vybere Zásilkovna")
- Když někdo bude potřebovat dopravce, kterého Zásilkovna nezprostředkovává
- Když objem zásilek nájemců dosáhne úrovně, kde se jim vyplatí vlastní smlouvy
  s dopravci a agregátor je jen technická spojka

## Co by bylo potřeba

### Modul

Nový modul `shipping-aggregator` (nebo `balikobot` podle zvoleného poskytovatele),
**base** stejně jako `packeta` — doprava je podmínka prodeje.

Implementuje jádrové kontrakty `Carrier`, `CarrierRegistry` a `PickupPointCatalog`
stejně jako `packeta`. Žádný nový jádrový kontrakt by vzniknout neměl; pokud se
ukáže, že je potřeba, je to signál, že abstrakce z vlny 2.5 nesedí, a patří to
k prodiskutování, ne k obejití.

### Registry musí umět víc providerů na jeden modul

Dnes `EloquentCarrierRegistry::for()` mapuje jeden klíč na jeden driver. Agregátor
nabízí desítky dopravců a každý z nich je z pohledu `shipping_methods.provider`
vlastní hodnota. Buď:

- **jeden driver, provider jako parametr** — `shipping_methods.settings` nese id
  dopravce u agregátora a driver ho předá dál; registry mapuje prefix (`bb_*`)
- **generovaná registry** — registry se ptá modulu na seznam podporovaných klíčů

První je jednodušší a stačí. Druhá varianta by dávala smysl, až budou agregátoři dva.

### Katalog výdejních míst

`pickup_points.carrier` už existuje a je na tohle připravený. Sync by běžel per
dopravce, ne jedním během — a **guard proti prázdnému feedu z vlny 2.5 musí platit
per dopravce**, ne pro celou tabulku: prázdná odpověď pro jednoho dopravce nesmí
deaktivovat místa ostatních.

### Ceníky

Agregátoři vracejí i ceny dopravy. Platforma dnes ceny dopravy nepočítá — nájemce
je zadává ručně v `shipping_methods`. Automatický ceník je samostatná funkce a
neměl by se do téhle vlny přilepit; kdyby se přilepil, jde o změnu v tom, kdo je
autorita nad cenou dopravy na dokladu, což se dotýká `InvoiceSnapshot` a DPH
rekapitulace (vlna 2.12).

### Co se musí vyřešit dřív

Totéž co u doručení na adresu, tedy **už hotové** k 2026-08-11:
`shipping_snapshot` s `provider` na top-level, `PickupPointCatalog::search()`
s dopravcem, `PickupPointController` bez natvrdo zadaného providera.

## Rizika

- **Závislost na třetí straně mezi námi a dopravcem** — dvě místa, kde může selhat
  podání, místo jednoho
- **Cena pro nájemce** — paušál navíc; u malého e-shopu může být vyšší než jeho
  marže na dopravě
- **Krabice v krabici** — agregátor sjednocuje API, ale ne chování; dopravci se
  liší v tom, co vyžadují (rozměry, telefon, e-mail, celní údaje), a to prosakuje
  skrz jakoukoli abstrakci

## Odhad

Jedna vlna, srovnatelná s 2.5 (Zásilkovna) — tedy velká. Katalog míst per dopravce
a per-dopravcová pravidla podání jsou to, co práci nafukuje, ne samotné API.
