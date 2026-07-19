# Specifikace: Pronajímatelná e-shopová platforma (SaaS)

**Verze:** 1.1 (draft) — doplněna Část II: detailní rozpracování jádra a modulů základního tarifu + modul Licence
**Datum:** 17. 7. 2026
**Úroveň detailu:** Level 3 (funkční specifikace — moduly, datové modely, uživatelské toky, akceptační kritéria)
**Stav:** K připomínkování

**Dodatky:**
- **2026-07-19 (v1.2):** doplněna §4.1.1 *Rendering policy storefrontu* (Blade SSR závazně). Změněno URL schéma produktu v §16.2 na ploché `/produkt/{slug}`. Upřesněno, že košík a pokladna jsou rovněž Blade SSR. Opravena §15.6 (zrušena cookie `has_cart`, mini-košík jako ostrůvek). Do MVP (§3.1) přidán řádek *SEO základ* — sitemap a robots. Doplněna poznámka k češtině u MySQL fulltextu (§4.1).

---

## 1. Cíl projektu a business model

### 1.1 Co stavíme

Multi-tenant SaaS platformu typu Shoptet / Eshop-rychle: **registrovaný uživatel si u nás za měsíční poplatek pronajme e-shop**, který si sám naplní produkty a provozuje pod vlastní značkou.

Klíčové principy od prvního dne:

1. **Modulární architektura** — vše nad rámec jádra (produkty, doprava, platby, e-maily, fakturace…) je samostatný modul s definovaným rozhraním. Jádro moduly pouze orchestruje. Toto je architektonický požadavek č. 1 — bez něj nelze platformu později škálovat ani zpoplatňovat funkce po vzoru Shoptet doplňků.
2. **Jedna šablona v MVP** — storefront má v první verzi jednu responzivní šablonu; šablonovací systém je ale navržen tak, aby šablon mohlo být později více (šablona = také modul).
3. **My neneseme odpovědnost za obsah nájemců** — my provozujeme software a infrastrukturu; za produkty, ceny, marketing, obchodní podmínky vůči koncovým zákazníkům, reklamace a daně odpovídá provozovatel e-shopu (nájemce). Toto musí být zakotveno ve VOP a promítnuto do celého návrhu (viz kap. 11).

### 1.2 Business model

- Měsíční/roční předplatné za pronájem e-shopu (tarify).
- Trial zdarma (14–15 dní — standard trhu: Eshop-rychle dává 15 dní + 8 dní „doběh", Shoptet 30 dní).
- Do budoucna: příplatkové moduly (marketplace doplňků), provize za zprostředkované služby (platební brány, dopravci), příplatky za objem (produkty, prostor).

### 1.3 Role v systému

| Role | Popis |
|---|---|
| **Superadmin (my)** | Správa platformy, tenantů, tarifů, modulů, fakturace nájemcům, podpora |
| **Provozovatel e-shopu (tenant admin)** | Registrovaný uživatel platformy; spravuje svůj e-shop, produkty, objednávky |
| **Personál e-shopu** | Další uživatelé pozvaní tenantem s omezenými právy (post-MVP, ale datový model počítá s rolemi od začátku) |
| **Koncový zákazník** | Nakupující na e-shopu tenanta; s námi nemá žádný smluvní vztah |

---

## 2. Analýza referenčních platforem

### 2.1 Shoptet.cz

- Největší CZ platforma, ~41–44 tis. aktivních e-shopů, od 2025 vlastněná skupinou Team.blue.
- **Tarify** (orientačně, roční platba): Basic ~396–440 Kč, Grow ~1 341 Kč, Pro ~2 241 Kč, Enterprise ~4 221 Kč, Premium ~12 000+ Kč/měs. Tarify se liší počtem produktů a přístupem k doplňkům.
- **Ekosystém doplňků** (doplnky.shoptet.cz, ~395 doplňků): třetí strany vyvíjejí addony proti Shoptet REST API. Addon běží **na infrastruktuře vývojáře** — Shoptet neposkytuje hosting addonů. Instalací vzniká API klíč per e-shop; volání se autorizují krátkodobými tokeny (platnost 30 min). Rozsah oprávnění (endpointů) schvaluje Shoptet a potvrzuje uživatel při instalaci.
- **API:** REST + JSON, není veřejné — přístup mají jen schválení addon partneři; privátní API pouze pro Premium klienty. Dokumentace: developers.shoptet.com + api.docs.shoptet.com. Klíčové koncepty, které stojí za převzetí:
  - **Webhooky** (událost → HTTPS callback, např. nová objednávka)
  - **Asynchronní požadavky** (job + polling pro velké operace: import obrázků, full snapshot dat)
  - **Rate limiter** na úrovni ochrany serveru
  - **OAuth ověření e-shopu** — addon nezná hesla uživatelů
  - **Image cuts** — předgenerované standardizované řezy obrázků
  - **Vkládání HTML na předdefinovaná místa šablony** (marketingové kódy, měření)
  - **Shipping addony a payment gateway addony** jako samostatné, dokumentované typy rozšíření — přesně model, který chceme replikovat interně
  - **Lifecycle addonu:** instalace → (pauza) → odinstalace / zrušení e-shopu, s povinností vývojáře reagovat (deaktivace účtu, smazání dat)
- **Podpora:** podpora.shoptet.cz (znalostní báze), Shoptet Univerzita (kurzy), FB skupina Poradna, stavová stránka shoptetstatus.com, blog, partnerská síť implementátorů.
- **Datové nástroje:** XML/CSV export a import produktů (vlastní Shoptet XML specifikace), data layer pro měření.

### 2.2 Eshop-rychle.cz

- Nízkonákladové krabicové řešení (stovky Kč/měs, tarify vč. Business ~990 Kč/měs), 15denní trial, vše ve fixní ceně bez doplňkových plateb.
- **Podpora:** help.eshop-rychle.cz — znalostní báze postavená na helpdeskovém SaaS (Crisp), členěná do kategorií (Nastavení e-shopu, Produkty, Zákazníci, Doprava a platby, Domény…), telefonická + e-mailová podpora. Hodnocení užitečnosti u každého článku.
- Funkce, které mají dobře vyřešené a stojí za zaznamenání do backlogu:
  - Cenové hladiny (velkoobchod/maloobchod pro registrované zákazníky — u nich vázáno na tarif Business nebo příplatkový balíček → přesně model „funkce jako modul")
  - Nákupní vs. prodejní vs. základní cena (přepočet dle kurzu), marže a zisky ve statistikách
  - „Cena od" u variant, nastavení DPH vč. automatického 0% DPH pro B2B reverse charge v EU
  - Sociální přihlášení zákazníků (Google, Facebook, Seznam, MojeID)
  - GDPR-krokovaná registrace zákazníka
  - Heslem chráněný e-shop (režim pro velkoobchod)
  - Verzované šablony (2.0/3.0/4.0) — funkce vázané na verzi šablony; poučení: šablonu verzovat od začátku
  - Trial → doběh 8 dní → smazání e-shopu (jasně komunikovaný lifecycle tenanta)

### 2.3 Další CZ platformy (kontext trhu)

- **Upgates** (~4 300 e-shopů): 4 tarify 450–3 250 Kč/měs, ~168 funkcí v ceně všech tarifů, REST API na všech tarifech (na rozdíl od Shoptetu), silná vícejazyčnost, grafický editor šablon „Designer", multistore ve vyšších tarifech. Odlišuje se otevřeností.
- **Webareal** (Unihost): konzervativní, jednoduchá krabice, dlouhá historie.
- **FastCentrik, BSshop, Binargon, Forga**: menší hráči; Forga láká tarifem 0 Kč do 10 produktů (freemium jako akviziční kanál).
- Trh: v ČR ~60+ tis. aktivních e-shopů, přes 2/3 na pronajímaných platformách. Povinnosti CZ trhu: DPH 21/12 %, OSS pro EU prodej, ISDOC, export do Pohody (XML), Zásilkovna/Balíkovna/PPL/DPD, GoPay/Comgate/GP webpay, Heureka/Zboží feedy, EET 2.0 od 1. 1. 2027 (sledovat legislativu).

### 2.4 Závěry z analýzy pro náš návrh

1. **Doplňky = hlavní monetizační i škálovací mechanismus** Shoptetu. My začneme s interními moduly, ale rozhraní modulů navrhneme tak, aby v budoucnu šlo otevřít třetím stranám (tokeny, oprávnění per endpoint, webhooky, schvalovací proces).
2. **Vše v ceně (Upgates/Eshop-rychle) vs. platba za doplňky (Shoptet)** — obchodní rozhodnutí; architektura musí umět obojí (modul má příznak `billable` a vazbu na tarif).
3. **Znalostní báze + stavová stránka + jasný lifecycle trialu** jsou hygienické minimum podpory — plánovat od spuštění, ne dodatečně.
4. **CZ lokalizace je konkurenční výhoda** proti Shopify/Wix: DPH, dopravci, platební brány, feedy, ISDOC/Pohoda. V MVP stačí základ (DPH, 2–3 dopravci, 1 brána), ale datový model s tím musí počítat.

---

## 3. Vize produktu a rozsah MVP

### 3.1 MVP — co musí umět první verze

Cíl MVP: registrovaný uživatel si **do 10 minut od registrace vytvoří funkční e-shop** na subdoméně, přidá produkty a přijme první objednávku. Ekvivalent „WordPress + WooCommerce v základu", ale jako multi-tenant SaaS.

**V MVP (jádro + moduly):**

| Oblast | Rozsah v MVP |
|---|---|
| Registrace a tvorba e-shopu | Průvodce, subdoména `nazev.platforma.cz`, trial |
| Šablona | 1 responzivní šablona, úprava loga, barev, patičky |
| SEO základ | Blade SSR storefront (§4.1.1), per-tenant `sitemap.xml` + `robots.txt`, canonical, OG meta, JSON-LD (Product, BreadcrumbList), 301 redirecty |
| Produkty | CRUD, kategorie, obrázky, varianty (1 úroveň), sklad, DPH |
| Košík a objednávky | 3krokový checkout, stavy objednávek, e-maily |
| Doprava | Ruční sazby (osobní odběr, kurýr, Zásilkovna — výdejní místa přes widget) |
| Platby | Dobírka, bankovní převod (QR platba), 1 online brána (Comgate nebo GoPay) |
| Zákazníci e-shopu | Nákup bez registrace + volitelný účet |
| Nastavení | Údaje provozovatele, DPH režim, měna CZK, texty (VOP, GDPR nájemce) |
| Doklady | Faktury / prodejní doklady k objednávkám (PDF, číselná řada, QR platba) — viz 16.6 |
| Fakturace platformy | Tarify, trial, platba kartou (opakovaná), faktury za pronájem |
| Superadmin | Přehled tenantů, správa tarifů, deaktivace, impersonace |

**Explicitně MIMO MVP (post-MVP backlog):** vlastní domény + SSL automatizace (fáze 2 — brzy!), více šablon, marketplace modulů pro třetí strany, feedy (Heureka, Zboží, Google), slevové kupóny, cenové hladiny B2B, vícejazyčnost/víceměnovost, multistore, mobilní admin aplikace, exporty do Pohody/ISDOC, blog/CMS stránky (v MVP jen statické stránky VOP/kontakt), abandoned cart, recenze, **licenční/digitální produkty s aktivačním API (kap. 17 — premium, fáze 2)**.

### 3.2 Zásada modularity (závazná)

> **Každá funkční oblast = modul.** Jádro definuje pouze: tenancy, uživatele a role, systém modulů (registrace, aktivace, hooky, migrace), sdílené služby (fronty, storage, e-mail transport, cache) a router storefront/admin. Vše ostatní — včetně produktů — je modul.

Testovací otázka pro každý návrh: *„Šel by tento modul vypnout, aniž by spadl zbytek systému?"* U produktů je odpověď „e-shop bez produktů nedává smysl", přesto modul Produkty komunikuje s ostatními výhradně přes kontrakty (interfaces) a eventy — aby šel **nahradit** (např. modulem Produkty-B2B) a **rozšiřovat** bez zásahu do jádra.

---

## 4. Architektura

### 4.1 Doporučený stack

Volba respektuje existující know-how týmu (PHP/Laravel, Vue/TypeScript):

- **Backend:** PHP 8.4, Laravel 12+ (fronty Horizon/Redis, plánovač, notifikace, Sanctum)
- **Multi-tenancy:** balíček `stancl/tenancy` (nebo `spatie/laravel-multitenancy`) — viz 4.2
- **Moduly:** `nwidart/laravel-modules` jako základ + vlastní vrstva (manifest, per-tenant aktivace, hooky) — viz kap. 5
- **Admin (tenant i superadmin):** Vue 3 + TypeScript + Inertia.js (rychlejší vývoj než SPA + Sanctum; SPA lze doplnit později)
- **Storefront:** Blade šablony renderované serverem (SEO, rychlost, jednoduchost cachování) + Alpine.js/Vue ostrůvky. **Závazné — viz §4.1.1.** Headless API pro storefront až post-MVP.
- **DB:** MySQL 8 / MariaDB (jedna DB, sdílené tabulky s `tenant_id` — viz 4.2), Redis (cache, fronty, session)
- **Storage:** S3-kompatibilní objektové úložiště (od začátku! ne lokální disk) — obrázky produktů, přílohy; CDN před ním
- **Search:** MVP: MySQL fulltext; fáze 2: Meilisearch (per-tenant index).
  **Pozor na češtinu:** InnoDB fulltext neumí stemming ani nenormalizuje diakritiku — „bunda" nenajde „bundy" ani „bundě". V MVP proto povinně: normalizovaný vyhledávací sloupec (lowercase, bez diakritiky, `utf8mb4_0900_ai_ci`) plnění při uložení produktu + prefixový `LIKE` fallback pro našeptávač. Bez toho působí vyhledávání rozbitě a je to častý důvod odchodu z e-shopu.
- **Infrastruktura:** 1–2 aplikační servery za load balancerem, oddělený DB server, oddělený worker server pro fronty; IaC (Ansible/Terraform), kontejnery volitelně
- **Monitoring:** Sentry (chyby), Uptime Kuma / Better Stack (dostupnost + veřejná stavová stránka), Laravel Telescope jen dev

### 4.1.1 Rendering policy storefrontu (ZÁVAZNÉ — SEO)

> **Storefront není webová aplikace, je to publikační médium.** SEO a organický traffic jsou hlavní marketingová hodnota, kterou nájemci pronajímáme. Klientsky renderovaný katalog tuto hodnotu ničí a je nevratně drahý na opravu. Toto rozhodnutí má přednost před pohodlím vývoje.

**Vrstva A — povinně Blade SSR** (plné HTML v první odpovědi serveru):
homepage, výpis kategorie, detail produktu, vyhledávání, statické stránky (VOP/GDPR/kontakt), výrobce a blog (fáze 2), `sitemap.xml`, `robots.txt`, XML feedy, chybové stránky (404/410/503).

**Vrstva B — povinně Blade SSR + progressive enhancement** (není SEO, ale robustnost a integrita cen):
`/kosik`, `/pokladna/doprava`, `/pokladna/udaje`, `/dekujeme/{uuid}`, `/platba/navrat`. Vazba na AK §16.3 („checkout funkční bez JS", „žádná cenová logika v JS"). Tyto stránky nesou `noindex`.

**Vrstva C — smí být Vue 3 + Inertia SPA:**
admin nájemce (`/admin/*`), superadmin, registrace a onboarding průvodce, fakturace nájemce. Vše `noindex, nofollow`.

**Vue / Alpine ostrůvky** jsou na storefrontu povoleny výhradně jako hydratace nad již vyrenderovaným HTML — výběr varianty, galerie, mini-košík, přidání do košíku, našeptávač, widget výdejních míst Zásilkovny, ARES autofill, filtry (s server-side fallbackem přes query parametry).

**Zakázáno na storefrontu:** client-side router, dotahování produktů/cen/popisů přes fetch po načtení, prázdný `<div id="app">` jako jediný obsah, meta tagy nastavované z JS, obsah indexovatelný až po interakci.

**Povinné SEO výstupy vrstvy A** (renderované serverem): `<title>`, meta description, absolutní `<link rel="canonical">`, OG a Twitter meta, JSON-LD (`Product`+`Offer` na detailu, `BreadcrumbList` všude, `ItemList` na kategorii, `Organization`/`WebSite` na homepage), strategie stránkování, 301 z historických slugů (`redirects`), 410 u smazaných produktů, per-tenant `sitemap.xml`.

**Rozpočet:** JS bundle storefrontu < 100 kB gzip. Vazba na §8 (TTFB < 200 ms) a AK §6.1 (Lighthouse ≥ 90).

Operativní checklist: [`.claude/rules/storefront-rendering.md`](../../.claude/rules/storefront-rendering.md).

### 4.2 Multi-tenancy — klíčové architektonické rozhodnutí

Varianty:

| Model | Popis | Pro | Proti |
|---|---|---|---|
| A. DB per tenant | Každý e-shop vlastní databáze | Izolace, snadný export/smazání tenanta, žádný risk úniku mezi tenanty dotazem | Stovky/tisíce DB → náročné migrace, connection pooling, provozní režie |
| B. Sdílená DB, `tenant_id` ve všech tabulkách | Jedna sada tabulek | Jednoduché migrace, agregované statistiky, levný provoz | Riziko chybějícího `where tenant_id` (únik dat!), obtížnější per-tenant obnova ze zálohy |
| C. Sdílená DB, schéma per tenant | Kompromis (PostgreSQL schémata) | Izolace + 1 server | Na MySQL nepraktické |

**Doporučení: varianta B se striktními pojistkami**, protože cílíme na stovky až tisíce malých tenantů (profil Eshop-rychle, ne Shoptet Premium):

1. **Globální scope vynucený na úrovni modelů** (trait `BelongsToTenant`, tenant context z domény requestu). Přímé DB dotazy mimo Eloquent jen přes review.
2. **Testy izolace:** automatizovaný test-suite, který pro každý endpoint ověří, že tenant A nevidí data tenanta B (seedy dvou tenantů + kontrola úniků). Povinná součást CI.
3. **`tenant_id` v každé doménové tabulce + composite indexy `(tenant_id, …)`.**
4. **Per-tenant export:** od začátku implementovat job „exportuj vše k tenantovi X" (GDPR, smazání, migrace pryč, obnova).
5. Pokud později přijde „velký" klient, lze jej vyčlenit do dedikované DB (tenancy balíčky to umí) — návrh to nesmí znemožnit.

### 4.3 Řešení domén a routing

- Tenant se identifikuje podle **Host hlavičky**: `nazev.platforma.cz` → lookup v tabulce `domains` → tenant context.
- Wildcard DNS `*.platforma.cz` + wildcard TLS certifikát.
- Admin platformy na `admin.platforma.cz` (superadmin) a `nazev.platforma.cz/admin` (tenant admin).
- **Fáze 2 — vlastní domény:** CNAME na náš endpoint, automatická emise certifikátů (Caddy on-demand TLS nebo Traefik + Let's Encrypt HTTP-01). Pozor: limity Let's Encrypt, ověření vlastnictví domény před emisí (jinak lze zneužít), stavové UI „doména čeká na DNS".

### 4.4 Fronty a asynchronní zpracování

Po vzoru Shoptet „Asynchronous Requests": vše, co může trvat > 1 s, jde do fronty s per-tenant fairness (žádný tenant nesmí frontu zahltit — oddělené fronty `default`, `imports`, `mails`, rate limit per tenant):

- import/export produktů (CSV/XML), generování obrázkových řezů, hromadné přecenění, odesílání e-mailů, generování faktur, mazání tenanta.
- Každý dlouhý job má záznam `jobs_log` viditelný tenantovi v adminu (stav: čeká / běží / hotovo / chyba + report).

### 4.5 Eventy a webhooky

- Interní **event bus** (Laravel events) je páteří komunikace mezi moduly: `OrderCreated`, `OrderPaid`, `ProductStockChanged`, `TenantCreated`, `SubscriptionExpired`…
- Moduly se na eventy pouze **subscribují**, nikdy nevolají cizí modul napřímo (výjimka: přes veřejný kontrakt/interface modulu).
- **Webhooky ven** (pro budoucí integrace a doplňky třetích stran): tabulka `webhook_endpoints` per tenant, podepisování payloadu (HMAC), retry s backoffem, log doručení. V MVP stačí infrastruktura + 2–3 eventy (order.created, order.paid), UI přidávání webhooků může být skryté.
---

## 5. Systém modulů (jádro požadavku)

### 5.1 Definice modulu

Modul je balíček kódu s manifestem, který:

```
modules/
  Products/
    module.json          # manifest
    Providers/           # service provider (registrace do jádra)
    Contracts/           # veřejné rozhraní modulu (interfaces, DTO)
    Database/Migrations/ # vlastní tabulky (prefix modulu)
    Http/                # controllery admin + storefront + API
    Resources/           # Vue komponenty adminu, Blade partialy storefrontu
    Events/ Listeners/
    routes/ (admin.php, storefront.php, api.php)
    lang/cs/
```

**module.json (manifest):**

```json
{
  "name": "products",
  "version": "1.0.0",
  "title": { "cs": "Produkty" },
  "description": { "cs": "Katalog produktů, varianty, sklad" },
  "core": true,                     // jádrový modul: nelze per-tenant vypnout
  "billable": false,                // budoucí zpoplatnění
  "requires": [],                   // závislosti na jiných modulech (semver)
  "provides": ["ProductRepository"],// kontrakty, které modul poskytuje
  "listens": ["order.created"],     // eventy, na které se váže
  "settings_schema": "settings.json", // JSON schema per-tenant nastavení
  "nav": [ { "area": "admin", "label": "Produkty", "route": "admin.products.index", "icon": "box", "order": 10 } ]
}
```

### 5.2 Životní cyklus modulu

| Fáze | Popis |
|---|---|
| **Instalace (platformní)** | Superadmin nasadí kód modulu, spustí migrace (tabulky jsou sdílené s `tenant_id`), modul se objeví v registru |
| **Aktivace per tenant** | Tenant (nebo tarif) modul zapne → záznam v `tenant_modules` (tenant_id, module, enabled, settings JSON, activated_at) → modul spustí `onActivate(tenant)` (výchozí data, např. výchozí kategorie) |
| **Deaktivace per tenant** | `onDeactivate(tenant)` — modul skryje UI a routy, **data nemaže** (reaktivace je vratná) |
| **Odinstalace per tenant** | Explicitní, s potvrzením: `onUninstall(tenant)` smaže data tenanta v tabulkách modulu (po vzoru Shoptet: vývojář musí reagovat na odinstalaci) |
| **Upgrade** | Verzované migrace; modul deklaruje kompatibilitu s verzí jádra (semver) |

### 5.3 Body rozšíření (hooky)

Aby moduly mohly zasahovat do UI a procesů bez zásahů do cizího kódu:

1. **Eventy** (viz 4.5) — doménová logika.
2. **UI sloty ve storefront šabloně** — pojmenovaná místa (`product.detail.after_price`, `checkout.summary.before_submit`, `layout.head`, `layout.footer`), do kterých modul registruje renderovatelný obsah. Ekvivalent Shoptet „vkládání HTML na předdefinovaná místa". Šablona MUSÍ tyto sloty obsahovat od první verze.
3. **Admin sloty** — záložky na kartě produktu / objednávky (`product.edit.tabs`), widgety na dashboardu.
4. **Checkout pipeline** — doprava a platby se registrují jako **poskytovatelé** přes kontrakty (viz 6.7, 6.8); checkout je iteruje, nikdy je nezná jmenovitě.
5. **Filtry cen** — řetěz `PriceModifier` (budoucí slevy, cenové hladiny) aplikovaný jednotně při každém výpočtu ceny.

### 5.4 Vazba modulů na tarify

`plans` ↔ `plan_modules` (plan_id, module, limits JSON). Při aktivaci modulu se ověřuje tarif; limity (počet produktů, prostor, e-maily/měs) vyhodnocuje jádro službou `LimitsService` — moduly se jí ptají, samy limity neimplementují.

---

## 6. Specifikace modulů (Level 3)

Formát každého modulu: Účel → Funkce → Datový model → UI → Procesy/eventy → Akceptační kritéria (AK).

### 6.0 Jádro: Tenancy & Účty (není modul, je to jádro)

**Funkce:** registrace uživatele platformy, ověření e-mailu, vytvoření tenanta průvodcem, přihlášení (+ 2FA TOTP volitelně, pro superadmin povinně), obnova hesla, role (owner; post-MVP: staff role s právy per modul), impersonace superadminem (auditovaná), lifecycle tenanta.

**Datový model (hlavní tabulky):**

- `users` (id, email, password, 2fa_secret, …) — uživatelé platformy
- `tenants` (id, name, status [trial|active|past_due|suspended|pending_deletion|deleted], trial_ends_at, plan_id, created_at, …)
- `tenant_users` (tenant_id, user_id, role) — 1 user může vlastnit více e-shopů
- `domains` (id, tenant_id, domain, is_primary, type [subdomain|custom], ssl_status)
- `tenant_modules`, `plans`, `plan_modules` (viz kap. 5)
- `audit_log` (tenant_id, user_id, action, subject, ip, created_at) — od začátku!

**Průvodce vytvořením e-shopu (onboarding):**
1. Registrace (e-mail + heslo, souhlas s VOP platformy — checkbox, verzované VOP, uložit čas a verzi souhlasu)
2. Ověření e-mailu (bez ověření nelze e-shop zveřejnit)
3. Název e-shopu → návrh subdomény (kontrola dostupnosti, slug, rezervovaná slova: www, admin, api, mail…)
4. Základní údaje: obor (jen pro statistiku), fakturační údaje provozovatele (IČO → ARES předvyplnění, DIČ, plátce DPH ano/ne)
5. Hotovo → přesměrování do admin dashboardu s checklistem („Přidej první produkt", „Nastav dopravu", „Nastav platby", „Zveřejni e-shop")

**Lifecycle tenanta (stavový automat):**

```
trial (15 dní) ──platba──► active ──neplatba──► past_due (7 dní)
   │                                             │
   └─ nevyužito ──► expired-grace (8 dní) ──► suspended (30 dní, storefront vypnut,
                                              admin read-only) ──► pending_deletion
                                              (e-mail s exportem dat) ──► deleted (hard delete + purge záloh dle retence)
```

**AK:**
- Registrace → funkční e-shop na subdoméně do 10 minut, bez zásahu superadmina.
- Tenant nikdy nevidí data jiného tenanta (automatizovaný test izolace v CI).
- Každá změna stavu tenanta odešle e-mail a zapíše se do audit logu.
- Smazání tenanta smaže/anonymizuje všechna jeho data vč. souborů v S3 a vydá potvrzení.

### 6.1 Modul: Vzhled & Šablona (`theme`)

**Účel:** render storefrontu jednou šablonou, per-tenant přizpůsobení bez zásahu do kódu.

**Funkce (MVP):**
- Nahrání loga a favicony (automatické řezy), výběr primární/sekundární barvy (CSS proměnné), typ hlavičky (logo vlevo/na střed)
- Texty: název e-shopu, claim, patička (kontakty, odkazy na stránky), sociální sítě
- Statické stránky: editor (WYSIWYG — TipTap) pro VOP, GDPR, Kontakt, O nás; tenant je POVINEN mít vlastní VOP a GDPR (viz kap. 11) — checklist mu je hlídá
- Vlastní HTML/JS bloky do slotů `layout.head` a `layout.footer` (měřicí kódy) — **sandbox: jasné upozornění, že za vložené kódy odpovídá tenant**
- Náhled před publikací, režim „e-shop skryt" (heslo / maintenance) — po vzoru Eshop-rychle
- Šablona verzovaná (v1); struktura umožňuje registraci dalších šablon jako modulů (`theme.provides = ThemeInterface`)

**Datový model:** `theme_settings` (tenant_id, key, value JSON), `pages` (tenant_id, slug, title, body, is_published, seo_title, seo_description).

**AK:** změna barev/loga se projeví do 1 min (cache invalidace); Lighthouse výkon storefrontu ≥ 90 na vzorovém shopu s 50 produkty; sloty pro moduly existují a jsou zdokumentované.

### 6.2 Modul: Produkty (`products`)

**Účel:** katalog. Jádro obchodní hodnoty; navržen jako nahraditelný a rozšiřitelný modul.

**Funkce (MVP):**
- CRUD produktu: název, slug (unikátní per tenant), krátký popis, popis (WYSIWYG), stav (koncept/aktivní/skryt), viditelnost
- Ceny: prodejní cena s DPH i bez, sazba DPH (21/12/0 % — číselník spravuje jádro, ne modul), nákupní cena (jen admin — pro budoucí marže po vzoru Eshop-rychle), běžná cena (přeškrtnutá)
- **Varianty (1 úroveň parametrů v MVP, datový model na 2+):** parametry (Barva, Velikost) → kombinace = varianta s vlastní cenou, skladem, EAN, obrázkem; „Cena od" ve výpisu
- Sklad: sledovat ano/ne, množství, chování při vyprodání (skrýt / zobrazit „vyprodáno" / dovolit objednat), rezervace skladem při objednávce (konfig.)
- Obrázky: drag&drop upload, řazení, alt texty; **image cuts** po vzoru Shoptetu: originál v S3 + předgenerované řezy (thumb 150, list 400, detail 1200, WebP/AVIF) generované jobem
- Identifikátory: SKU/kód, EAN, výrobce (číselník), hmotnost (pro dopravu!)
- SEO per produkt: title, description, OG obrázek; strukturovaná data (JSON-LD Product) v šabloně
- Import/export CSV (fáze 1.1): mapování sloupců, dry-run s reportem chyb, job ve frontě; XML feed struktura připravena pro Heureka/Zboží (fáze 2)
- Hromadné operace: změna stavu, přesun kategorie, změna DPH, přecenění o % (job)

**Datový model:**
- `products` (tenant_id, name, slug, description, status, tax_rate_id, price, compare_at_price, purchase_price, sku, ean, manufacturer_id, weight_g, stock_tracked, stock_qty, stock_policy, seo_*, …)
- `product_options` (tenant_id, product_id, name, position), `product_option_values`
- `product_variants` (product_id, sku, ean, price, stock_qty, image_id, option_value_ids JSON)
- `product_images` (product_id, path, position, alt), `manufacturers`
- `product_category` (product_id, category_id) — M:N

**Kontrakt pro ostatní moduly:** `ProductCatalog` interface (`findBySlug`, `search`, `decrementStock`, `price(product, context)` — context prochází řetězem `PriceModifier`). Košík/objednávky NIKDY nesahají do tabulek produktů napřímo.

**AK:** založení produktu s variantami < 2 min; výpis kategorie s 1 000 produkty < 300 ms (server render, cache); import 5 000 řádků CSV proběhne jobem s validním reportem; snížení skladu je atomické (žádný oversell při souběhu — DB lock/atomický decrement).

### 6.3 Modul: Kategorie & Navigace (`categories`)

- Stromová struktura (nested set — `staudenmeir/laravel-adjacency-list` nebo kylekatarnls), drag&drop řazení, obrázek a popis kategorie, SEO pole, skrytí kategorie
- Automatické menu storefrontu z kategorií + ruční položky (odkaz na stránku/URL)
- Breadcrumbs, kanonické URL (produkt má primární kategorii)
- **Datový model:** `categories` (tenant_id, parent_id, name, slug, description, image, position, is_visible, seo_*)
- **AK:** přesun podstromu nerozbije URL (301 přesměrování ze starých slugů — tabulka `redirects`).

### 6.4 Modul: Košík & Checkout (`checkout`)

**Účel:** cesta zákazníka od košíku k odeslané objednávce. Kritický konverzní bod — jednoduchost > funkce.

**Funkce (MVP):**
- Košík: session-based (host) / vázaný na účet; přidání z výpisu i detailu, změna množství, odstranění; mini-košík v hlavičce; přepočet při změně cen/skladu s upozorněním
- Checkout 3 kroky (1: košík, 2: doprava+platba, 3: údaje+rekapitulace) NEBO one-page — rozhodnout A/B později, implementovat jako kroky:
  - Krok dopravy/platby: dynamický výpis od registrovaných poskytovatelů (viz 6.7/6.8), ceny dopravy dle pravidel, doprava zdarma od X Kč
  - Údaje: e-mail, telefon, fakturační adresa; checkbox „Doručit jinam"; checkbox „Nakupuji na firmu" (IČO/DIČ → ARES); poznámka
  - Souhlasy: povinný checkbox VOP **tenanta** (ne naše!), volitelný marketingový souhlas — obojí logováno (čas, IP, verze textu)
- Validace skladu a cen těsně před vytvořením objednávky; idempotence odeslání (token proti dvojkliku)
- Výpočty: DPH per položka dle sazby, zaokrouhlení dle CZ pravidel, doprava a platba jako položky objednávky se svou DPH

**Datový model:** `carts` (tenant_id, session_id/customer_id, expires_at), `cart_items` (cart_id, product_variant_id, qty, price_snapshot).

**AK:** kompletní nákup hostem do 90 s; ceny v košíku vždy odpovídají cenám při vložení, nebo je změna explicitně hlášena; nelze objednat vyprodanou variantu (při stock_policy=block); dvojité odeslání vytvoří 1 objednávku.

### 6.5 Modul: Objednávky (`orders`)

**Funkce (MVP):**
- Číselná řada per tenant (konfigurovatelný formát, např. `{YYYY}{NNNN}`, bez děr kvůli účetnictví)
- Stavy: nová → přijatá → zpracovává se → odeslaná → doručená / stornovaná; + platební stav (nezaplaceno/zaplaceno/vráceno) odděleně od logistického
- Detail objednávky: položky (snapshot názvu, ceny, DPH v okamžiku objednávky — nikdy živý odkaz na produkt!), adresy, doprava, platba, historie stavů, interní poznámky
- Ruční akce: změna stavu (→ e-mail zákazníkovi, volitelně), úprava položek před expedicí, storno s důvodem, ruční vytvoření objednávky (telefonická)
- Přehled: filtrace (stav, datum, platba), fulltext (číslo, e-mail, jméno), export CSV
- Eventy: `order.created`, `order.paid`, `order.status_changed`, `order.cancelled` → e-maily, sklad, budoucí webhooky

**Datový model:** `orders` (tenant_id, number, status, payment_status, customer_id?, email, phone, billing JSON, shipping JSON, shipping_method_snapshot JSON, payment_method_snapshot JSON, subtotal, tax_total, total, currency, consents JSON, created_at…), `order_items` (order_id, product_variant_id?, name_snapshot, qty, unit_price, tax_rate, total), `order_status_history`.

**AK:** objednávka je immutable snapshot (změna produktu po objednání nemění historii); číselná řada bez děr a duplicit i při souběhu; storno vrací sklad.

### 6.6 Modul: Zákazníci e-shopu (`customers`)

- Nákup bez registrace (MVP default) + volitelná registrace (e-mail+heslo; sociální přihlášení post-MVP)
- Účet zákazníka: historie objednávek, uložené adresy, změna hesla, žádost o smazání účtu (GDPR — anonymizace objednávek, ne smazání účetních dokladů)
- Admin: seznam zákazníků (agregace i z hostovských objednávek dle e-mailu), detail s historií, poznámky
- Připraveno pro budoucí moduly: skupiny zákazníků (→ cenové hladiny B2B po vzoru Eshop-rychle), marketingové souhlasy s logem
- **Datový model:** `customers` (tenant_id, email UNIQUE per tenant, password?, name, phone, marketing_consent_at, created_at), `customer_addresses`.
- **AK:** GDPR výmaz zákazníka: osobní údaje anonymizovány, objednávky (účetní data) zachovány s pseudonymem; e-mail zákazníka unikátní per tenant, ne globálně.

### 6.7 Modul: Doprava (`shipping`) — vzorový „rozšiřitelný modul"

**Architektura (důležité):** jádro modulu definuje kontrakt

```php
interface ShippingProvider {
    public function key(): string;                       // 'personal_pickup', 'zasilkovna'
    public function title(Tenant $t): string;
    public function isAvailable(CartContext $ctx): bool; // váha, cena, země
    public function price(CartContext $ctx): Money;      // dle pravidel tenanta
    public function checkoutFields(): array;             // např. widget výdejních míst
    public function onOrderCreated(Order $o): void;      // rezervace, štítek (fáze 2)
}
```

Konkrétní dopravci jsou **sub-moduly registrující ShippingProvider** — přesně model Shoptet „Shipping Addons". Checkout o dopravcích nic neví.

**MVP poskytovatelé:**
1. Osobní odběr (adresa, otevírací doba, cena 0/N Kč)
2. Kurýr na adresu (obecný — tenant si pojmenuje: PPL/DPD/ČP; ruční pravidla)
3. Zásilkovna — výdejní místa přes oficiální JS widget, uložení ID+názvu místa k objednávce (API tvorba zásilek až fáze 2)

**Pravidla cen (per metoda, per tenant):** základní cena, zdarma od X Kč, omezení váhou (min/max g), omezení zemí (MVP: CZ, příprava SK), dobírkový příplatek (vazba na platbu — matice povolených kombinací doprava×platba)

**Datový model:** `shipping_methods` (tenant_id, provider_key, title, enabled, position, settings JSON, price, free_from, weight_min, weight_max, countries JSON), `shipping_payment_matrix`.

**AK:** přidání nového dopravce = nový sub-modul bez změny checkoutu; nedostupná metoda (váha/země) se v checkoutu nenabízí; Zásilkovna vyžaduje výběr místa před odesláním objednávky.

### 6.8 Modul: Platby (`payments`) — druhý vzorový „rozšiřitelný modul"

**Kontrakt:**

```php
interface PaymentProvider {
    public function key(): string;                 // 'cod', 'bank_transfer', 'comgate'
    public function isOnline(): bool;
    public function initiate(Order $o): PaymentIntent; // redirect URL / instrukce
    public function handleWebhook(Request $r): PaymentResult; // ověření podpisu!
    public function refund(Order $o, Money $m): RefundResult; // fáze 2
}
```

**MVP poskytovatelé:**
1. **Dobírka** — příplatek, payment_status řeší tenant ručně
2. **Bankovní převod** — účet tenanta, VS = číslo objednávky, QR platba (SPD formát) v e-mailu i na děkovné stránce; párování ruční (auto-párování z API banky = fáze 3)
3. **Comgate NEBO GoPay** (vybrat jednu pro MVP; Comgate má jednodušší onboarding pro malé e-shopy) — **režim: každý tenant má VLASTNÍ smlouvu a merchant ID** (my nejsme platební zprostředkovatel! — viz rizika kap. 12), tenant vloží credentials do nastavení modulu; platba redirectem, webhook mění payment_status, stavy pending/paid/cancelled/expired, opakování platby z e-mailu

**Datový model:** `payment_methods` (tenant_id, provider_key, title, enabled, fee, settings ENCRYPTED JSON), `payments` (order_id, provider, external_id, status, amount, raw_response JSON, created_at).

**AK:** webhook ověřuje podpis a je idempotentní (opakované doručení nezdvojí zaplacení); credentials tenantů šifrovány (Laravel encrypted casts, klíč mimo DB); nikdy nelogujeme celé karetní údaje (PCI DSS scope = žádný, karty jen na straně brány).

### 6.9 Modul: E-maily (`mailer`)

- Transakční e-maily: potvrzení objednávky (s rekapitulací, QR platbou), změna stavu, expedice (číslo zásilky), registrace zákazníka, reset hesla; + platformní e-maily tenantům (trial, faktury) — oddělené šablony i odesílací domény!
- Odesílání: platformní SMTP/API (Postmark/SES/Mailgun) z domény `mail.platforma.cz`; **From = jméno e-shopu, Reply-To = e-mail tenanta**. Vlastní odesílací doména tenanta (SPF/DKIM průvodce) = fáze 2. Pozor na reputaci sdílené IP (viz rizika).
- Šablony: výchozí texty s proměnnými ({order_number}, {total}…), tenant může texty upravit (jednoduchý editor, náhled, reset na výchozí)
- Log odeslaných e-mailů per tenant (stav doručení z webhooku poskytovatele), rate limit per tenant
- **AK:** e-mail o objednávce odejde do 1 min; bounce/complaint webhooky zpracovány; tenant nevidí e-maily jiných tenantů.

### 6.10 Modul: Nastavení e-shopu (`settings`)

- Údaje provozovatele (název, IČO, DIČ, adresa, e-mail, telefon, plátce DPH) — zobrazují se v patičce, na dokladech, v e-mailech; **bez vyplnění nelze e-shop zveřejnit** (právní požadavek — viz kap. 11)
- Ceny/DPH: režim plátce/neplátce (neplátce: ceny bez DPH agendy), výchozí sazba, zaokrouhlování
- Provoz: e-shop zveřejněn/skryt, heslo pro vstup, maintenance text
- Jednotný settings storage: `settings` (tenant_id, module, key, value JSON) + JSON schema validace z manifestu modulu

### 6.11 Modul: Fakturace & Tarify platformy (`billing`) — naše příjmy

- Tarify: MVP navrhuji 2 (Start ~390 Kč, Business ~890 Kč/měs bez DPH — kalibrovat dle trhu: Eshop-rychle 150–990, Shoptet od ~400) + roční platba se slevou ~10–20 %
- Limity tarifu: počet produktů, storage GB, e-maily/měs (vyhodnocuje `LimitsService`; překročení = upozornění a výzva k upgrade, nikdy tichá blokace prodeje)
- Platby předplatného: karta s uloženým tokenem a rekurencí (GoPay/Comgate umí, případně Stripe — pozor Stripe vs. CZ fakturace), bankovní převod pro roční platby
- Proforma → po zaplacení daňový doklad (PDF, naše číselná řada, DPH 21 %), e-mailem + ke stažení v adminu; dunning: upomínky D+3, D+7, suspend D+14
- Upgrade/downgrade s poměrnou částkou (proration) — MVP může zjednodušit: změna od dalšího období
- **AK:** trial → platba → aktivace bez zásahu superadmina; faktury splňují náležitosti daňového dokladu; selhání rekurentní platby spouští dunning sekvenci.

### 6.12 Superadmin (`platform-admin`)

- Dashboard: počet tenantů dle stavu, MRR, nové registrace, konverze trialu
- Správa tenantů: vyhledání, detail (tarif, platby, využití limitů, moduly), suspend/obnovení s důvodem, **impersonace** („přihlásit se jako" — auditováno, banner v UI), vynucené smazání
- Správa tarifů a modulů (zapnout modul globálně / per tarif / per tenant — kill switch vadného modulu!)
- Fronta jobů a chybovost (Horizon), přehled e-mail reputace, stavová stránka (externí)
- Nástroje podpory: náhled e-shopu tenanta, log auditu, znovu-odeslání e-mailu
---

## 7. Podpora a dokumentace (produktová část, ne „až potom")

Poučení z obou platforem: znalostní báze a jasná komunikace lifecycle je součást produktu.

1. **Znalostní báze** `napoveda.platforma.cz` — po vzoru help.eshop-rychle.cz: kategorie kopírující menu adminu (Produkty, Doprava, Platby, Nastavení, Domény, Fakturace), články s obrázky, hodnocení „Pomohl vám článek?", vyhledávání. Technicky: Crisp/HelpScout/Chatwoot (self-host, sedí k preferenci local-first), nebo statický web (VitePress) + widget.
2. **Kontextová nápověda v adminu** — ikona „?" u sekcí → článek KB; onboarding checklist na dashboardu.
3. **Helpdesk:** e-mail → ticketing (Chatwoot/FreeScout self-host); SLA interně: první odpověď do 24 h v pracovní dny; telefon až s růstem.
4. **Stavová stránka** (status.platforma.cz, externě hostovaná — musí běžet, i když my ne) + plánované odstávky e-mailem.
5. **Changelog / novinky** v adminu (widget „Co je nového").
6. **Dokumentace API a webhooků** (developers.platforma.cz) — až otevřeme API třetím stranám; strukturu psát průběžně interně.

---

## 8. Nefunkční požadavky

| Oblast | Požadavek |
|---|---|
| Výkon storefront | TTFB < 200 ms (cache), plné načtení < 2 s na 4G; page cache per tenant s invalidací eventy (product.updated…) |
| Výkon admin | Odezvy < 500 ms na běžné operace do 10 000 produktů/tenant |
| Dostupnost | Cíl 99,5 % (MVP), měřeno externě; bezvýpadkové deploye (rolling/queue drain) |
| Škálování | Horizontálně stateless app servery; DB read replika až dle potřeby |
| Zálohy | DB: denní full + binlog (point-in-time), retence 30 dní; S3 versioning; **testovaná obnova 1× za čtvrtletí**; obnova jednoho tenanta = dokumentovaný postup (export z bodu 4.2/4) |
| Bezpečnost | OWASP ASVS L2 jako vodítko; 2FA; rate limiting loginů; CSP na adminu; šifrování citlivých settings; závislosti — automatický audit (Dependabot/Renovate); penetrační test před ostrým spuštěním |
| Izolace tenantů | CI test-suite izolace (viz 4.2); upload souborů: prefix `tenants/{id}/` + podepsané URL |
| GDPR | Viz kap. 11; logy s osobními údaji retence 90 dní |
| Lokalizace | CZ v MVP; všechny texty v lang souborech od začátku (SK = levná expanze) |
| Observabilita | Sentry, strukturované logy (tenant_id v každém logu!), metriky front, alerting (PagerDuty/ntfy) |

---

## 9. Datová a integrační strategie (výhled)

- **Exporty:** CSV produktů/objednávek v MVP; XML feedy (Heureka, Zboží.cz, Google Merchant) fáze 2 — generované jobem do S3, servírované z CDN s cache 1–6 h.
- **Účetnictví:** export objednávek/dokladů pro Pohodu (XML) a ISDOC — fáze 3; do té doby CSV.
- **Veřejné API + webhooky pro tenanty:** fáze 3; design už teď: tokeny per tenant s scope, rate limit, verze `/api/v1`, dokumentace OpenAPI. Tím se otevře cesta k marketplace doplňků (model Shoptet: doplněk běží na infrastruktuře vývojáře, my schvalujeme scope oprávnění a uživatel je potvrzuje).
- **EET 2.0 (od 1. 1. 2027):** sledovat finální podobu; pokud dopadne na e-shopy s platbami v hotovosti/dobírkou, bude to modul `eet` — do specifikace zapsat jako sledované riziko, ne závazek.

---

## 10. Fáze a roadmapa

**Fáze 0 — základy (4–6 týdnů):** jádro tenancy, systém modulů, CI s testy izolace, deploy pipeline, S3, fronty, superadmin skeleton.

**Fáze 1 — MVP (12–16 týdnů po F0):** moduly dle kap. 6, 1 šablona, billing, KB se ~30 články, právní dokumenty, beta s 5–10 pilotními e-shopy (zdarma za feedback).

**Fáze 1.5 — hardening (4 týdny):** penetrační test, zátěžový test (cíl: 200 tenantů, 50 rps storefront), obnova ze zálohy nanečisto, dunning, stavová stránka.

**Fáze 2 (dle trakce):** vlastní domény + auto SSL, kupóny/slevy, Heureka/Zboží feedy, další dopravci (Balíkovna, PPL API se štítky), druhá platební brána, sociální login zákazníků, e-mail doména tenanta, blog modul.

**Fáze 3:** cenové hladiny B2B, veřejné API + webhooky, Pohoda/ISDOC, vícejazyčnost, marketplace modulů třetích stran.

**Metriky úspěchu MVP:** ≥ 50 % dokončených onboardingů (registrace → zveřejněný e-shop), konverze trial→placený ≥ 15 %, churn < 5 %/měs, < 2 h podpory na tenanta/měs.

---

## 11. Právní a compliance rámec (kritické — konzultovat s právníkem)

> Pozn.: toto je technicko-produktový souhrn, ne právní rada. Před spuštěním nechat zpracovat advokátem se specializací na IT/e-commerce.

1. **VOP platformy (my ↔ tenant):**
   - Předmět: pronájem software (SaaS), ne prodej zboží. Explicitně: **neneseme odpovědnost za obsah, produkty, ceny, marketing, plnění objednávek ani právní compliance e-shopu tenanta**; tenant je samostatný podnikatel a výhradní provozovatel svého obchodu vůči zákazníkům.
   - Zákaz zneužití: seznam zakázaného zboží/obsahu (nelegální zboží, padělky, léčiva bez oprávnění…), právo suspendovat při porušení (notice-and-action).
   - SLA/dostupnost „best effort" bez garancí v základních tarifech; limitace naší odpovědnosti do výše N měsíčních plateb (pozor na kogentní ustanovení OZ).
   - Lifecycle dat: co se děje s daty při ukončení (lhůta na export, poté smazání).
   - Změny VOP: notifikace + právo výpovědi (žádné „tiché akceptace" bez oznámení — přesně typ klauzule, který sami kritizujeme u dodavatelů).
2. **Zpracovatelská smlouva (DPA, čl. 28 GDPR):** tenant = správce osobních údajů svých zákazníků, **my = zpracovatel**. Povinná písemná smlouva (součást VOP), seznam sub-zpracovatelů (hosting, e-mail poskytovatel, S3, platební brána tenanta ne — ta je jeho), TOMs (technická a organizační opatření), hlášení incidentů do 72 h, asistence při výkonu práv subjektů.
3. **Naše role správce:** pro údaje tenantů samotných (fakturace, účty) jsme správce → vlastní zásady zpracování, cookies lišta na našem webu.
4. **DSA (nařízení o digitálních službách):** jako hosting/zprostředkovatel máme povinnosti notice-and-action (kontaktní místo pro hlášení nelegálního obsahu, proces vyřízení, transparentnost) — podceňované, ale relevantní pro „pronajímáme platformu, neodpovídáme za obsah". Správné nastavení procesů tuto naši ne-odpovědnost teprve podepírá (safe harbour funguje jen při pasivní roli + reakci na oznámení).
5. **Povinnosti tenanta hlídané produktem (checklist, ne vynucení):** identifikace provozovatele na webu (§ 435 OZ, ŽZ), vlastní VOP a reklamační řád, GDPR informace pro zákazníky, cookies lišta (fáze 2 modul consent), tlačítko objednávky „objednávka zavazující k platbě". My dodáme VZOROVÉ šablony textů s výhradou, že jde o vzor a odpovědnost za finální znění nese tenant.
6. **Platby:** nikdy nesmíme přijímat peníze zákazníků na náš účet a přeposílat tenantům — byli bychom platební instituce (licence ČNB). Proto model „každý tenant má vlastní smlouvu s bránou". Pokud někdy „platby v ceně" (model Shoptet Pay), pak jen přes partnerství s licencovaným poskytovatelem.
7. **AML/daně:** naše fakturace tenantům = běžná B2B služba, DPH 21 %; OSS se nás týká jen při prodeji tenantům mimo CZ.
8. **Ochranné známky a šablony e-mailů:** neumožnit tenantům vydávat se za platformu (subdomény typu `podpora.platforma.cz` rezervované).

---

## 12. Rizika a na co si dát pozor (lessons learned předem)

### Technická
1. **Únik dat mezi tenanty** — riziko č. 1 modelu sdílené DB. Mitigace: global scopes, CI testy izolace, code review pravidlo pro raw SQL, tenant_id v S3 cestách i cache klíčích (`cache:tenant:{id}:…`).
2. **„Noisy neighbour"** — jeden tenant (import 50k produktů, virální kampaň) zpomalí ostatní. Mitigace: per-tenant rate limity, oddělené fronty, limity tarifů, page cache.
3. **Migrace sdílených tabulek** — ALTER TABLE na tabulce s miliony řádků = výpadek. Mitigace: online DDL (gh-ost/pt-osc) od chvíle, kdy data narostou; migrace vždy zpětně kompatibilní (expand→migrate→contract).
4. **E-mailová reputace sdílené domény** — jeden spamující tenant zablacklistuje všechny. Mitigace: rate limity, monitoring bounce/complaint rate per tenant, automatický suspend odesílání, postupný přechod velkých tenantů na vlastní domény.
5. **Soubory a mazání tenantů** — orphan soubory v S3, nedotažené purge. Mitigace: prefix per tenant, mazání jobem s reportem, lifecycle policy.
6. **Wildcard SSL vs. vlastní domény** — on-demand emise certifikátů bez ověření vlastnictví = vektor zneužití; limity Let's Encrypt při špičce registrací.
7. **Oversell skladu** při souběhu objednávek — atomické operace, ne read-modify-write.
8. **Zaokrouhlování DPH** — počítat v haléřích (integer), definovat jednoznačně pořadí (per položka vs. per doklad) a držet konzistentně doklad vs. brána (rozdíl 1 haléř = zamítnutá platba).
9. **Verzování šablony** — každá úprava šablony se dotkne všech tenantů. Mitigace: šablona verzovaná, vizuální regresní testy (Percy/Lost Pixel) na vzorových shopech, feature flagy.
10. **Kill switch modulů** — vadný modul musí jít globálně vypnout bez deploye.

### Produktová/obchodní
11. **Rozsah MVP se nafoukne** („ještě kupóny, ještě feedy…") — hlídat kap. 3.1; vše mimo = backlog fáze 2.
12. **Konkurence je levná a zavedená** (Eshop-rychle od 150 Kč, vše v ceně) — potřebujeme jasnou diferenciaci: rychlost, modernost adminu, férové ceny, osobní podpora, niche (např. B2B moduly, lokální integrace). Bez odpovědi na „proč ne Shoptet?" nespouštět marketing.
13. **Podpora sežere kapacitu** — každý tenant-začátečník generuje dotazy. Mitigace: onboarding checklist, KB, šablonové odpovědi; podpora je náklad v ceně tarifu — kalkulovat.
14. **Konverze trialu** — trial bez aktivace (žádný produkt) nekonvertuje; aktivační e-mailová sekvence (den 1/3/7/12) je součást MVP.
15. **Vendor lock-in obavy zákazníků** — nabídnout jednoduchý export všech dat (CSV) jako feature, ne překážku; buduje důvěru (a je to i GDPR povinnost).
16. **Právní podcenění** (kap. 11) — DPA a VOP před prvním platícím tenantem, ne po.
17. **Závislost na jedné platební bráně / e-mail poskytovateli** — abstrakce providerů (máme) + mít otestovanou zálohu.
18. **EET 2.0 / legislativa 2027** — sledovat; nepodcenit ani novely spotřebitelského práva (tlačítková novela, recenze, slevy z nejnižší ceny za 30 dní — dotkne se modulu slev ve fázi 2).

---

## 13. Otevřené otázky k rozhodnutí

1. Název platformy + domény (rezervovat i .sk). Souvisí s existujícími doménami (WooShop.cz?).
2. Comgate vs. GoPay pro MVP (onboarding pro malé e-shopy, poplatky, rekurence pro náš billing).
3. Cenotvorba: 2 tarify a jaké limity? Freemium do N produktů (model Forga) jako akvizice — ano/ne?
4. Checkout: 3 kroky vs. one-page (rozhodnout testem v betě).
5. Trial 15 vs. 30 dní; vyžadovat kartu při registraci (vyšší kvalita leadů, nižší objem) — doporučuji NE v MVP.
6. Hosting: VPS (Hetzner) vs. CZ poskytovatel (latence, cena, GDPR optika „data v EU" splní obojí).
7. Kdo píše obsah KB a vzorové právní texty (interně + advokát)?
8. Pilotní segment pro betu (např. drobní výrobci/řemeslníci?) — ovlivní prioritizaci modulů fáze 2.

---

## Příloha A: Mapa eventů (MVP)

| Event | Producent | Konzumenti |
|---|---|---|
| tenant.created | jádro | mailer (uvítání), billing (trial), theme (výchozí nastavení) |
| tenant.status_changed | jádro/billing | mailer, storefront router (vypnutí) |
| product.updated / stock_changed | products | cache invalidace, (fáze 2: feedy) |
| order.created | checkout | orders, products (sklad), mailer, payments (initiate) |
| payment.confirmed | payments | orders (payment_status), mailer |
| order.status_changed | orders | mailer |
| subscription.payment_failed | billing | mailer (dunning), jádro (past_due) |

## Příloha B: Checklist zveřejnění e-shopu (vynucovaný)

- [ ] Ověřený e-mail vlastníka
- [ ] Vyplněné údaje provozovatele (název/IČO/adresa/kontakt)
- [ ] Alespoň 1 aktivní produkt
- [ ] Alespoň 1 metoda dopravy a 1 platby
- [ ] Publikovaná stránka VOP a GDPR (obsah = odpovědnost tenanta)
- [ ] (doporučeno) Logo a barvy, kontaktní stránka

---
---

# ČÁST II — DETAILNÍ ROZPRACOVÁNÍ (v1.1)

Zásada této části: **„Platím měsíční poplatek za platformu → mohu plnohodnotně prodávat."** Vše, co je nutné k rozjetí a provozu e-shopu, je v základním tarifu (moduly `base`). Prémiové moduly (`premium`) jsou naznačeny a rozpracují se později.

## 14. Rozdělení modulů: základní tarif vs. premium

| Modul | Úroveň | Poznámka |
|---|---|---|
| Jádro (tenancy, účty, moduly, limity) | vždy | není vypnutelné |
| `theme` — Vzhled a stránky | **base** | 1 šablona, plná úprava |
| `products` — Produkty | **base** | vč. variant, skladu, importu CSV |
| `categories` — Kategorie a navigace | **base** | |
| `checkout` — Košík a pokladna | **base** | |
| `orders` — Objednávky | **base** | |
| `customers` — Zákazníci | **base** | účty zákazníků, GDPR |
| `shipping` — Doprava | **base** | 3 poskytovatelé v MVP |
| `payments` — Platby | **base** | dobírka, převod+QR, 1 brána (smlouva tenanta) |
| `mailer` — E-maily | **base** | transakční e-maily, editace textů |
| `settings` — Nastavení | **base** | |
| `billing` — Fakturace platformy | vždy | naše příjmy |
| `docs` — Doklady k objednávkám (faktury tenanta) | **base** | viz 16.6 — bez toho nelze reálně prodávat |
| `licensing` — Licenční/digitální produkty | **premium** (fáze 2) | viz kap. 17 |
| `coupons`, `b2b-pricing`, `feeds`, `multilang`, `abandoned-cart`, `api-access`, `custom-domain-email` | premium | jen náznak, kap. 18 |

Pozn.: vlastní doména + SSL je **base** (fáze 2 nasazení, ale bez příplatku) — bez vlastní domény nelze mluvit o plnohodnotném e-shopu; příplatek za ni by byl konkurenční nevýhoda.

---

## 15. Jádro platformy — detailní specifikace

### 15.1 Služby jádra (kernel services)

Jádro poskytuje modulům výhradně tyto služby (nic jiného modul nesmí z jádra volat):

| Služba | Rozhraní (výběr metod) | Popis |
|---|---|---|
| `TenantContext` | `current(): ?Tenant`, `id()`, `runAs(Tenant $t, fn)` | Aktuální tenant requestu/jobu. `runAs` pro systémové joby (generování faktur všem). |
| `ModuleRegistry` | `all()`, `enabledFor(Tenant)`, `isEnabled(Tenant, key)`, `activate/deactivate/uninstall(Tenant, key)` | Registr modulů, lifecycle, validace závislostí (topologické řazení dle `requires`). |
| `SettingsService` | `get(module, key, default)`, `set(...)`, `schemaFor(module)` | Per-tenant nastavení, validace proti JSON schema z manifestu, cache `settings:{tenant}:{module}`. |
| `LimitsService` | `check(limit, delta=1): LimitResult`, `usage(limit)` | Vyhodnocení limitů tarifu (products_count, storage_mb, emails_month). Vrací allow / warn(80 %) / block + text pro UI. |
| `EventBus` | standardní Laravel events + `transactionalDispatch()` | Eventy se publikují až po commitu DB transakce (outbox pattern light — tabulka `pending_events` flushovaná po commitu), aby listener neviděl neexistující data. |
| `FileStorage` | `putTenantFile(path, file)`, `signedUrl(path, ttl)`, `deleteTenantPrefix()` | Vynucuje prefix `tenants/{id}/…`; modul nikdy nepracuje s S3 přímo. |
| `MailService` | `sendTemplated(tenant, template, to, data)` | Jediná cesta k odeslání e-mailu; řeší rate limit, log, Reply-To, suppression list. |
| `JobMonitor` | `track(job): TrackedJob`, progress API | Dlouhé joby s progresem viditelným v adminu (viz 4.4). |
| `AuditLog` | `log(action, subject, meta)` | Automaticky doplní tenant/user/IP. Povinné pro destruktivní akce. |
| `SequenceService` | `next(tenant, series)` | Bezpečné číselné řady (objednávky, doklady) — `SELECT … FOR UPDATE` na řádku řady, bez děr. |
| `FeatureFlags` | `enabled(flag, tenant?)` | Postupné zapínání funkcí (procenta tenantů, whitelist). |
| `Money` | value object, integer haléře | Veškeré částky v systému. Zákaz float. |

### 15.2 Průchod requestu (middleware pipeline)

```
Request → ResolveHost (domains → tenant | platform) 
        → pokud platforma: superadmin/registrace routy
        → pokud tenant:
            → CheckTenantStatus (suspended → 503 stránka „e-shop nedostupný"; 
               pending_deletion → totéž; trial/active/past_due → pokračuj)
            → SetTenantContext (+ nastavení DB scope, cache prefix, locale, měna)
            → větev /admin → auth:tenant, ověření role, CSRF, 2FA gate
            → větev storefront → page cache (viz 15.6) → render
```

- Tenant context se **propaguje do jobů** automaticky (serializace tenant_id v payloadu, middleware jobu jej obnoví). Job bez tenant contextu smí sahat jen na platformní tabulky (lint pravidlo).

### 15.3 Datový model jádra (kompletní)

```sql
users(id, email UQ, password, name, phone, locale, two_fa_secret NULL,
      two_fa_confirmed_at NULL, last_login_at, created_at, updated_at)

tenants(id, uuid UQ, name, status ENUM(trial,active,past_due,suspended,
        pending_deletion,deleted), plan_id FK, trial_ends_at, suspended_at,
        deletion_requested_at, billing_name, billing_ico, billing_dic,
        billing_address JSON, vat_payer BOOL, country CHAR(2) DEF 'CZ',
        currency CHAR(3) DEF 'CZK', created_at, updated_at)

tenant_users(tenant_id, user_id, role ENUM(owner,staff), permissions JSON NULL,
             invited_at, joined_at, PK(tenant_id,user_id))

domains(id, tenant_id, domain UQ, type ENUM(subdomain,custom), is_primary,
        ssl_status ENUM(none,pending,issued,error), verified_at)

modules(key PK, version, core BOOL, level ENUM(base,premium), enabled_globally BOOL)
tenant_modules(tenant_id, module_key, enabled BOOL, settings JSON,
               activated_at, deactivated_at, PK(tenant_id,module_key))

plans(id, key, name, price_month, price_year, level ENUM(base,premium), is_public,
      limits JSON)          -- {"products":500,"storage_mb":2048,"emails_month":3000}
plan_modules(plan_id, module_key)

sequences(tenant_id, series, prefix, next_number, PK(tenant_id,series))
settings(tenant_id, module, key, value JSON, PK(tenant_id,module,key))
audit_log(id, tenant_id NULL, user_id NULL, action, subject_type, subject_id,
          meta JSON, ip, created_at)  -- partition/archivace po 12 měs.
jobs_log(id, tenant_id, type, status, progress, report JSON, created_at, finished_at)
webhook_endpoints(id, tenant_id, url, secret, events JSON, is_active)
webhook_deliveries(id, endpoint_id, event, payload JSON, status, attempts, last_error)
pending_events(id, tenant_id, event, payload JSON, created_at)  -- outbox
redirects(tenant_id, from_path, to_path, code DEF 301)
```

Konvence: všechny doménové tabulky `tenant_id BIGINT NOT NULL` + první sloupec composite indexů; FK na `tenants` s `ON DELETE RESTRICT` (mazání jen řízeným purge jobem).

### 15.4 Autentizace, role, oprávnění

- **Uživatelé platformy:** session auth (Laravel), Argon2id, povinná min. délka 10 znaků + kontrola proti úniklým heslům (zxcvbn/haveibeenpwned k-anonymita), rate limit 5 pokusů/min, zámek účtu na 15 min po 10 pokusech, e-mail o novém přihlášení z neznámého zařízení.
- **2FA (TOTP):** volitelná pro tenanty, **povinná pro superadmin**; recovery kódy.
- **Role v tenantovi:** MVP jen `owner`; model ale obsahuje `permissions JSON` — permissions jsou definovány moduly v manifestu (`"permissions": ["products.view","products.edit","orders.manage"]`), takže role `staff` ve fázi 2 nevyžaduje refaktoring.
- **Superadmin:** oddělený guard + oddělená tabulka `platform_admins` (žádné sdílení s users — snížení dopadu úniku), IP allowlist volitelně, impersonace: podepsaný token 30 min, banner, každá akce v audit logu s příznakem `impersonated_by`.
- **Zákazníci e-shopů:** třetí guard, scoped per tenant (e-mail unikátní jen v rámci tenanta), session cookie vázaná na doménu tenanta.

### 15.5 Registrace modulu — mechanika

1. Deploy: composer/adresář `modules/*`; `php artisan modules:sync` načte manifesty, zvaliduje (JSON schema manifestu, semver závislostí), zapíše do `modules`.
2. Route registrace: modul deklaruje `routes/admin.php` (prefix `/admin/m/{module}` + názvová konvence `admin.{module}.*`), `routes/storefront.php`, `routes/api.php` (`/api/m/{module}`). Jádro je mountne jen pro tenanty s aktivním modulem (route middleware `module:products`).
3. Navigace adminu se skládá z `nav` sekcí manifestů aktivních modulů (řazení `order`).
4. Migrace modulů běží platformně (`modules:migrate`), per-tenant se nic nemigruje (sdílené tabulky) — `onActivate(tenant)` jen seeduje výchozí data.
5. **Kill switch:** `modules.enabled_globally=false` → routy i listenery modulu se přestanou registrovat okamžitě (cache registru 60 s), UI tenantům ukáže „modul dočasně nedostupný".

### 15.6 Cache strategie

- **Page cache storefrontu:** celé HTML pro anonymní GET, klíč `page:{tenant}:{path}:{qs-hash}`, TTL 10 min + eventová invalidace (product.updated → invalidace tagů `product:{id}`, `category:{id}`, `home`).
  - **Železné pravidlo: cachované HTML nesmí obsahovat žádný per-návštěvníkový obsah.** Sdílená page cache je klíčovaná jen podle tenanta a cesty — cokoli osobního v HTML by dostal i další anonymní návštěvník. Stejná třída chyby jako únik mezi tenanty (§12.1).
  - **Mini-košík v hlavičce se proto nerenderuje na serveru.** Do HTML jde prázdný placeholder; počet položek a částku dohydratuje ostrůvek jedním requestem `GET /api/kosik/souhrn` s `Cache-Control: private, no-store`. Díky tomu je HTML katalogu identické pro všechny a cachovatelné vždy.
  - **Zrušeno (2026-07-19):** původní návrh vypínal page cache cookie `has_cart`. To sice únik řešilo, ale obětovalo cache celého katalogu každému návštěvníkovi, který něco vložil do košíku — tedy přesně těm nejaktivnějším. Cíl TTFB < 200 ms (§8) by tím padl. Cookie `has_cart` se neimplementuje.
  - Košík, pokladna, děkovná stránka, návrat z brány a účet zákazníka se necachují **pravidlem routy**, ne cookie.
- **Fragment cache:** menu, patička (tag `theme:{tenant}`).
- **Data cache:** settings, registr modulů, tarify. Vše tagované `tenant:{id}` → deaktivace tenanta = flush jedním příkazem.

### 15.7 Plánované úlohy (scheduler)

| Kdy | Úloha |
|---|---|
| každou minutu | fronta due webhooků/retry, expirace platebních intentů |
| hodinově | přepočet usage limitů (storage), expirace košíků (>14 dní), doběh trialů → e-maily |
| denně 03:00 | billing běh (obnovy, dunning), purge expirovaných sessions, čištění starých jobs_log |
| denně 04:00 | tenanty v `pending_deletion` po lhůtě → purge job |
| týdně | report superadminovi (MRR, churn, chybovost), test obnovy zálohy (staging, měsíčně ručně ověřit) |

---

## 16. Moduly základního tarifu — rozpracování do hloubky

Formát: Obrazovky adminu → Storefront → Procesy krok za krokem → Validace → Doplnění datového modelu → AK navíc. (Navazuje na kap. 6 — neopakuji, prohlubuji.)

### 16.1 `products` — Produkty (detail)

**Obrazovky adminu:**
1. **Seznam produktů** — tabulka (obrázek, název, kód, cena, sklad, stav, kategorie), fulltext, filtry (stav, kategorie, výrobce, skladem/vyprodáno, bez obrázku, bez kategorie), řazení, stránkování 50, hromadný výběr → akce (aktivovat/skrýt/smazat/přesun kategorie/přecenění %), tlačítka Import/Export.
2. **Karta produktu** — záložky (záložky = admin slot, moduly mohou přidávat):
   - *Základní*: název (→ auto-slug s možností úpravy), stav, kategorie (multi, jedna primární), krátký popis (240 zn.), popis (WYSIWYG s omezeným HTML — whitelist tagů), výrobce, kód/SKU, EAN, hmotnost
   - *Ceny*: cena s DPH (primární vstup), bez DPH (dopočet live), sazba DPH (select z číselníku jádra), běžná cena, nákupní cena (jen role s právem `products.costs`)
   - *Varianty*: definice parametrů (název + hodnoty, drag&drop), tlačítko „Vygenerovat kombinace", tabulka variant (inline editace ceny/skladu/EAN/kódu), hromadné vyplnění sloupce
   - *Obrázky*: drag&drop multi-upload (limit 8 MB/soubor, jpg/png/webp), řazení, alt, hvězdička = hlavní; stav generování řezů (job)
   - *Sklad*: sledovat (toggle), množství (u variant per varianta), politika vyprodání, upozornění při poklesu pod X (e-mail tenantovi — jednoduchá verze)
   - *SEO*: title (počítadlo znaků), description, náhled snippetu, OG obrázek
3. **Import CSV** — wizard: upload → detekce oddělovače/kódování (UTF-8/Win-1250!) → mapování sloupců na pole (uložitelné šablony mapování) → dry-run (report: N ok, M chyb s čísly řádků) → potvrzení → job s progresem. Párování dle SKU (update) / bez SKU (insert). Podpora sloupců variant (řádek = varianta, seskupení dle kódu produktu).
4. **Export CSV** — výběr polí, filtr jako v seznamu, job → odkaz ke stažení (podepsaná URL, expirace 24 h).

**Storefront:** výpis kategorie (grid, stránkování, řazení cena/název/nejnovější; filtry výrobce + skladem v MVP, parametrové filtry fáze 2), detail produktu (galerie, výběr varianty — přepočet ceny/dostupnosti bez reloadu, množství, do košíku, popis, parametry, breadcrumbs, JSON-LD), vyhledávání (naše MVP: MySQL fulltext přes název+kód+krátký popis, našeptávač od 3 znaků).

**Validace (výběr):** cena ≥ 0; DPH sazba z číselníku; slug `[a-z0-9-]{1,190}` unikátní per tenant, kolize → suffix `-2`; EAN 8/13 číslic s checksum (jen warning); hmotnost 0–200 000 g; smazání produktu s existujícími objednávkami = soft delete (objednávky drží snapshot, ale FK zůstává validní).

**Doplnění modelu:** `products.deleted_at` (soft delete), `products.stock_alert_qty`, `import_profiles(tenant_id, name, mapping JSON)`.

**AK navíc:** import 5 000 řádků < 5 min; změna varianty na detailu nemění URL (query `?v=`); soft-deleted produkt vrací 410 se stránkou „produkt již není v nabídce" + odkaz na kategorii.

### 16.2 `categories` (detail)

- Admin: strom s drag&drop (přesun i vnoření), inline přejmenování, karta kategorie (název, slug, nadřazená, popis nad/pod výpisem, obrázek, viditelnost, SEO). Max hloubka 4 (UX rozhodnutí, technicky neomezeno).
- Přesun/přejmenování kategorie → automatický zápis do `redirects` (301) pro **kategorii**; URL produktů se díky plochému schématu nemění.
- Storefront URL schéma **(rozhodnuto 2026-07-19, odchylka od v1.1)**: kategorie `/kategorie/{slug}`, produkt **ploché `/produkt/{slug}`**. Původní návrh `/{category-slug}/{product-slug}` zamítnut — reorganizace katalogu by měnila URL všech produktů v podstromu, což generuje 301 řetězce a ztrátu link equity. Ploché URL je stabilní po celou životnost produktu. Canonical vždy `/produkt/{slug}`; breadcrumbs nesou kategorii. Slug zůstává unikátní per tenant, změna slugu zapíše 301 do `redirects`.
- AK: cyklus v rodičích nemožný (validace); smazání kategorie s produkty → dialog „přesunout produkty do…".

### 16.3 `checkout` (detail)

**Proces krok za krokem (3 kroky, každý = samostatná URL, stav v DB `carts`):**
1. `/kosik` — položky (obrázek, název+varianta, cena/ks, množství ±, mezisoučet, odstranit), pole slevový kód (skryté, aktivuje premium modul `coupons` přes UI slot), součet, „doprava od X Kč zdarma — zbývá Y" (motivační lišta), CTA Pokračovat.
2. `/pokladna/doprava` — radio seznam doprav (název, popis, cena; Zásilkovna → tlačítko „Vybrat výdejní místo" otevře widget, vybrané místo se zobrazí a uloží do carts.meta), pod tím platby filtrované maticí doprava×platba (změna dopravy přefiltruje platby). Ceny se přepočítávají serverem (žádná cenová logika v JS).
3. `/pokladna/udaje` — e-mail (kontrola formátu + návrh překlepů domén), telefon (+420 default), jméno/příjmení, adresa (našeptávač fáze 2), checkbox firma → IČO (ARES autofill přes náš proxy endpoint s cache), checkbox jiná doručovací adresa, poznámka, souhlasy, rekapitulace (položky, doprava, platba, DPH rozpis, celkem), tlačítko **„Objednat s povinností platby"** (zákonná formulace).
4. Server: transakce { validace skladu → `SequenceService.next('orders')` → insert order + items (snapshoty) → decrement skladu → outbox event `order.created` } → redirect: online platba → `PaymentProvider.initiate()` redirect na bránu; offline → `/dekujeme/{order-uuid}` (číslo objednávky, QR platba u převodu, instrukce).
5. Návrat z brány: `/platba/navrat` čte jen stav z DB (pravdu určuje webhook, ne redirect!) → „zaplaceno" / „čekáme na potvrzení" (polling 5 s, max 2 min).

**Validace/edge cases:** změna ceny produktu mezi vložením a checkoutem → banner „cena položky se změnila z X na Y" + přepočet; vyprodání během checkoutu → návrat do košíku s hláškou; expirace košíku 14 dní; idempotence: hidden token, unique index `(cart_id, checkout_token)` na orders.

**AK navíc:** celý checkout funkční bez JS (progressive enhancement, widget Zásilkovny výjimka — fallback select nejbližších míst dle PSČ fáze 2); Lighthouse accessibility ≥ 90.

### 16.4 `orders` (detail)

**Obrazovky:** seznam (badge stavů, rychlá změna stavu ze seznamu, ikona platby), detail — hlavička (číslo, datum, zdroj, stavy logistický+platební jako 2 nezávislé selecty), sloupce zákazník/adresy (editovatelné do stavu „odeslaná"), položky (editace množství/přidání položky do stavu „zpracovává se" → přepočet + poznámka v historii), platby (záznamy z modulu payments, tlačítko „označit zaplaceno ručně" s povinnou poznámkou), doprava (výdejní místo, fáze 2: číslo zásilky + tracking link), historie (kdo/kdy/co, systémové vs. ruční), interní poznámky, tlačítka: e-mail znovu, storno (dialog: důvod, vrátit sklad ano/ne, e-mail ano/ne), vytvořit doklad (→ modul docs).
- Ruční objednávka: zjednodušený formulář (zákazník dle e-mailu nebo nový, položky vyhledáváním, doprava/platba, bez online platby).
- **Stavový automat vynucen:** povolené přechody `nová→přijatá→zpracovává se→odeslaná→doručená`; storno z libovolného ne-koncového stavu; přeskočení stavů povoleno vpřed, ne vzad (krom admin override s poznámkou).

### 16.5 `shipping` + `payments` (doplnění)

- Nastavení dopravy v adminu: seznam metod (zapnuto, název, cena, zdarma od, váhy, pozice drag&drop), karta metody dle providera (osobní odběr: adresa+hodiny; Zásilkovna: API klíč widgetu, výchozí velikost zásilky), matice doprava×platba jako zaškrtávací tabulka.
- Platby: karta providera; u brány průvodce „Jak získat smlouvu s Comgate" (odkaz do KB) + testovací režim (sandbox credentials, testovací objednávka označená, nikdy nemíchat do statistik).
- `payments.settings` šifrované; zobrazení jen maskovaně (`****abcd`), změna vyžaduje re-zadání.
- Webhook endpoint per tenant: `https://{domain}/api/m/payments/webhook/{provider}` — ověření podpisu, mapování external_id→order, idempotence dle `(provider, external_id, status)`.

### 16.6 NOVÝ modul `docs` — Doklady tenanta (base!)

**Zdůvodnění:** bez faktur/prodejních dokladů nelze v ČR reálně prodávat; konkurence to má v základu. Oddělený modul (ne součást orders), protože pravidla dokladů jsou samostatná doména a premium rozšíření (ISDOC, Pohoda) na něj naváží.

**Funkce (base):**
- Automatické/ruční vystavení dokladu k objednávce: plátce DPH → faktura – daňový doklad; neplátce → doklad bez DPH náležitostí. Vlastní číselná řada (`SequenceService`, série `invoices`), konfigurovatelný formát.
- Náležitosti: dodavatel (z nastavení tenanta), odběratel, DUZP, datum vystavení/splatnosti, položky se sazbami, rekapitulace DPH per sazba, způsob platby, VS, QR platba.
- PDF generování jobem (šablona A4, logo tenanta, patičkový text), uložení do S3, odeslání e-mailem (volitelně automaticky při order.paid nebo při expedici — nastavení), odkaz v zákaznickém účtu.
- Opravný daňový doklad při stornu zaplacené objednávky (zjednodušeně: dobropis celé objednávky; částečné dobropisy fáze 2).
- Export dokladů: CSV se souhrnem DPH za období (podklad pro účetní) — base; ISDOC/Pohoda XML = premium `accounting`.
- **Datový model:** `documents(id, tenant_id, order_id, type ENUM(invoice,proforma,credit_note), number, series, issued_at, taxable_at, due_at, supplier JSON, customer JSON, items JSON, vat_summary JSON, total, pdf_path, sent_at)` — plný snapshot, doklad je immutable; oprava jen dobropisem.
- **AK:** doklad splňuje náležitosti §29 ZDPH (ověřit s účetní/právníkem); vystavený doklad nelze editovat ani smazat, jen dobropisovat; číselná řada bez děr.

### 16.7 `mailer`, `theme`, `customers`, `settings`, `billing` — doplnění

- `mailer`: seznam šablon s náhledem a testovacím odesláním na e-mail tenanta; proměnné dokumentovány u editoru; suppression list (bounce/complaint) per tenant i globální; DKIM/SPF naší odesílací domény, `List-Unsubscribe` u budoucích marketingových (base neposílá marketing).
- `theme`: editor barev s kontrolou kontrastu (WCAG AA warning); knihovna 10 přednastavených palet; správa souborů (obrázky do stránek) s limitem storage tarifu.
- `customers`: detail zákazníka ukazuje LTV (součet objednávek), tlačítko GDPR výmaz (dialog s vysvětlením anonymizace), export dat zákazníka (JSON — právo na přenositelnost).
- `settings`: nová sekce **Otevírací doba / kontakty** (patička), **Číselné řady** (formáty), **DPH číselník** je jádrový, ale tenant volí výchozí sazbu; sekce **Soukromí** (retence dat zákazníků, texty souhlasů).
- `billing`: v adminu tenanta sekce „Předplatné" — aktuální tarif, využití limitů (progress bary), historie faktur (PDF), změna karty, změna tarifu, zrušení (s výpovědní logikou: doběhne do konce období, pak lifecycle 6.0); nabídka premium modulů se zobrazuje zde (upsell katalog s popisy — naznačit, neaktivní „Již brzy").

---

## 17. Modul `licensing` — Licenční a digitální produkty (premium, fáze 2)

**Účel:** tenant (typicky vývojář — náš vlastní use-case: prodej WP pluginů) prodává digitální produkty. Zákazník po zaplacení obdrží **licenční klíč a soubory ke stažení**; produkt (např. WP plugin) se klíčem aktivuje proti API platformy a ověřuje si licenci i aktualizace. Inspirace: `masterix21/laravel-licensing` (Ed25519, offline ověření) + model Easy Digital Downloads Software Licensing.

### 17.1 Funkce

**Strana tenanta (admin):**
- Označení produktu jako „digitální/licenční" (checkbox na kartě produktu — modul přidává záložku *Licence* přes admin slot `product.edit.tabs`):
  - typ licence: časově omezená (N měsíců, s obnovou) / doživotní / doživotní s aktualizacemi na N měsíců
  - počet aktivací (seats): 1 / 3 / 5 / neomezeně — varianty produktu mohou mapovat na různé seats (Personal/Business/Agency)
  - grace period po expiraci (default 14 dní — plugin funguje, ale hlásí)
  - přiřazené soubory a verze (release management): upload ZIP, číslo verze (semver), changelog, minimální verze WP/PHP (metadata pro update API)
- Přehled licencí: klíč, zákazník, produkt, stav (active/expired/grace/revoked), aktivace (domény/instance, naposledy viděno), akce: prodloužit, revokovat (s důvodem), přegenerovat klíč, ruční vystavení licence (bez objednávky)
- Statistika: aktivní licence, expirace v příštích 30 dnech (podklad pro obnovy), verze v terénu

**Strana koncového zákazníka:**
- Po `order.paid`: e-mail s klíčem + odkazy ke stažení (podepsané URL, limit stažení konfigurovatelný); v zákaznickém účtu sekce „Moje licence" (klíče, stažení aktuální verze, správa aktivací — deaktivace staré domény)
- Obnova: obnovovací produkt/varianta; e-mail 30/7 dní před expirací s odkazem do košíku s předvyplněnou obnovou (napojení na klíč)

**Strana produktu (WP plugin) — veřejné Licensing API** na doméně tenantova e-shopu, prefix `/api/m/licensing/v1`:

| Endpoint | Metoda | Popis |
|---|---|---|
| `/activate` | POST | `{license_key, instance_id, domain, product_slug, meta{php,wp,version}}` → ověří klíč+seats → vytvoří aktivaci → vrací **podepsaný licenční token (Ed25519)** s claims (product, expires_at, seats, instance) pro offline ověření na straně pluginu |
| `/deactivate` | POST | uvolní seat |
| `/validate` | POST | periodická kontrola (doporučeno 1×/24 h s jitterem); aktualizuje `last_seen`, vrací čerstvý token; toleruje offline díky podpisu (plugin věří tokenu do `expires_at + grace`) |
| `/updates/{product_slug}` | GET | `?license_key&instance_id&version` → JSON kompatibilní s WP update mechanismem (`new_version`, `package` = podepsaná URL ZIPu s TTL 5 min, `requires`, `requires_php`, `sections.changelog`); bez platné licence vrací info o verzi, ale bez `package` |
| `/download/{release}` | GET | podepsaná URL, kontrola licence, počítadlo |

**Kryptografie:** platforma drží per-tenant Ed25519 keypair (privátní klíč šifrovaně v DB, rotace podporována — token nese `kid`); veřejný klíč si tenant zabuduje do pluginu. Plugin ověřuje podpis lokálně → funguje i při výpadku API (offline-first, přesně přístup laravel-licensing). Formát klíče licence: `XXXX-XXXX-XXXX-XXXX` (crockford base32, bez kolizí, per tenant unikátní).

### 17.2 Datový model

```sql
license_products(product_id PK/FK, tenant_id, license_type ENUM(subscription,lifetime,
                 lifetime_updates), duration_months NULL, updates_months NULL,
                 max_activations NULL, grace_days DEF 14)
releases(id, tenant_id, product_id, version, file_path, size, changelog,
         requires_wp, requires_php, published_at, is_public BOOL)
licenses(id, tenant_id, product_id, order_id NULL, customer_id, key UQ(tenant),
         status ENUM(active,expired,grace,revoked), issued_at, expires_at NULL,
         updates_until NULL, max_activations, revoke_reason NULL)
license_activations(id, license_id, instance_id, domain, meta JSON,
                    activated_at, last_seen_at, deactivated_at NULL)
license_events(id, license_id, type, meta JSON, created_at)  -- audit: activate, validate, revoke…
tenant_signing_keys(tenant_id, kid, public_key, private_key ENCRYPTED, active BOOL)
```

### 17.3 Procesy a integrace s ostatními moduly

- `order.paid` listener: pro každou licenční položku vygeneruje licenci (+ event `license.issued` → mailer šablona „Vaše licence"), refund/storno → `license.revoked` (konfigurovatelně).
- Checkout: digitální produkt bez fyzických položek → skrýt krok dopravy (doprava „elektronicky, 0 Kč"), nevyžadovat doručovací adresu; smíšený košík → doprava jen za fyzické položky.
- Docs: na dokladu položka jako služba/digitální plnění (DPH pozor: u prodeje spotřebitelům do EU jde o elektronicky poskytovanou službu → OSS režim — v MVP modulu omezit prodej na CZ, EU rozšíření s OSS podporou později; zapsat do rizik!).
- Limity/zneužití: rate limit aktivací (10/min/IP), detekce sdílení klíče (aktivace > seats → poslední odmítnuta s návodem na deaktivaci), honeypot na hádání klíčů (exponenciální backoff per IP).

### 17.4 AK modulu

- Nákup pluginu → e-mail s klíčem do 2 min; aktivace ve WP pluginu (referenční klientská knihovna PHP, kterou dodáme jako součást modulu — composer balíček `platforma/license-client`) na 1 request; update se ve WP nabídne do 24 h od publikace release; revokovaná licence přestane validovat okamžitě a stahovat balíčky okamžitě, plugin přestane po doběhu grace tokenu.

---

## 18. Premium moduly — náznak (rozpracujeme později)

| Modul | Jedna věta |
|---|---|
| `licensing` | viz kap. 17 |
| `coupons` | slevové kódy (%, Kč, doprava zdarma, omezení: platnost, min. košík, 1×/zákazník) — pozor na novelu o slevách |
| `b2b-pricing` | cenové hladiny, skupiny zákazníků, schvalování registrací, faktura se splatností (vzor Eshop-rychle Business) |
| `feeds` | Heureka, Zboží.cz, Google Merchant XML + měření konverzí |
| `accounting` | ISDOC, Pohoda XML export, párování plateb z bankovního API |
| `multilang` | jazykové mutace + měny (SK first) |
| `abandoned-cart` | e-mail sekvence na opuštěný košík (vyžaduje marketingový souhlas!) |
| `api-access` | veřejné REST API + webhooky pro tenanta (tokeny, scopes) — brána k budoucímu marketplace doplňků |
| `custom-email-domain` | odesílání z domény tenanta (SPF/DKIM průvodce) |
| `staff` | další uživatelé adminu s právy per modul |

Zpoplatnění: buď vyšší tarif „Premium" zahrnující balík modulů, nebo jednotlivé moduly à la carte — architektura umí obojí (`plan_modules` i budoucí `tenant_module_subscriptions`); obchodně rozhodnout před fází 2.
