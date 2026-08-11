# Přímé integrace dopravců (PPL, DPD, GLS, Česká pošta)

**Stav:** návrh, nerealizováno
**Vzniklo:** 2026-08-11, při rozhodování o dalších dopravcích
**Alternativa k:** `dopravci-agregator.md`

## O co jde

Vlastní modul per dopravce. Nájemce má smlouvu přímo s dopravcem — lepší ceny,
žádný prostředník, žádný paušál navíc, a zákazník vidí značku, kterou zná.

## Proč to zatím není

Každé API je jiné a každý dopravce chce něco jiného. Kde agregátor znamená jeden
driver, tohle znamená čtyři samostatné vlny a trvalou údržbu čtyř integrací, které
se mění nezávisle na nás. Doručení na adresu — to, co chybělo nejvíc — přitom
umí Zásilkovna sama, takže se 2026-08-11 šlo tou cestou
(`docs/superpowers/specs/2026-08-11-packeta-home-delivery-design.md`).

## Kdy se k tomu vrátit

- Když nájemce s vlastní smlouvou u dopravce bude chtít fakturovat přes ni, ne
  přes Zásilkovnu
- Když objem zásilek dá nájemcům vyjednávací sílu na vlastní ceníky
- Když zákazníci začnou vybírat podle značky dopravce, ne podle „domů / na výdejnu"

## Co by bylo potřeba, per dopravce

Společné pro všechny: modul implementuje jádrové kontrakty `Carrier` a (kde má
dopravce výdejní síť) `PickupPointCatalog`. Žádný nový jádrový kontrakt.

### PPL

- **API:** myAPI (REST, OAuth2 client credentials). Starší SOAP je odstavené —
  ověřit aktuální stav před implementací.
- **Výdejní síť:** ParcelShop a ParcelBox, vlastní feed
- **Zvláštnosti:** vyžaduje číslo zákazníka (`depoCode`) a sadu produktů (PPL
  Parcel CZ Private, Business, Smart…), kterou nájemce musí namapovat na své
  dopravní metody — to je pole navíc v nastavení dopravní metody, ne konstanta
- **Štítky:** PDF i ZPL

### DPD

- **API:** REST (dříve SOAP ShipmentService)
- **Výdejní síť:** DPD Pickup, vlastní feed
- **Zvláštnosti:** rozlišuje produkty (Classic, Private, Pickup); dobírka má vlastní
  strukturu a **liší se podle produktu**, což je přesně ten druh detailu, který
  jednotný `Carrier::submit()` musí schovat do driveru, ne pustit ven

### GLS

- **API:** ParcelService (REST/SOAP podle regionu)
- **Výdejní síť:** ParcelShop a ParcelLocker
- **Zvláštnosti:** ClientNumber + heslo; některé služby (FlexDelivery) vyžadují
  e-mail příjemce, jinak podání selže

### Česká pošta / Balíkovna

- **API:** CPOST API (REST, dříve PodáníOnline)
- **Výdejní síť:** Balíkovny a pošty, vlastní číselník
- **Zvláštnosti:** podací archy a uzávěrky — Česká pošta pracuje v dávkách s denní
  uzávěrkou, což se **nedá namapovat na „podat jednu zásilku"** stejně jako u
  ostatních. Buď se to schová do driveru (podání = zařazení do otevřeného archu,
  uzávěrka = zvláštní akce v expediční frontě), nebo se přizná, že tenhle dopravce
  potřebuje vlastní obrazovku. Rozhodnout **před** implementací, ne během ní.

## Co je společné a co se musí vyřešit dřív

Tři místa přidrátovaná k Zásilkovně (`zasilkovna-dalsi-dopravci.md`) jsou už
rozpojená vlnou z 2026-08-11: `provider` a `weight_grams` na top-level
`shipping_snapshot`, `PickupPointCatalog::search()` s dopravcem,
`PickupPointController` bez konstanty.

Zbývá:

- **Katalog míst per dopravce** — sloupec `carrier` existuje, ale guard proti
  prázdnému feedu (rozhodnutí 2026-07-27) je dnes psaný pro jeden katalog. Musí
  platit **per dopravce**, jinak prázdná odpověď jednoho smaže místa ostatních.
- **Rozměry a hmotnost** — každý dopravce má vlastní limity a vlastní chování při
  jejich překročení. Platforma dnes posílá rozměry jen u jednopoložkové zásilky
  (rozhodnutí 2026-08-09); víc dopravců to nezmění, ale zvýrazní.
- **Hmotnost per varianta** — nesený dluh z vlny 2.4. U jednoho dopravce je to
  nepřesnost, u čtyř s různými váhovými pásmy je to špatná cena dopravy.

## Rizika

- **Čtyři API, čtyři změnové kalendáře.** Integrace, kterou nikdo neudržuje, přestane
  fungovat tiše — podání spadne až u nájemce, ne u nás.
- **Testy bez reálného účtu.** Vlna 2.5 jede na `Http::fake`, což ověří naši stranu,
  ne jejich. U čtyř dopravců to znamená čtyři sady předpokladů o cizím API.
- **Nájemce musí mít smlouvu.** Modul, který si nikdo nemůže zapnout bez podpisu u
  dopravce, je práce, kterou využije menšina.

## Odhad

Tři až čtyři samostatné vlny, jedna per dopravce, každá velikosti zhruba poloviny
vlny 2.5. Rozdělit je nutné — čtyři integrace v jedné vlně znamenají čtyři různá
cizí API, jejichž chyby se navzájem maskují.
