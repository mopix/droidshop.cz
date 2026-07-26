# Vlna 2.4 — Varianty produktů — design

Datum: 2026-07-26 · Fáze 2 · Navazuje na: modul `products` (`EloquentProductCatalog`, `ProductWriter`), `checkout` (`CartPricer`, `cart_items`), `orders` (`OrderPlacer`, `order_items`), `storefront` (Blade SSR detail produktu), `tenant_theme` (vlna 2.2).

## Cíl

Umožnit nájemci prodávat **jeden produkt ve více variantách** (Velikost × Barva × …) s vlastním skladem, SKU a cenou per kombinace. Zákazník variantu vybere na detailu produktu, vloží do košíku a koupí — **i s vypnutým JavaScriptem** (pravidlo `.claude/rules/storefront-rendering.md`, spec §16.3 AK „celý checkout funkční bez JS"). JS je pouze ostrůvek pro živý přepočet ceny a dostupnosti.

Produkt bez variant se chová **přesně jako dnes** — varianty jsou volitelné per produkt, ne povinná vrstva.

Mimo rozsah (do `docs/future/`): obrázky per varianta, URL per varianta (samostatná indexace), hmotnost per varianta (dopravné), filtrování katalogu podle osy („zobraz jen červené"), hromadný import variant.

## Role

- `TENANT_ADMIN` / `TENANT_STAFF` s právem `products.edit` — definuje osy, hodnoty, generuje a edituje varianty. Write-freeze na `suspended`/`past_due` platí přes `CheckTenantStatus`.
- `TENANT_ADMIN` — nastavuje globální default zobrazení (radio vs. dropdown) na `/admin/nastaveni/vzhled`.
- `CUSTOMER` / anonym — vybírá variantu na storefrontu, kupuje.

## Rozhodnutí z brainstormingu (závazná)

| Otázka | Rozhodnutí |
|--------|-----------|
| Model variant | **Víceosé matice** (Velikost × Barva), ne jednoduchý seznam |
| Cena varianty | **Absolutní s fallbackem** — varianta smí mít vlastní cenu; `null` = základní cena produktu |
| Výběr na storefrontu | **Radio i dropdown**, nájemce si volí v adminu |
| Kde se volí zobrazení | **Globální default + přepis per produkt** |
| Kde bydlí globální default | **Sloupec v `tenant_theme`** (obrazovka `/admin/nastaveni/vzhled` už existuje) |
| Datový model | **4 relační tabulky**, ne JSON |
| Obrázky per varianta | **NE v MVP** → `docs/future/` |

## Datový model

Čtyři nové tabulky v modulu `products`. Relační, ne JSON dokument — stejné odůvodnění jako u `homepage_blocks` (vlna 2.3): per-řádek validace FormRequestem, řazení `position` + tlačítka nahoru/dolů (WCAG 2.1.1, precedent kategorie a obrázky) a dotazovatelnost pro budoucí filtry podle osy.

### `product_options` — osy varianty

| Sloupec | Typ | Poznámka |
|---------|-----|----------|
| `id` | PK | |
| `tenant_id` | FK | `BelongsToTenant`, index |
| `product_id` | FK → `products`, cascade delete | |
| `name` | string(60) | „Velikost" |
| `position` | unsigned int | pořadí os |

Unique `(product_id, name)` — dvě osy stejného jména na jednom produktu nedávají smysl.

### `product_option_values` — hodnoty osy

| Sloupec | Typ | Poznámka |
|---------|-----|----------|
| `id` | PK | |
| `tenant_id` | FK | |
| `option_id` | FK → `product_options`, cascade delete | |
| `value` | string(60) | „M" |
| `position` | unsigned int | pořadí hodnot |

Unique `(option_id, value)`.

### `product_variants` — konkrétní kombinace

| Sloupec | Typ | Poznámka |
|---------|-----|----------|
| `id` | PK | |
| `tenant_id` | FK | |
| `product_id` | FK → `products`, cascade delete | |
| `sku` | string(64) nullable | |
| `ean` | string(14) nullable | |
| `price` | unsigned bigint **nullable** | hrubá cena v haléřích; `null` = zdědí `products.price` |
| `stock_tracked` | bool default false | |
| `stock_qty` | int default 0 | |
| `stock_policy` | string(24) default `show_sold_out` | stejný slovník jako produkt |
| `active` | bool default true | neaktivní kombinace nejde koupit a nezobrazí se |
| `position` | unsigned int | |
| `timestamps` | | |

Index `(tenant_id, product_id, position)`, index `(tenant_id, sku)`.

**Sazbu DPH varianta nemá** — `tax_rate_id` zůstává na produktu. Různá sazba na velikosti trička je nesmysl a snapshot `order_items.tax_rate` se dál bere z produktu.

**`weight_g` varianta nemá** (odloženo) — dopravné počítá váhu produktu.

### `product_variant_values` — pivot varianta × hodnota

| Sloupec | Typ | Poznámka |
|---------|-----|----------|
| `tenant_id` | FK | |
| `variant_id` | FK → `product_variants`, cascade delete | |
| `option_value_id` | FK → `product_option_values`, cascade delete | |

Unique `(variant_id, option_value_id)`, index `(tenant_id, option_value_id)` (resoluce varianty z odeslaných voleb).

### Změny existujících tabulek

- `products` + `variant_display` string(16) **nullable** — `radio` \| `select` \| `null` (= zdědit globální default).
- `tenant_theme` + `variant_display` string(16) default `radio` — globální default nájemce.
- `cart_items` + `variant_id` unsigned bigint nullable; unique `cart_item_unique` přepsán na `(tenant_id, cart_id, product_id, variant_id)`.
- `order_items` + `variant_id` unsigned bigint nullable (**bez FK**, stejně jako `product_id` — snapshot musí přežít smazání) + `variant_label` string nullable (snímek „Velikost: M, Barva: červená").

**Háček k unique v `cart_items`:** MySQL i SQLite berou `NULL` v unique indexu jako vždy odlišný, takže produkt **bez** variant by šel do košíku vícekrát jako samostatné řádky. Řešení: pro produkt bez variant se ukládá `variant_id = 0` (sentinel), ne `NULL`. Nula je konzistentní i pro `INSERT … ON DUPLICATE`. V `order_items` sentinel nepotřebujeme (žádný unique) — tam zůstává `NULL`.

## Core kontrakty (`app/Core/Catalog/`)

Cizí modul (checkout, orders, feedy) nikdy nesahá na Eloquent modely variant. Rozšiřuje se stávající `ProductCatalog` a přibývá tvar `CatalogVariant` — stejný vzor jako `CatalogProduct`.

### `ProductCatalog` — rozšíření

```php
// Cenová autorita, nově i pro variantu. Variant null = produkt bez variant.
public function price(int $productId, array $context = [], ?int $variantId = null): Money;

// Sklad se odepisuje z varianty, když existuje; jinak z produktu. Pořád atomický UPDATE.
public function decrementStock(int $productId, int $quantity, ?int $variantId = null): void;
public function incrementStock(int $productId, int $quantity, ?int $variantId = null): void;

// Server je autorita nad tím, která kombinace je která — storefront posílá volby os,
// ne rovnou variant_id (jinak by šlo POSTem koupit neaktivní/cizí variantu).
/** @param array<int> $optionValueIds */
public function resolveVariant(int $productId, array $optionValueIds): ?CatalogVariant;

public function findVariantById(int $productId, int $variantId): ?CatalogVariant;

/** @return Collection<int, CatalogVariant> — jen aktivní, pro render a JSON-LD */
public function variantsFor(int $productId): Collection;
```

`?int $variantId = null` jako poslední parametr = **žádný existující callsite se nemění**. To je záměr: vlna nesmí přepsat každé volání ceny v checkoutu a objednávkách.

### `CatalogVariant` — nový tvar

```php
public function getKey();
public function catalogVariantSku(): ?string;
public function catalogVariantLabel(): string;      // „Velikost: M, Barva: červená"
public function catalogVariantPrice(): Money;       // už s fallbackem na základní cenu
public function catalogVariantIsAvailable(int $quantity = 1): bool;
/** @return array<int, int> option_id => option_value_id — pro předvýběr ve formuláři */
public function catalogVariantSelection(): array;
```

### `CatalogProduct` — rozšíření

```php
public function catalogHasVariants(): bool;
public function catalogPriceFrom(): Money;          // min přes aktivní dostupné varianty; bez variant = catalogPrice()
public function catalogVariantDisplay(): string;    // 'radio' | 'select', už vyřešená dědičnost produkt → tenant_theme
```

`catalogIsAvailable()` u produktu s variantami znamená **„≥1 aktivní varianta je skladem"** — jinak by se vyprodaný produkt tvářil dostupně, protože jeho vlastní `stock_qty` nikdo nesleduje.

**Když produkt má varianty:** `products.stock_qty` a `products.stock_tracked` se **ignorují**, `products.price` slouží jen jako fallback pro varianty bez vlastní ceny. Admin to musí říct nahlas (upozornění nad polem skladu), jinak nájemce nastaví sklad na produktu a diví se.

## Košík a objednávka

- **Add-to-cart posílá `option_value_id[]`, ne `variant_id`.** Server resolvne kombinaci přes `resolveVariant()`; neexistující, neaktivní nebo nedostupná kombinace = chyba s hláškou, ne tichý fallback na produkt.
- `CartPricer` počítá řádky přes `price($productId, $context, $variantId)` — cenová autorita zůstává v katalogu, `cart_items.unit_price` je dál jen zobrazovací snímek (rozhodnutí 2026-07-21).
- `OrderPlacer` každý řádek při odeslání přepočítá z katalogu **per (produkt, varianta)**. Neshoda snímku → `PriceChanged` (banner + přepočet). Varianta mezitím deaktivovaná / vyprodaná → stejná cesta jako dnes nedostupný produkt.
- Odpis skladu běží dál **uvnitř téže transakce** jako zápis objednávky, jen míří na variantu. Souběh na posledním kusu prohraje na `UniqueConstraintViolationException` / atomickém `UPDATE`, ne na read-modify-write.
- `order_items` snímkuje `variant_id` + `variant_label` + název a SKU varianty. Faktura, dodací list a e-maily tisknou `variant_label` pod názvem produktu.
- `OrderEditor` (admin edituje řádky objednávky) vrací sklad na správnou variantu.

## Storefront (Blade SSR + Alpine ostrůvek)

`Modules/Products/Resources/views/storefront/show.blade.php`:

1. **Server vyrenderuje** osy s hodnotami (radio nebo `<select>` dle `catalogVariantDisplay()`), cenu („od {min}" dokud není vybráno, nebo cenu předvybrané varianty) a dostupnost. Vše v HTML první odpovědi.
2. **Bez JS:** formulář se odešle POSTem s `option_value_id[]`; server variantu resolvne, ověří dostupnost a buď vloží do košíku, nebo vrátí stránku s chybou a zachovaným výběrem. Žádná cesta k nákupu nevede přes JS.
3. **S JS (Alpine ostrůvek):** embedded `<script type="application/json">` s maticí variant (id, volby, cena, dostupnost) → živě přepočítá cenu a dostupnost, zašedne nemožné kombinace. Ostrůvek **nikdy není jediným zdrojem obsahu** a **nepočítá cenu, kterou by server neznal** — jen zobrazuje hodnoty, které už poslal server.

**Předvýběr:** první aktivní dostupná varianta je předvybraná. Zákazník tak vidí konkrétní cenu a může koupit jedním klikem; „od {min}" se použije jen ve výpisu kategorie a v případě, že žádná varianta není dostupná.

**Výpis kategorie a homepage** (`product-card.blade.php`): produkt s variantami zobrazí `catalogPriceFrom()` s prefixem „od". Přidání do košíku z výpisu u produktu s variantami **nevkládá** — vede na detail (kombinaci nelze vybrat z karty).

**SEO:**
- JSON-LD `Product` dostane `offers` jako pole `Offer` — jeden per aktivní varianta, s `sku`, `price` a `availability`. Bez variant zůstává jediný `Offer` jako dnes.
- **Jedna URL produktu** (`/produkt/{slug}`), canonical beze změny. Volba varianty nemění URL (per-variant URL = `docs/future/`).
- `og:price` a `<title>` z produktu, ne z varianty.

## Admin

### Detail produktu — nový tab „Varianty" (`resources/js/Pages/Modules/Products/Show.vue`)

1. **Osy a hodnoty** — přidat osu („Velikost"), přidat hodnoty, řazení os i hodnot **tlačítky nahoru/dolů** (drag&drop nikdy jako jediná cesta — rozhodnutí 2026-07-20). Přejmenování hodnoty nechá varianty na místě (mění se text, ne vazba).
2. **„Generovat varianty"** — kartézský součin os. Idempotentní: existující kombinace zachová (včetně ceny a skladu), doplní jen chybějící. Mazání hodnoty osy nabídne, které varianty tím zaniknou (potvrzovací dialog — pravidlo mazacích akcí).
3. **Mřížka variant** — cena (prázdné = základní), SKU, EAN, sklad, `active`. Hromadné akce: nastavit cenu / sklad všem.
4. **Zobrazení** — přepínač `variant_display`: `Zdědit z obchodu` (default) / `Přepínače (radio)` / `Rozbalovací seznam`.
5. **Upozornění** nad polem skladu produktu, když má varianty: „Sklad se sleduje na variantách."

### `/admin/nastaveni/vzhled` — globální default

Jedno pole navíc do existující obrazovky vzhledu (vlna 2.2): „Výběr varianty na produktu" → radio / dropdown. Uloží se do `tenant_theme.variant_display`.

**Vědomý kompromis:** `tenant_theme` je jinak branding (logo, barvy) a `variant_display` je katalogová prezentace. Vlastní settings vrstva by ale znamenala postavit **první** admin obrazovku modulových nastavení (`SettingsService` dnes nemá žádné UI — `Modules/Docs/settings.json` se needituje) a to je scope navíc mimo cíl vlny. Až obrazovka modulových nastavení vznikne, pole se přesune.

## Bezpečnost a tenant izolace

- **Tenant izolace:** všechny 4 tabulky mají `tenant_id` + `BelongsToTenant`; `SchemaConventionTest` je nesmí muset povolovat výjimkou.
- **Server-authoritative resoluce:** klient posílá `option_value_id[]`, nikdy `variant_id`, a `resolveVariant()` ověřuje, že všechny hodnoty patří **tomuto** produktu a **tomuto** tenantovi. Jinak by POST s cizím `option_value_id` sáhl na variantu jiného e-shopu.
- **Neaktivní / nedostupná varianta** se odmítá na serveru, ne skrytím v UI.
- **Cena z klienta se nikdy nebere** — `unit_price` v požadavku se ignoruje stejně jako dnes.
- **Sklad** — atomický `UPDATE … WHERE stock_qty >= ?`, žádné read-modify-write; transakce společná se zápisem objednávky.
- **Názvy os a hodnot** jsou prostý text bez HTML — escapované Bladem, žádný sanitizer navíc.

## Testy

| Oblast | Test |
|--------|------|
| Izolace | Tenant A nevidí ani neresolvne varianty tenanta B; `option_value_id` cizího produktu = odmítnuto |
| Cena | Varianta s `price = null` dědí základní cenu; varianta s cenou přebíjí; `catalogPriceFrom()` = min přes dostupné |
| Bez JS | POST s `option_value_id[]` bez JS vloží správnou variantu do košíku; neúplný výběr = chyba, ne první varianta |
| Sklad | Odpis míří na variantu; souběh na posledním kusu = jedna objednávka projde, druhá dostane nedostupnost; storno vrátí sklad na tutéž variantu |
| Objednávka | `order_items` snímkuje `variant_label` a přežije smazání produktu i varianty; idempotence objednávky s variantou |
| Neaktivní | Deaktivovaná varianta nejde koupit ani přímým POSTem |
| Zpětná kompatibilita | Produkt bez variant se chová beze změny (cena, sklad, košík, objednávka, feed) |
| Košík | Dvě různé varianty téhož produktu = dva řádky; tatáž varianta dvakrát = navýšení množství; produkt bez variant dvakrát = navýšení (sentinel 0) |
| Admin | Generování variant idempotentní; smazání hodnoty osy smaže jen dotčené varianty; řazení tlačítky |
| Zobrazení | `variant_display` dědí z `tenant_theme`, přepis per produkt vyhrává |
| SEO | JSON-LD `Product` má `Offer` per varianta; „od" cena ve výpisu |
| A11y | Radio skupina má `<fieldset>`/`<legend>`, `<select>` má `<label>`; nedostupná kombinace hlášena textem, ne jen barvou |

## Migrační strategie

Čistě aditivní. Existující produkty nemají varianty → `catalogHasVariants()` false → dnešní chování. Žádný backfill kromě defaultu `tenant_theme.variant_display = 'radio'` a přepisu unique indexu `cart_items` (s naplněním `variant_id = 0` u existujících řádků).

## Verze

Vlna 2.4 = minor bump `0.21.0` → `0.22.0` (skill `versioning`).

## Odloženo do `docs/future/`

| Téma | Proč teď ne |
|------|-------------|
| Obrázky per varianta | Vyžaduje vazbu `product_images` ↔ hodnota osy + přepínání galerie; MVP zvládne jeden set fotek |
| URL per varianta | Samostatná indexace = duplicitní obsah, canonical strategie a redirecty — vlastní téma |
| Hmotnost per varianta | Dopravné dnes počítá váhu produktu; změna sahá do `shipping` |
| Filtr katalogu podle osy | Potřebuje indexovanou fasetovou vrstvu, ne `LIKE` |
| Hromadný import variant | Až bude import produktů obecně |
