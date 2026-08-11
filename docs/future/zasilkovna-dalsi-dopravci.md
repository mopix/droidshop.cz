# Další dopravci a co je pro ně potřeba dodělat

> **Aktualizace 2026-08-12:** body 1 a 2 níže jsou **hotové** (vlna „doručení na adresu", `docs/as-is/2026-08-11-packeta-home-delivery.md`). `PickupPointCatalog::search()` má parametr dopravce, `PickupPointController` si dopravce odvozuje z vybrané metody, a `provider`/`weight_grams`/`dimensions_mm` sedí na top-level `shipping_snapshot` s vnořeným čtením jako trvalou zálohou. Akceptační kritérium §16.5 je tím splněné. Platí dál bod 3 (odložené věci) — s tím, že **adresné doručení kurýrem už hotové je**.

Vzniklo při uzavírání vlny 2.5 (Zásilkovna). Architektura je na víc dopravců postavená — `CarrierRegistry`, `Carrier`, `PickupPointCatalog` a `ShipmentBook` jsou jádrové kontrakty a druhý driver je „jen" další arm v registry. Tři místa ale zůstala navázaná na Zásilkovnu natvrdo a musí se dodělat dřív, než přijde Balíkovna, PPL nebo DPD.

## 1. Checkout se na dvou místech ptá natvrdo

Akceptační kritérium spec §16.5 („přidání nového dopravce = nový sub-modul bez změny checkoutu") **není splněné**.

Splněné je: `CheckoutController`, `OrderPlacer` i Blade šablona kroku dopravy se ptají obecně přes `CarrierRegistry::for($provider)?->requiresPickupPoint()`.

Nesplněné je:
- `Modules/Checkout/Http/Controllers/PickupPointController::store()` — hledá místo v katalogu s natvrdo zadaným `'packeta'`
- `Modules/Checkout/Http/Controllers/PickupPointController::widgetApiKey()` — čte klíč widgetu přímo z modelu `ShippingMethod` modulu `shipping`, opět s natvrdo zadaným providerem
- `App\Core\Shipping\Contracts\PickupPointCatalog::search()` — **nemá parametr dopravce vůbec**, takže hledá napříč všemi katalogy

**Proč se to nedotáhlo:** převedení obou míst přes `CarrierRegistry` by znamenalo, že se výběr místa neotevře bez plné konfigurace dopravce (registry vyžaduje `api_password` i `eshop`). To by rozbilo šest záměrných testů, které picker a widget zkoušejí nezávisle na credentials. Správné řešení není protlačit to registry, ale zavést užší dotaz „který dopravce s výdejními místy tenhle obchod nabízí" — ten nepotřebuje credentials.

**Co udělat:**
- `PickupPointCatalog::search(string $carrier, string $query, int $limit)` — doplnit parametr dopravce
- odvodit dopravce z vybrané dopravní metody v košíku, ne z konstanty
- klíč widgetu řešit přes kontrakt, ne přímým čtením cizího modelu z checkoutu

## 2. Snapshot objednávky nemá kam uložit dopravce bez výdejního místa

`orders.shipping_snapshot` dnes vypadá takto:

```
shipping_snapshot: {
  …, pickup_point: { code, name, street, city, zip, provider, weight_grams }
}
```

`provider` a `weight_grams` jsou **vnořené uvnitř `pickup_point`**, protože ho zapisuje `OrderPlacer::resolvePickupPoint()`, která se volá jen u dopravce vyžadujícího výdejní místo.

Důsledek: kurýr doručující na adresu (Packeta Home Delivery, PPL, DPD) by neměl kam uložit ani klíč dopravce, ani hmotnost. `ShipmentSubmitter::submit()` čte `orderShippingSnapshot()['pickup_point']['provider']` — u takové objednávky by našel prázdno a vždy skončil na `CarrierError::notConfigured()`, i kdyby byl dopravce správně nakonfigurovaný.

**Co udělat:** při přidání prvního dopravce bez výdejního místa vytáhnout `provider` a `weight_grams` na top-level `shipping_snapshot` a `ShipmentSubmitter` přepsat na čtení odtud. Pozor na objednávky vzniklé před tou změnou — buď migrace snapshotů, nebo čtení z obou míst s prioritou top-level.

## 3. Odložené z rozsahu vlny 2.5

- **Adresné doručení kurýrem** (Packeta Home Delivery) — jen výdejní místa
- **Automatický polling stavu zásilky** (`packetStatus`) — dnes jen sledovací odkaz. Polling cizího API kvůli informaci, kterou dopravce ukazuje na svém webu, se nevyplatí, dokud nájemci nechtějí stav vidět přímo v adminu
- **Zpáteční zásilky a reklamace**
- **Vlastní mapa výdejních míst** bez widgetu třetí strany — katalog v `pickup_points` má souřadnice, takže je to proveditelné
- **Hmotnost per varianta** — odloženo už z vlny 2.4; varianty dnes dědí hmotnost produktu, takže těžká varianta téhož produktu se propíše do zásilky špatně
- **E-mail „objednávka odeslána"** — projekt takový transakční e-mail nemá vůbec, zákazník se o podání zásilky aktivně nedozví
