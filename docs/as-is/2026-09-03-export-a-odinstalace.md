# Export dat nájemce a odinstalace modulu — as-is

Vlna **0.48**. Plán: [`docs/superpowers/plans/2026-09-02-uzavreni-technickych-mezer.md`](../superpowers/plans/2026-09-02-uzavreni-technickych-mezer.md).

Zadání znělo „zavřít tři mezery ze `STATUS.md`". Jedna se zavřela (export), druhá se ukázala jako
už uzavřená a jen špatně zapsaná (fulltext), třetí se zavřela v užším tvaru, než plán předpokládal
(odinstalace).

## Mapa změn

| Oblast | Soubory |
|---|---|
| Export — jádro | `app/Core/Export/{TenantTableRegistry,TenantDataExporter,ExportRequests,ExportResult}.php`, `Contracts/TenantExporter.php`, `Exceptions/` |
| Export — spuštění | `app/Jobs/ExportTenantData.php`, `app/Console/Commands/ExportTenant.php`, `app/Models/JobLogEntry.php` |
| Export — obrazovky | `app/Http/Controllers/Tenant/DataExportController.php`, `resources/js/Pages/Tenant/Settings/DataExport.vue`, sekce v `Platform/Tenants/Show.vue` |
| Úložiště | `app/Core/Storage/FileStorage.php` (`tenantFiles`, `readStream`, `putPrivateUnmetered`, `ARTEFACT_PREFIXES`) |
| Odinstalace | `app/Core/Modules/{UninstallModule}.php`, `Contracts/ModuleUninstall.php`, `ModuleRegistry::uninstall()`, `Modules/{Discounts,Feeds}/Lifecycle.php` |
| Bezpečnost | `app/Core/Html/UrlGuard.php`, `HtmlSanitizer`, `Modules/Storefront/Support/BlockUrl.php`, `RichTextEditor.vue` |

## Plnění spec

### §4.2 pojistka 4 — per-tenant export

Hotovo. Archiv je ZIP: `manifest.json`, `data/<tabulka>.json`, `files/public/…`, `files/private/…`.

Co je tenantovo, se odvozuje **ze schématu** — tabulka se sloupcem `tenant_id`, což je definice
pojistky 3. Trait `BelongsToTenant` by našel 36 modelů, schéma najde 43 tabulek; rozdíl jsou
`shop_settings` a `tenant_theme` (trait záměrně nemají), pivoty `product_category` a
`shipping_method_payment_method` (nemají model) a kernelové `settings`, `sequences`, `domains`.

Manifest jmenuje, co v archivu **není**: `customer_tokens` (živé přihlašovací údaje) a hashe hesel
v `customers`. Archiv, který něco vynechá a neřekne to, je horší než žádný.

Spouští ho `tenant:export {id|doména} [--sync] [--tables=]`, obrazovka nájemce
`/admin/nastaveni/export` a tlačítko na detailu nájemce v superadminu. Souběžné běhy hlídá zámek
v `ExportRequests`.

### §4.4 — `jobs_log`

Tabulka existovala od vlny 0.x a nikdo do ní nepsal. Export je její první zapisovatel; schéma
sedělo beze změny.

### §5.2 — odinstalace modulu

Hotovo **v užším tvaru, než plán předpokládal**, a ten rozdíl je věcný, ne úspora práce.

Plán počítal s odinstalací jako s obecnou schopností. Mapa cizích klíčů ale ukázala, že
`documents.order_id` míří do `orders` a doklady jsou zákonná evidence s desetiletou archivační
povinností — odinstalace `orders` tedy není „nebezpečná", je nemožná. Rozhraní `ModuleUninstall` je
proto **opt-in**: modul, který ho nedeklaruje, odinstalovat nejde. První dva jsou `discounts` a
`feeds`.

Dobrá zpráva z téže mapy: `order_items` **nemá** cizí klíč na `products` — objednávky nesou snímek.
Katalog by tedy šel smazat, aniž by se rozpadly objednávky.

## Testy

| Sada | Počet | Co hlídá |
|---|---|---|
| `tests/Feature/Export/` | 23 | izolace exportu, discovery tabulek, zámek, job, redakce |
| `tests/Feature/Tenant/DataExportControllerTest.php` | 11 | role, cizí tenant, stažení za přihlášením |
| `tests/Feature/Modules/ModuleUninstallTest.php` | 11 | core, zákonná evidence, běžící modul, cizí tenant, záloha |
| `tests/Feature/Platform/ModuleManagementTest.php` | +3 | HTTP vrstva odinstalace |
| `tests/Unit/Html/`, `tests/Unit/Storefront/` | +6 | open redirect |

Nejdůležitější je `TenantExportIsolationTest`: export je **jediné místo v kódu s ručně psaným
`where tenant_id`** (pivoty nemají scope k zdědění), takže chyba tam neuniká po řádku, ale
databází.

## Odchylky od plánu

1. **Discovery podle schématu, ne podle traitu.** Plán říkal odvodit z `BelongsToTenant`. Vynechalo
   by to sedm tabulek, aniž by to archiv přiznal.
2. **Stažení za přihlášením, ne za podepsanou URL.** Platformní routa `storage.private` nese jen
   `signed`, tedy žádnou autentizaci. U faktury přijatelné, u archivu se všemi zákazníky ne.
3. **Odinstalace je opt-in per modul.** Viz výše — plán tuhle hranici neznal.
4. **Etapa B (fulltext) zrušena.** Rozhodnutí ji zamítalo už od 2026-08-05 a `STATUS.md` to
   popíral. Detail níže.

## Co se nepovedlo

**Etapa B se udělala zbytečně.** `docs/decisions/02` fulltext zamítá od 2026-08-05 po měření
(3,2 ms na base, 31 ms na stropu premium) a jmenuje i `innodb_ft_min_token_size`, na který přepis
narazil znovu. Práce vznikla ze `STATUS.md`, kde fulltext stál jako otevřený dluh se slovy
„Přepis = vlna 3.2". Poučení není „číst pozorněji", ale **`STATUS.md` nesmí nést dluh, který
rozhodnutí uzavřelo** — patří tam odkaz, ne konkurenční tvrzení. Opraveno.

Přepis přesto přinesl jedno nové zjištění, které je zapsané jako dodatek: **InnoDB neaktualizuje
FULLTEXT index do commitu**, takže `MATCH` nevidí řádek vložený v transakci, zatímco `LIKE` ano.
Celá sada jede na `RefreshDatabase`, takže cena fulltextu není „přepsat dotaz", ale přepsat
testovací strategii.

**Dvě chyby v exportu odhalily až testy**, ne review: `exports/` leželo v tenantově prefixu, takže
každý export zabalil ten předchozí (5,9 MB → 12 MB), a týž prefix se počítal do kvóty, přestože
zápis limit záměrně obchází.

**Testy psaly reálné archivy na disk** — 88 MB za odpoledne a běh 8 minut místo 23 s.

## Technický dluh

- Odinstalaci má zatím jen `discounts` a `feeds`. Další modul si ji přidá deklarací
  `ModuleUninstall`; u modulů se zákonnou evidencí to je **záměrně** nemožné.
- Export běží synchronně uvnitř jednoho jobu bez průběžného `progress`. Sloupec v `jobs_log`
  existuje, plní se jen 0 → 1 → 100.
- Retence archivů není řešená — starý export leží na disku, dokud ho někdo nesmaže.
- `storage/app/tenant-private` obsahuje ~1 GB dat 660 testovacích tenantů nahromaděných za celý
  vývoj. Není to výstup této vlny, ale stojí za úklid.

## Pre-deploy checklist

- [ ] Žádná migrace. `npm run build` kvůli obrazovce exportu a tlačítku odinstalace.
- [ ] Ověřit, že fronta běží — export bez workera zůstane ve stavu `pending`.
- [ ] Vyzkoušet `tenant:export <doména> --sync` na demu a otevřít archiv.
- [ ] Zkontrolovat volné místo: archiv velkého e-shopu je řádově stovky MB.
