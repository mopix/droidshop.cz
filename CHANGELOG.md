# Changelog

Historie verzí projektu DroidShop.cz. Aktuální verze je vždy v souboru [`VERSION`](VERSION).

Formát: [Keep a Changelog](https://keepachangelog.com/), verzování [SemVer](https://semver.org/).
Pravidla: [`.claude/skills/versioning/SKILL.md`](.claude/skills/versioning/SKILL.md).

- **patch** (`+0.0.1`) — každý commit (až bude `pre-commit` hook)
- **minor** (`+0.1.0`) — start nového implementačního plánu
- **major** (`+1.0.0`) — jen na explicitní pokyn

> CHANGELOG vede milníky (minor/major). Detail patchů je v `git log`.

## [0.14.0] – 2026-07-22

**Fáze 1 / vlna 1.5 — modul `docs`: faktury k objednávkám.** Objednávka konečně dostane fakturu. Nájemce ji vystaví tlačítkem v detailu objednávky, nebo se vystaví sama při zaplacení či expedici (dle nastavení `auto_issue_on`). PDF (A4, QR u nezaplacených, patička) se vygeneruje na pozadí a uloží na privátní disk; zákazník dostane fakturu e-mailem a stáhne si ji ve svém účtu. Doklad je jednou vystavený neměnný. Uzavírá spec §16.6 (base modul).

### Jádro — `app/Core/Documents/`

- Kontrakty `DocumentIssuer` (write: `issue()`, idempotentní) a `DocumentBook` (read: `forOrder()`) — **oddělený read/write split**, stejný vzor jako `OrderBook`/`OrderPlacement`; cizí modul nikdy nesahá na model `Document`.
- `DocumentView` — úzký snímkový tvar (číslo, typ, PDF cesta, total, currency, issued_at, sent_at); `NullDocumentIssuer`/`NullDocumentBook` guest-safe.

### Modul `docs` (base, nelze vypnout)

- `documents` tabulka přesně dle §16.6; unique `(tenant_id, number)` + unique `(tenant_id, order_id, type)` jako DB-level idempotence.
- `Document` — **immutable model**: update povolen jen na `pdf_path`/`sent_at`, delete vždy vyhodí; oprava jen dobropisem (vlna 1.6).
- `InvoiceIssuer` — gap-free číslo přes `SequenceService` v transakci s insertem, idempotence na `(order_id, type)`.
- `GenerateInvoicePdf` — dompdf (`barryvdh/laravel-dompdf`), A4, SPAYD QR pro nezaplacené, uloženo přes `FileStorage::putPrivate()` (`tenant_private`); e-mail zákazníkovi (`MailKind::Transactional`) obalený guardem, aby chyba pošty nespadla vygenerovaný doklad.
- `IssueInvoiceOnPaid`/`IssueInvoiceOnShipped` — naslouchají doménovým eventům `OrderPaymentSettled`/`OrderShipped`, dispatchovaným z `OrderWorkflow` přes **`DB::afterCommit`** (settlement transakci vnoří, inline dispatch by běžel před commitem). Payments/orders nezná modul `docs`.
- Admin (`docs.manage`): vystavit, výpis, stáhnout, znovu odeslat. Zákazník: stažení vlastní faktury přes gated route (`auth:customer` + `customer.session` + vlastnictví přes `OrderBook::findForCustomer`, cizí = 404).
- Plátce vs neplátce DPH = render distinkce v PDF šabloně, ne nový typ enumu; snapshot dodavatele z `tenants.billing_*` v okamžiku vystavení.

### Testy

Nové: `DocsModuleManifestTest`, `InvoiceIssuerTest`, `DocumentImmutabilityTest`, `GenerateInvoicePdfTest`, `InvoiceEmailTest`, `DocumentAdminTest`, `CustomerInvoiceDownloadTest`, `NullDocumentIssuerTest`. Celá suite **858 passed**.

### Mimo rozsah vlny

Dobropis (`credit_note`), CSV VAT export za období, proforma faktura — vlna 1.6. Enum `type` nese všechny tři hodnoty od začátku, 1.5 vystavuje jen `invoice`.

## [0.13.0] – 2026-07-21

**Fáze 1 / vlna 1.4 — modul `payments`: online platební brána Comgate.** Zákazník zaplatí kartou přes Comgate: po odeslání objednávky redirect na bránu, po ověřeném zaplacení `payment_status = paid` a děkovná stránka s potvrzením. Neúspěch/vypršení vrátí sklad a nechá objednávku pro nový nákup. Vypnutý modul nechá pokladnu na dobírku/převod (spec §16.6).

### Jádro — `app/Core/Payments/`

- Kontrakty `PaymentGateway` (driver) + `PaymentGatewayRegistry` (`for($provider)`/`available()`) — **registry/driver architektura**, víc bran koexistuje per tenant; `NullPaymentGatewayRegistry` guest-safe.
- `PaymentResult` + enum `PaymentStatus`, `PaymentInitiation`, jádrová výjimka `GatewayError`.
- Nový kontrakt `App\Core\Orders\Contracts\OrderSettlement` (`attachReference`/`settlePaid`/`settleFailed`) — seam, přes který `payments` mění stav a vrací sklad bez sahání do `OrderWorkflow`.

### Modul `payments`

- `ComgateGateway` (v1.0 e-commerce HTTP-POST protokol, `Http` fasáda, bez composer balíčku), `EloquentPaymentGatewayRegistry`, `ComgateSignature`.
- `PaymentSettlement` (verify-before-trust), controllery `/platba/navrat` a `/platba/notifikace` (mimo CSRF, podpis brány), job `ExpireUnpaidOrder`.

### Bezpečnost

- `payment_status = paid` jen po server-to-server `verify()` — podvržený návrat ani webhook payload nic nesettluje; kontrola částky; reference vázaná na objednávku serverově.
- Idempotence duplicitní notifikace (webhook + návrat) přes `from==to` no-op + `lockForUpdate`.
- Credentials brány `encrypted:array`, maskované, keep-on-update.

### Testy

Nové: `PaymentGatewayRegistryTest`, `ComgateGatewayTest`, `PaymentCallbackTest`, `ExpireUnpaidOrderTest`; rozšířeny `OrderWorkflowTest`, `PaymentMethodAdminTest`, `CheckoutRedirectTest`. Celá suite **813 passed**.

## [0.12.0] – 2026-07-21

**Fáze 1 / vlna 1.3 — etapy 4+5 (sloučené): moduly `checkout` + `orders`.** Zákazník projde nákup od detailu produktu po děkovnou stránku bez zapnutého JavaScriptu, vznikne reálná objednávka a nájemce ji v adminu vidí, edituje, mění oba stavy a stornuje. Uzavírá MVP cíl vlny 1.3 (spec §3.1, §16.3, §16.4).

### Jádro — tři nové kontrakty po vzoru `ProductCatalog`

- `App\Core\Checkout\Contracts\CartRepository` (+ shape `CartShape`) — guest-safe null binding (`NullCartRepository`/`TransientCart`), přebitý modulem `checkout`
- `App\Core\Orders\Contracts\OrderPlacement` (+ shape `PlacedOrder`) a `OrderBook` (+ shape `OrderView`) — psaní a čtení objednávek jsou dva různé kontrakty (jiné invarianty: odeslání je jeden atomický zápis s idempotencí, čtení je „moje objednávky" vs. „admin výpis")
- Žádný z modulů nedeklaruje `requires` na druhý ani na `shipping` — `checkout` volá `app(OrderPlacement::class)` (null odmítne odeslání) a `ShippingOptions`/`PaymentOptions` (null → nouzovka „osobní odběr zdarma"). Runtime gate přes `ShopModules`, ne manifest, stejný precedent jako `CustomerIdentity` z etapy 2
- `CatalogProduct` rozšířen o `catalogTaxRatePercent()` — sazba DPH ke snímku řádku objednávky se čte z katalogu, ne z ceníku samostatně

### Modul `checkout` — košík a pokladna (Blade SSR, `noindex`)

- `carts`/`cart_items`, košík vázaný na `carts.token` (kryptograficky náhodný cookie), volitelně na přihlášeného zákazníka
- Po přihlášení zákazníka se anonymní košík připojí k účtu (`CartMerger` na `Login` eventu guardu `customer`) — stejný produkt sečte množství, nepřepíše; přihlášení bez anonymního cookie znovu nasměruje cookie na uložený košík zákazníka
- `/kosik`, `/pokladna/doprava`, `/pokladna/udaje`, `/dekujeme/{uuid}` — celý tok funguje bez JS; **veškerá cenová logika na serveru** (`CartPricer`), podvržená cena/doprava v POST se ignoruje
- Změna ceny mezi vložením do košíku a odesláním zobrazí banner a přepočte (`PriceChanged`), nikdy nenaúčtuje starou cenu
- SPAYD QR pro platbu převodem jako inline SVG (`endroid/qr-code ^6.0`, `SvgWriter`, bez GD) — účet se čte živě z platební metody, nikdy ze snímku objednávky (žádný credential ve snímku, spec §16.5)
- Potvrzovací e-mail zákazníkovi i nájemci a stavové e-maily přes kernel `MailService`, vždy `MailKind::Transactional`
- `/kosik`, `/pokladna/*`, `/dekujeme/*` vyřazeny z page cache hlavičkou `Cache-Control: private, no-store` (page cache jako taková ještě neexistuje — provizorní řešení, viz Odchylky)

### Modul `orders` — perzistence a admin (Inertia, `resources/js/Pages/Modules/Orders/`)

- `orders`/`order_items`/`order_events`, číslo objednávky přes gap-free `SequenceService::configure('orders')` (běží v `Lifecycle::onActivate()`, ne v `boot()` — tenant kontext v tu chvíli ještě neexistuje)
- `OrderPlacer::place()` — jedna DB transakce: idempotence podle `(cart_id, checkout_token)` první, přepočet každého řádku z `ProductCatalog::price()` (nikdy z `cart_items.unit_price`, který je jen snímek), odpis skladu (`decrementStock`, atomický `UPDATE`) uvnitř téže transakce jako zápis objednávky — objednávka, která nevezme sklad, nesmí vzniknout, a naopak. Souběh na posledním kusu: prohraný požadavek dostane `UniqueConstraintViolationException`, dohledá už vzniklou objednávku a vrátí ji místo 500
- `OrderWorkflow` vynucuje dvojitý stavový automat (`fulfillment_status` × `payment_status`, nezávislé grafy, nezávislé `order_events` záznamy) — kontrola nelegálního přechodu proběhne čistě v paměti před jakýmkoli dotazem, takže není co vracet
- Admin: výpis s filtrem a hledáním, detail s položkami/adresami/historií, editace existujících řádků (sklad podle delty), ruční založení objednávky, storno s přesným vrácením skladu. Oprávnění `orders.view`/`orders.edit`/`orders.cancel`
- `EloquentOrderBook::forCustomer`/`findForCustomer` — čtení pro účet zákazníka, tenant + vlastnictví scoped

### Účet zákazníka — historie objednávek

- `/ucet/objednavky` (seznam) a `/ucet/objednavky/{uuid}` (detail) v `Modules/Customers`, nahrazují placeholder z etapy 2. Blade SSR, `noindex`, za guardem `customer`
- Detail čte přes `OrderBook::findForCustomer(customerId, uuid)` — vlastnictví, ne jen znalost UUID: cizí objednávka (jiný zákazník i jiný tenant) vrátí `null` → 404, stejně jako cizí `customer_address`

### Testy

Celá sada **775 passed** (bylo 656 na startu etapy 4/5, 762 po Task 8, 770 po historii objednávek). Nově `AccountOrdersTest` (8) a `CartMergeOnLoginTest` (5), oprava `CustomerAccountTest` (placeholder → odkaz na historii objednávek).

### Mimo rozsah etapy

- Online platební brána, webhook, `/platba/navrat` — vlna 1.4, poběží na `payment_snapshot` a stavu `payment`
- Faktury, PDF, číselné řady dokladů — vlna 1.5, poběží na hotových `orders`
- Manuální Lighthouse a11y check na `/kosik` a pokladně — pre-deploy checklist, nebylo možné spustit v implementačním prostředí

## [0.11.0] – 2026-07-21

**Fáze 1 / vlna 1.3 — etapa 3: modul `shipping`.** Nájemce si v adminu definuje, jak jeho e-shop doručuje a přijímá platby — způsoby dopravy (osobní odběr, paušální dopravce), způsoby platby (dobírka, převod s QR) a matici, která platba patří ke které dopravě. Modul je admin-only; volby renderuje až budoucí checkout. Online platební brány jsou vlna 1.4.

### Datový model a jádro

- Tři tenant-scoped tabulky: `shipping_methods`, `payment_methods` a pivot `shipping_method_payment_method`. Ceny jako celé haléře (`MoneyCast` + companion sloupec `currency`), DPH nese `TaxRate`, ne `Money`
- `payment_methods.settings` je **šifrované at rest** (`encrypted:array`) — bankovní účet pro QR je credential podle §16.5. První tenant-scoped použití `encrypted` castu; sloupec je `text`, ne `json`, protože cast píše opaque ciphertext
- Dva jádrové kontrakty `App\Core\Shipping\Contracts\ShippingOptions` a `PaymentOptions` (+ read-only shapes `ShippingOption`/`PaymentOption`, které modely implementují přímo) — jak si checkout vyžádá aktivní, správně filtrované volby, aniž by sahal na tabulky modulu
- Guest-safe null bindingy v jádře; modul je přebíjí a každá implementace se ptá `ShopModules->has('shipping')` za běhu, takže deaktivovaný modul odpoví prázdno bez `requires` v manifestu (precedent `CustomerIdentity` z etapy 2)

### Admin (Inertia, `resources/js/Pages/Modules/Shipping/`)

- CRUD způsobů dopravy i platby s řazením tlačítky ovladatelnými klávesnicí (WCAG 2.1.1, ne drag&drop jako jediná cesta)
- Účet pro QR se adminovi vrací jen **maskovaný** (poslední 4 znaky) + afordance „změnit"; writer přepíše `settings`, jen když admin pošle novou hodnotu — otevření a uložení formuláře beze změny účet nevymaže
- Matice doprava × platba jako checkbox mřížka; **prázdná řada = všechny aktivní platby povoleny** (jinak by nedotčená obrazovka udělala e-shop, který nepřijme objednávku). Uložení nahradí pivot řádky v transakci, tenant-scoped
- Oprávnění `shipping.manage`, položka v adminní navigaci

### Mimo rozsah etapy

- Žádný storefront povrch — volby dopravy a platby vykreslí až modul `checkout` (etapa 4), který tyto kontrakty spotřebuje
- Online platební brány (Comgate/GoPay) — vlna 1.4; proto je šifrování settings připravené už teď
- Váhový strop metody (`max_weight_g`) filtruje v `available()`, ale samotnou váhu košíku dodá až checkout

## [0.10.1] – 2026-07-21

**Bezpečnostní záplata etapy 2 (předmergová).** Finální revize větve našla řetězec vedoucí k převzetí účtu a několik děr kolem GDPR výmazu; opraveno před sloučením do `main`.

- **Resetovací token přežíval výmaz zákazníka.** Výmaz uvolnil e-mailovou adresu z unikátního indexu, novou registrací ji obsadil jiný člověk a starý resetovací odkaz původního zákazníka pak přepsal heslo tomu novému a přihlásil útočníka pod jeho účet. `CustomerEraser` teď v téže transakci maže všechny tokeny původní adresy. Incident: [`2026-07-21-error-01`](docs/superpowers/errors/2026-07-21-error-01-token-prezije-vymaz-zakaznika.md)
- Výmaz redaguje adresu i v `mail_messages.recipients` (řádky zůstávají kvůli počítadlu `emails_month`); `customer_tokens` se čistí při expiraci i denním commandem `customers:prune-tokens`
- `CustomerIdentity` má jádrovou null-implementaci (checkout poběží i na e-shopu bez modulu) a ptá se `ShopModules` za běhu; přibylo `findById()` pro rehydrataci `carts.customer_id`
- Reset hesla vyhazuje ostatní session přes vlastní `AuthenticateCustomerSession` (Laravelí `AuthenticateSession` je natvrdo na guardu `web`)
- Přihlášený zákazník na `/prihlaseni` míří na `/ucet`, ne na staffovský dashboard; hlavička e-shopu konečně odkazuje na účet/přihlášení

## [0.10.0] – 2026-07-21

**Fáze 1 / vlna 1.3 — etapa 2: modul `customers`.** Koncoví zákazníci e-shopu dostávají vlastní identitu — registrace, přihlášení, reset hesla, verifikace e-mailu, účet a admin s GDPR výmazem.

### Guard a datový model

- Čtvrtý guard `customer` nad tabulkou `customers` (tenant-scoped, `BelongsToTenant`); stejná e-mailová adresa u dvou e-shopů jsou dva nesouvisející účty, unikátní index `(tenant_id, email)`
- `customer_addresses` (fakturační/dodací) a `customer_tokens` (reset hesla, verifikace)
- Vlastní `CustomerTokens` — tenant-scoped, hash-only, jednorázové, expirující tokeny nad `(tenant_id, email, purpose)`; Laravelí password broker nejde použít, protože `password_reset_tokens` má primární klíč jen `email`
- `AnonymisedCustomerProvider` (driver `customer-eloquent`) vyřazuje anonymizované účty ze všech cest, kterými guard dohledává uživatele — session, remember-me, přihlášení. Admin dál anonymizované zákazníky vidí, filtr sedí jen na autentizační cestě

### Storefront (Blade SSR, `noindex`)

- `/registrace`, `/prihlaseni`, `/odhlaseni`, `/zapomenute-heslo`, `/obnova-hesla/{token}`, `/overeni-emailu/{token}`, `/overeni-emailu/znovu`
- `/ucet`, `/ucet/udaje`, `/ucet/adresy` + editace a potvrzovací stránka smazání adresy (GET krok, ne JS `confirm()`) — celý tok funguje bez JavaScriptu
- Historie objednávek je vyznačený placeholder — čeká na modul `orders`

### Mail a rate limiting

- Verifikační e-mail a reset hesla jdou přes kernel `MailService` z etapy 1, vždy jako `MailKind::Transactional`
- Přihlášení, reset hesla i verifikace mají explicitní decay okna (ne implicitní minuta Laravelu), klíčované tenantem a adresou/IP — lockout na jednom e-shopu neuzamkne stejnou osobu na jiném

### Admin (Inertia, `resources/js/Pages/Modules/Customers/`)

- Výpis, detail, JSON export (právo na přenositelnost)
- GDPR výmaz — `CustomerEraser` anonymizuje místo mazání (objednávky budou na řádek odkazovat cizím klíčem), transakčně, idempotentně, s auditním záznamem
- Oprávnění `customers.view` a `customers.erase`, položka v adminní navigaci

### Jádro

- Kontrakt `App\Core\Customers\Contracts\CustomerIdentity` (+ `CustomerAccount`) — jak si budoucí modul `checkout` připojí košík k přihlášenému zákazníkovi; `findByEmail()` úmyslně přeskakuje anonymizované účty
- `EnsureTenantMember` napevno na guard `web`; guest redirect (`redirectGuestsTo`) čte middleware namatchované routy, takže zákazník skončí na `/prihlaseni`, ne na staffovském `/login`

### Mimo rozsah etapy

- Verifikace e-mailu se nikde nevynucuje — nic není podmíněno `email_verified_at`; jestli to bude vyžadovat checkout, rozhodne až jeho etapa
- Historie objednávek v účtu — čeká na modul `orders`

## [0.9.2] – 2026-07-20

**Fáze 1 / vlna 1.3 — etapa 1: MailService.** Jádrová služba pro odesílání e-mailu jménem tenanta — první konkrétní volající pro `emails_month` v `LimitsService`.

- Kontrakt `MailService` + implementace `QueuedMailService` — tenant se dořeší (explicitní argument vyhrává nad ambientním kontextem) a celý běh (kvóta, log, identita odesílatele) jede uvnitř `TenantContext::runAs()`
- `SendTenantMail` — fronta doručení; při chybě během opakování se zapisuje jen text chyby, stav `failed` nastaví jedině Laravelí `failed()` hook (na sync driveru `attempts()` vrací natvrdo 1, takže by podmínka na poslední pokus nikdy nesepnula)
- `TenantSender` — obálková adresa vždy platformní (SPF/DKIM), tenant dodává jen display name a reply-to; nové sloupce `tenants.mail_from_name` a `tenants.mail_reply_to`
- `MailKind` — povinný argument kontraktu, `Transactional` nebo `Bulk`. Vyčerpaný limit nikdy nezastaví potvrzení objednávky ani reset hesla; transakční pošta se počítá, ale neblokuje. Druh se ukládá do `mail_messages.kind`, aby log ukázal, proč zpráva odešla přes strop
- `MailLimitCounter` — počítadlo `emails_month` nad `queued` i `sent` v aktuálním kalendářním měsíci (klíčem `queued_at`), zaregistrované v `AppServiceProvider`
- Model `MailMessage` nad tabulkou `mail_messages` (tenant-scoped)
- **Mimo rozsah etapy:** šablony e-mailů (verifikace, reset hesla, potvrzení objednávky) — přijdou s moduly `customers` a `orders`; `EventBus` zůstává odloženo

## [0.9.0] – 2026-07-20

**Fáze 1 / vlna 1.2 — storefront katalogu.** E-shop nájemce je poprvé veřejně dostupný: homepage, kategorie, detail produktu a vyhledávání renderované serverem, se SEO výstupy podle závazného pravidla storefrontu.

### Nový modul `storefront`

- Layout e-shopu (skip link, navigace kořenových kategorií, hledání, patička), homepage, `/hledani`
- Blade komponenty `seo-meta`, `json-ld`, `breadcrumbs`, `product-card`, `product-grid`, `sort-form`
- Chybové stránky v šabloně e-shopu; bez tenanta se degraduje na prostý HTML
- `sitemap.xml` a `robots.txt` per tenant; e-shop, který neobchoduje, dostane `Disallow: /`

### Veřejný katalog

- `/kategorie/{slug}` — výpis celého podstromu, stránkování 24, řazení a filtr „skladem" přes query parametry (funguje bez JS)
- `/produkt/{slug}` — galerie, cena s DPH i bez, dostupnost, popis
- JSON-LD `Product`+`Offer`, `BreadcrumbList`, `ItemList`, `Organization`+`WebSite`; canonical, OG a Twitter meta, `rel=prev/next`
- `noindex` na výsledky hledání a na filtrované kombinace

### SEO a chybové stavy

- **Přejmenovaný slug konečně odpovídá 301.** `redirects` se zapisovaly od vlny 1.1, ale nic je neservírovalo — obsluha visí na handleru 404, takže úspěšná cesta nenese DB dotaz navíc
- Stažený (soft-deleted) produkt vrací **410** se stránkou „produkt už není v nabídce" a odkazem do kategorie
- 404 se renderuje v šabloně e-shopu

### Jádro

- Kontrakt `StorefrontHome` — kořenová routa zůstává v jádře a deleguje ji šabloně
- `ProductQuery` + rozšíření `ProductCatalog` o `latest()` a `paginate()`; `CatalogProduct` o obrázek, krátký popis a URL
- `RedirectResponder` — servírování redirectů včetně dohledání tenanta z hostu

### Modul `products`

- Normalizovaný sloupec `search_text` (lowercase, bez diakritiky) plněný při zápisu + command `products:reindex-search`
- Vyhledávání ho používá, takže „cerna bunda" najde „Černá bunda"

### Assety

- Samostatný storefront bundle (JS 250 B gzip, CSS 9,8 kB gzip), Tailwind vidí Blade v `Modules/`

## [0.8.0] – 2026-07-20

**Fáze 1 / vlna 1.1 — jádro katalogu.** Nájemce spravuje strom kategorií a produkty s cenami, DPH, skladem, obrázky a SEO poli ve vlastním adminu.

### Bezpečnost

- **Opravena díra v admin routách modulů.** Byly montované jen s `web` a modulovým gate, takže kdokoli bez přihlášení mohl číst a zapisovat cizí e-shop. Týkalo se i nasazeného modulu `Pages`. Nový middleware `EnsureTenantMember` ověřuje přihlášení a členství v e-shopu, na jehož hostu request dorazil.
- **Oprávnění z manifestů začala platit.** `TenantPermissions` odvozuje sadu práv e-shopu z manifestů modulů, které běží; `Gate::before` z ní odpovídá na `$user->can()`. Právo vypnutého modulu nedostane nikdo, ani vlastník.
- **Vlastní `HtmlSanitizer`** (whitelist tagů, atributů a URL schémat nad `DOMDocument`). Popisy produktů se čistí při zápisu.
- **Nákupní cena** se zahazuje z validovaných dat a neopouští server bez práva `products.costs`.
- **Obrázky se při nahrání otevírají**, ne jen kontrolují podle přípony — HTML soubor přejmenovaný na `.jpg` by se jinak servíroval z originu e-shopu.

### Jádro

- Číselník sazeb DPH (`tax_rates`, promile jako integer); převody `net`/`gross`/`vat` na `TaxRate`
- Tabulka a služba `redirects` — 301 po přejmenování, řetězce se kolabují při zápisu
- `AdminLayout` — shell adminu nájemce, navigace z manifestů modulů, sdílené Inertia props
- Kontrakt `ProductCatalog` + `CatalogProduct` v jádře, implementace v modulu
- Service providery modulů se načítají z disku (`Modules/*/Providers/ModuleProvider.php`)
- Sdílené UI komponenty přesunuty z `Components/Platform` do `Components/Ui`

### Modul `categories`

- Strom (adjacency list + materializovaná cesta), max 4 úrovně, bez cyklů
- Admin: výpis, inline editace, přesun, řazení tlačítky (ovladatelné klávesnicí), mazání s povinným cílem pro podkategorie

### Modul `products`

- Produkty, výrobci, obrázky, vazba na kategorie s hlavní kategorií
- Cena hrubá + sazba; net a DPH se dopočítávají
- Atomický `decrementStock` jedním podmíněným `UPDATE`
- Soft delete; smazané produkty nepočítají do limitu tarifu
- Admin: seznam s filtry a stránkováním, karta se záložkami Základní / Ceny / Obrázky / Sklad / SEO
- Validace EAN-8/13 včetně kontrolní číslice

### Mimo rozsah vlny

Varianty, CSV import/export, generování řezů obrázků, hromadné operace, storefront rendering.

## [0.7.0] – 2026-07-20

**Fáze 0 / vlna 0.6 — superadmin management UI.** Platformu lze spravovat z prohlížeče: tenanti, stavy, tarify, moduly, kill switch.

- Výpis tenantů s filtry (stav, tarif, hledání dle jména/domény/IČO) a stránkováním; detail adresovaný přes UUID
- Detail tenanta: stav, tarif, domény, uživatelé, moduly, čerpání limitů, posledních 20 záznamů auditu
- Změna stavu podle mapy povolených přechodů v `TenantStatus`; důvod povinný u pozastavení a čekání na smazání; `deleted` nelze nastavit ručně
- Změna tarifu přes `PlanSwitcher` — **downgrade vypne moduly, které nový tarif nekryje**, i jejich závislé; UI ukáže dopad předem
- Aktivace a deaktivace modulů per tenant přes `ModuleRegistry` (plán, závislosti a core status dál hlídá registry)
- **`ModuleKillSwitch`** — jediná zápisová cesta k `modules.enabled_globally`; zahodí cache registru, vynutí důvod, zapíše audit. Přebíjí i core moduly (nouzová brzda)
- **Oprava:** `AuditLog` bral `user_id` z naposledy použitého guardu, takže superadmin akce shodila cizí klíč nebo ukázala na cizí osobu. Nyní guard `web` + identita superadmina v `meta`
- Impersonace vrací `Inertia::location()` — spouští se z Inertia stránky
- Vlastní UI komponenty (`PlatformLayout`, `DataTable`, `Pagination`, `StatusBadge`, `ConfirmDialog`, `FilterBar`) — žádná nová JS závislost
- Nová brána izolace: `PlatformRouteIsolationTest` trvá na `platform.host`, `auth:platform` a `platform.2fa` u každé `platform.*` routy
- **Odloženo:** metriky a MRR (čeká na fakturaci), zakládání a mazání tenantů z UI, editace tarifů, prohlížeč auditu
- **As-is:** [`docs/as-is/2026-07-20-superadmin-ui.md`](docs/as-is/2026-07-20-superadmin-ui.md)

## [0.6.0] – 2026-07-19

**Fáze 0 / vlna 0.5 — superadmin auth jádro.** Správce platformy s odděleným účtem, povinným 2FA a auditovanou impersonací.

- Oddělená tabulka `platform_admins` + guard `platform` — sdílí nic s `users`
- Přihlášení jen na platformním hostu (na doméně tenanta 404), rate limit 5/min + lockout
- Povinné 2FA (TOTP + jednorázové recovery kódy, šifrované/hashované), dvě brány přes middleware
- **Impersonace** přes podepsaný handoff mezi hosty (různé session cookies); 30 min expirace, `impersonated_by` v každém audit zápisu, banner v UI
- `platform:create-admin` — interaktivní zřízení superadmina (žádné údaje v seederu)
- Balíček `pragmarx/google2fa`
- **Odloženo:** management UI (výpis tenantů, metriky), HIBP kontrola hesla, IP allowlist
- **As-is:** [`docs/as-is/2026-07-19-superadmin-auth.md`](docs/as-is/2026-07-19-superadmin-auth.md)

## [0.5.0] – 2026-07-19

**Fáze 0 / vlna 0.4 — FileStorage.** Modul umí uložit a servírovat soubor přes službu jádra, aniž zná disk. Soubory zůstávají na naší VPS (lokální disk, ne S3).

- `FileStorage` — dva disky (`tenant_public` web-served, `tenant_private` jen přes podpis); každá cesta vynuceně pod `tenants/{id}/`
- `PathGuard` — odmítá traversal ve všech podobách (samostatná pojistka)
- Privátní soubory přes `URL::temporarySignedRoute` na doméně tenanta; podpis váže host i tenant param
- `StorageLimitCounter` — první konkrétní počítadlo pro `LimitsService`; upload nad limit tarifu se odmítne
- **Rozhodnutí 2026-07-19:** úložiště lokální, ne S3 (změna „S3 od začátku"); abstrakce drží swap na S3 jako změnu configu
- **As-is:** [`docs/as-is/2026-07-19-filestorage.md`](docs/as-is/2026-07-19-filestorage.md)

## [0.4.0] – 2026-07-19

**Fáze 0 / vlna 0.3 — kernel služby.** Pět služeb jádra a vynucení tarifu při aktivaci modulu.

- `Money` — integer haléře, dělení bez ztráty haléře, zákaz míchání měn
- `SettingsService` — per-tenant nastavení, validace proti schématu z manifestu, cache
- `LimitsService` — allow/warn/block, počítadla přes kontrakt `LimitCounter`, override z `plan_modules`
- `SequenceService` — číselné řady bez děr, dokázáno souběhovým testem 4 procesů; atomický `UPDATE ... LAST_INSERT_ID`
- `FeatureFlags` — global / whitelist / deterministické procento
- **Aktivace modulu respektuje tarif** — zavřená mezera z vlny 0.2; tenant bez tarifu si zapne jen core moduly
- **Odloženo:** `FileStorage`, `MailService`, `EventBus` — čekají na výběr provideru a prvního skutečného volajícího
- **As-is:** [`docs/as-is/2026-07-19-kernel-sluzby.md`](docs/as-is/2026-07-19-kernel-sluzby.md)

## [0.3.0] – 2026-07-19

**Fáze 0 / vlna 0.2 — systém modulů.** Modul jde nasadit, zaregistrovat, per tenanta zapnout a vypnout; když ho tenant nemá, jeho routy pro něj neexistují.

- Manifest (`module.json`) s validací — neplatný manifest shodí `modules:sync` celý, nikdy nezapíše polovičatý záznam
- `DependencyResolver` — topologické, deterministické řazení; cykly a nesplněné semver rozsahy hlásí chybu
- `ModuleRegistry` — aktivace dotáhne závislosti, deaktivace nic nemaže, kill switch přebíjí i core moduly
- Routy z disku, povolení z DB; middleware `module:{key}` vrací **404, ne 403**
- `NavigationBuilder` skládá admin menu z manifestů
- Referenční modul **Pages** — důkaz celého řetězu včetně Blade SSR a serverem renderovaných SEO tagů
- Balíček `composer/semver` přidán
- **Odchylka:** odinstalace modulu (`onUninstall`) odložena — rozhodnutí 2026-07-19
- **As-is:** [`docs/as-is/2026-07-19-system-modulu.md`](docs/as-is/2026-07-19-system-modulu.md)

## [0.2.0] – 2026-07-19

**Fáze 0 / vlna 0.1 — tenancy jádro.** Rozpoznání tenanta z Host hlavičky, datová izolace vynucená na modelech, propagace kontextu do jobů, audit log, CI s izolací jako samostatnou branou.

- Datový model jádra dle spec §15.3 (`tenants`, `domains`, `tenant_users`, `plans`, `audit_log`, `jobs_log`)
- Middleware pipeline `ResolveHost` → `CheckTenantStatus` → `SetTenantContext` (spec §15.2)
- `BelongsToTenant` + `TenantScope`; dotaz bez kontextu hodí `MissingTenantContext` místo tichého vrácení dat všech tenantů
- `SchemaConventionTest` shodí build, když doménová tabulka přijde bez `tenant_id`
- Balíčky: `spatie/laravel-multitenancy ^4.1` přidán, `stripe/stripe-php` odstraněn
- Lokální konfigurace přes `.env.local` (načítá `bootstrap/app.php`)
- **As-is:** [`docs/as-is/2026-07-19-tenancy-jadro.md`](docs/as-is/2026-07-19-tenancy-jadro.md)
- **Plán:** [`docs/superpowers/plans/2026-07-19-faze-0-vlna-01-tenancy-jadro.md`](docs/superpowers/plans/2026-07-19-faze-0-vlna-01-tenancy-jadro.md)

## [0.1.0] – 2026-07-19

**Bootstrap.** Laravel skeleton + napojení na GitHub + AI/docs struktura (`claude-laravel-vue` + WooShop vzor) + produktová specifikace v `docs/specs/`.

- **As-is:** [`docs/as-is/2026-07-19-bootstrap.md`](docs/as-is/2026-07-19-bootstrap.md)
