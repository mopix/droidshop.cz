# As-is: Zásilkovna — doručení na adresu

**Datum:** 2026-08-11 (uzavřeno 2026-08-12)
**Verze:** 0.46.0
**Spec:** [`docs/superpowers/specs/2026-08-11-packeta-home-delivery-design.md`](../superpowers/specs/2026-08-11-packeta-home-delivery-design.md)
**Plán:** [`docs/superpowers/plans/2026-08-11-packeta-home-delivery.md`](../superpowers/plans/2026-08-11-packeta-home-delivery.md)

## Co se změnilo

Vlna 2.5 přinesla Zásilkovnu jen na výdejní místa. Zákazník, který chtěl zásilku domů,
ji u žádného e-shopu na této platformě nedostal — a to je většina objednávek běžného
e-shopu. Zásilkovna přitom doručení na adresu umí, zprostředkovaně přes partnerské
dopravce; nájemce k tomu nepotřebuje novou smlouvu ani nové přihlašovací údaje.

| Oblast | Změna |
|--------|-------|
| `app/Core/Shipping/Contracts/Carrier.php` | `submit()` má `$destination` (kód místa **nebo** id partnerského dopravce) a nový `?array $address` |
| `app/Core/Shipping/Contracts/PickupPointCatalog.php` | `search()` dostal dopravce jako **první** parametr, stejně jako `find()` |
| `Modules/Packeta/Services/PacketaHomeDelivery.php` | nový driver `packeta_hd` |
| `Modules/Packeta/Services/EloquentCarrierRegistry.php` | resolvuje oba klíče, každý ze své vlastní řádky `shipping_methods` |
| `Modules/Packeta/Services/PacketaCarrierCatalog.php` | seznam partnerských dopravců z feedu, krátký timeout, negativní cache |
| `Modules/Orders/Services/OrderPlacer.php` | `provider`, `weight_grams` a `dimensions_mm` na top-level snímku |
| `Modules/Orders/Services/OrderEditor.php` | totéž pro ručně zakládané objednávky |
| `Modules/Orders/Services/EloquentOrderBook.php` | `forShippingProvider()` čte top-level i vnořeně |
| `Modules/Packeta/Http/Controllers/DispatchQueueController.php` | fronta pokrývá všechny nakonfigurované dopravce |
| `Modules/Packeta/Http/Controllers/ShipmentAdminController.php` | štítky se resolvují per dopravce dávky |
| `Modules/Checkout/Http/Controllers/PickupPointController.php` | dopravce z vybrané metody, ne z konstanty |
| `resources/js/Pages/Modules/Shipping/ShippingMethod.vue` | nastavení metody `packeta_hd` |

## Plnění spec

| Požadavek | Stav |
|-----------|------|
| Zákazník zvolí doručení na adresu a dokončí nákup bez JS | splněno |
| Nájemce zásilku podá a vytiskne štítek ze stejné fronty | splněno |
| Objednávka na výdejní místo beze změny | splněno |
| Objednávka založená před vlnou jde dál podat | splněno |
| `search()` nevrátí místo cizího dopravce | splněno |
| Podání bez adresy selže hlasitě, před sítí | splněno |
| Rozlišení Z-BOXů | **vypuštěno** — viz níže |

Průběh podání se u doručení na adresu liší: `createPacket` s `addressId` = id
partnerského dopravce a adresními poli, pak `packetCourierNumber` (objednání u kurýra),
a štítek jde přes `packetCourierLabelPdf` — jiný endpoint než u výdejního místa.
Objednání u kurýra je **součástí podání**, ne tisku: štítek bez čísla kurýra vytisknout
nejde a nájemce, kterému podání projde a tisk pak selže, nemá jak zjistit proč.

## Testy

156 testů v `Packeta` + `Shipping`, 220 v `Orders` + `Checkout`, celá E2E sada 83.
Nový E2E scénář `packeta-home-delivery-no-js.spec.ts` běží v projektu `no-js` a
neasertuje jen děkovnou stránku — čte zpět snímek uložené objednávky a ověřuje, že nese
`provider = packeta_hd` a žádné `pickup_point`.

## Odchylky od specifikace

**Rozlišení Z-BOXů vypuštěno.** Spec předpokládala, že boxy už v katalogu jsou a chybí
jen příznak. Není to pravda: voláme feed `branch.json` (v4), který obsahuje **jen
pobočky**. Boxy má Zásilkovna ve zvláštním feedu na jiném hostu a v jiné verzi API.
Odvodit typ z názvu místa bylo zvažováno a zamítnuto — heuristika nad cizím volným
textem se rozbije při přejmenování poboček, a rozbije se tiše. Rozhodnutí vlastníka:
mimo tuto vlnu, popsáno v [`docs/future/zasilkovna-z-boxy.md`](../future/zasilkovna-z-boxy.md).

**Id partnerského dopravce sedí na dopravní metodě, ne v configu.** První verze ho vzala
z platformního configu; to by znamenalo tentýž partnerský dopravce pro všechny nájemce,
zatímco dostupnost i cena závisí na jejich vlastní smlouvě. Config zůstal jen jako
záloha a prázdná hodnota nově vrací „dopravce není nakonfigurovaný" místo volání s
prázdným `addressId`.

**`OrderEditor` opraven mimo původní rozsah.** Ručně zakládaná objednávka nezapisovala
`provider` vůbec, takže ji nešlo podat **žádnému** dopravci — `CarrierError::notConfigured()`
padal pokaždé. Nešlo o regresi téhle vlny, ale o díru, kterou vlna odhalila.

**`ShippingMethod::packetaEshop()` a spol. rozšířeny na celou rodinu** (`isPacketaFamily()`)
místo přidání druhé sady přístupových metod. Pro `packeta` se chovají beze změny.

## Nálezy, které vlna odhalila

**Objednávka na adresu se nedostala do expediční fronty.** Nejzávažnější nález, a našla
ho až závěrečná revize celé větve. Dotaz filtroval na
`shipping_snapshot->pickup_point->provider`, jenže doručení na adresu žádné
`pickup_point` nemá — z definice téhle vlny. Nájemce tedy neměl odkud zásilku podat:
fronta ji nenašla, na detailu objednávky chybělo tlačítko, a štítek by se tiskl přes
endpoint pro výdejní místa. Pět dílčích revizí to minulo, protože **každý test volal
`ShipmentSubmitter` přímo**; díra ležela mezi obrazovkami, ne uvnitř služby. Doplněny
testy, které jedou přes admin obrazovky.

**Selhání po vytvoření zásilky nechávalo živý balík.** `createPacket` uspěje,
`packetCourierNumber` selže — zásilka u Packety existuje, u nás je objednávka označená
jako neúspěšná, a další pokus vytvoří druhou. U dobírky to znamená zákazníka, kterému
kurýr vybere peníze dvakrát. Nově se osiřelá zásilka best-effort ruší; když se zrušit
nepodaří, číslo zásilky jde do logu i do textu chyby, kterou nájemce vidí („zrušte ji
ručně"), místo aby zmizelo beze stopy.

**Výběr výdejního místa se zasekl.** Odkaz „Vybrat výdejní místo" nenesl volbu, takže se
dopravce bral z toho, co bylo v košíku uložené naposledy. Zákazník, který nejdřív zvolil
doručení na adresu a pak si to rozmyslel, dostal prázdné hledání a nesrozumitelnou
chybu. Regrese, kterou zavedla tato vlna, a projevila se přesně v konfiguraci obchodu,
kvůli které vlna vznikla.

## Známé chování a technický dluh

- **Číslo zásilky u kurýra se nikde neukládá.** Štítky, storno i sledování jedou přes
  `packet_id` a `barcode`, takže nic nerozbíjí — zákazník ale nemůže zásilku sledovat na
  webu partnerského dopravce.
- **Tvar odpovědi `packetCourierNumber` je odhad**, neověřený proti reálnému účtu. Špatný
  tvar degraduje hlasitě (prázdný výsledek → chyba → kompenzační storno), ne tiše.
- **Osiřelá zásilka po pádu procesu mezi dvěma voláními** zůstává živá; retry vytvoří
  druhou. Trvalé řešení (uložit `packet_id` hned po vytvoření a navázat) chce změnu
  kontraktu.
- `EloquentShippingOptions::find()` nefiltruje na aktivní metody — vlastnost starší než
  tato vlna.
- `crowns()`, `splitName()` a blok rozměrů jsou duplicitní mezi oběma drivery. Třetí
  dopravce = čas na společný trait.
- Feed dopravců se stahuje i pro nájemce, který má modul vypnutý (degraduje bezpečně).

## Pre-deploy

- [ ] `php artisan migrate` (rozšíření enumu `shipping_methods.provider`)
- [ ] `npm run build`
- [ ] **Ověřit tvar odpovědi `packetCourierNumber` proti reálnému účtu Zásilkovny** — a
      s ním i `packetCourierLabelPdf`
- [ ] Ověřit id partnerského dopravce v nastavení metody proti tomu, co má nájemce
      povolené ve své smlouvě
