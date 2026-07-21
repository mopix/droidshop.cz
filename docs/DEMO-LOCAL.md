# Lokální demo e-shop (náhled)

Rychlé rozjetí funkčního e-shopu pro proklikání storefrontu, adminu nájemce a superadminu. **Jen pro dev**, ne produkce.

## Seeder

`Database\Seeders\DemoShopSeeder` (idempotentní) založí:

- tenanta **„Demo obchod"** na doméně **`obchod.droidshop`** (tarif Základní, moduly zapnuté),
- 4 produkty, dopravu (kurýr / osobní odběr), platby **dobírka / bankovní převod (QR) / Platební karta (Comgate, test mód)**,
- uživatele:
  - **nájemce (owner):** `admin@demo.cz` / `password`
  - **superadmin:** `super@droidshop.cz` / `password` (projde 2FA setupem)

Comgate má fiktivní test credentials — reálná platba neproběhne, ale celý tok (redirect na bránu, návrat, admin) je vidět.

## Spuštění

```bash
php artisan migrate
php artisan modules:sync
php artisan db:seed --class=DemoShopSeeder --force
npm run build
php artisan serve --port=8010 --no-reload
```

`/etc/hosts`:

```
127.0.0.1 obchod.droidshop droidshop admin.droidshop
```

Adresy:

- **Storefront:** http://obchod.droidshop:8010
- **Admin nájemce:** http://obchod.droidshop:8010/login
- **Superadmin:** http://droidshop:8010/superadmin/login

## Známé pasti (a řešení)

### 1. Rozbitá cache serializace na dev mašině

Na některém dev PHP prostředí vrací Laravel cache (file i redis) `__PHP_Incomplete_Class` pro **jakýkoli objekt**, zatímco raw `serialize/unserialize` funguje. Láme `ModuleRegistry` → aplikace nejede. **Není to bug kódu** (ověřeno: žádný serializer v configu, žádný igbinary/msgpack/xdebug).

**Obcházka** — přepni cache na in-memory `array` (neserializuje) inline:

```bash
CACHE_STORE=array SESSION_DRIVER=file QUEUE_CONNECTION=sync php artisan serve --port=8010 --no-reload
# a stejně u db:seed
```

Skutečná oprava = spravit PHP env (restart OPcache/php-fpm, `rm bootstrap/cache/*.php`, případně reinstal PHP). **Bez opravy nespoléhat na redis cache / page cache v produkci.**

### 2. Rezervované subdomény

`config/tenancy.php → reserved_subdomains` (`www, admin, api, app, demo, test, dev, blog, docs, …`) se v `DomainTenantFinder` berou jako **platform**, ne tenant. Proto demo běží na `obchod.droidshop`, ne `demo.droidshop`. Tenant vždy na nerezervované subdoméně.

### 3. Chrome nenačte `.droidshop`

- **Secure DNS (DoH)** obchází `/etc/hosts` → `ERR_NAME_NOT_RESOLVED`. Vypni v `chrome://settings/security` → „Používat zabezpečený systém DNS" OFF.
- **HSTS / HTTPS-First** forcuje https na http-only server → `ERR_CONNECTION_CLOSED`. Vypni `chrome://settings/security` → „Vždy používat zabezpečená připojení" OFF, a smaž HSTS: `chrome://net-internals/#hsts` → „Delete domain security policies" → `droidshop` (i `obchod.droidshop`). Alternativa: anonymní okno.

Ověření, že resolvuje systém (ne Chrome): `ping obchod.droidshop` → `127.0.0.1`.

### 4. Assety

Assety se generují z hostu requestu **včetně portu** — reálný prohlížeč posílá `Host: obchod.droidshop:8010`, takže se načtou správně. **Nenastavuj `ASSET_URL`** na konkrétní host, rozbil bys tím assety druhého hostu (platform vs. tenant).
