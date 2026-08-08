# As-is: layout administrace (plná šířka, seskupené levé menu) — vlna 3.5

Datum: 2026-08-08 · Verze: **0.40.0** (minor otevírá vlnu 3.5) · Větev: `feature/vlna-35-admin-layout`

Plán: [`docs/superpowers/plans/2026-08-08-vlna-35-admin-layout.md`](../superpowers/plans/2026-08-08-vlna-35-admin-layout.md)

Samostatná spec neexistuje — zadání je v konverzaci z 2026-08-08 a shrnuté v plánu.

## Co vlna přinesla

Administrace nájemce běžela na `max-w-7xl` s vodorovným menu, které se s přibývajícími moduly rozrostlo na třináct nezařazených odkazů vedle sebe. Vlastník si vyžádal plnou šířku, menu vlevo rozdělené do kategorií, sbalitelné do ikon a na mobilu vysouvací.

- **Plná šířka** na všech obrazovkách administrace včetně modulových
- **Levé menu** rozdělené do čtyř kategorií, výchozí stav sbalený
- **Rail** (jen ikony) na desktopu, **drawer** pod `lg`
- **Nástěnka** — `/admin` poprvé má vlastní obrazovku
- Superadmin dostal totéž rozvržení, ale **nechal si tmavý panel**

## Mapa změn

30 souborů. Klíčové:

| Soubor | Změna |
|--------|-------|
| `app/Core/Modules/NavigationGroup.php` | **nový** enum — kategorie a jejich pořadí drží jádro |
| `app/Core/Modules/NavigationBuilder.php` | + `groupedForTenant()`; prázdná kategorie se nevrací |
| `app/Core/Modules/ManifestValidator.php` | `nav.*.group` validované proti enumu při `modules:sync` |
| `Modules/*/module.json` | 13 nav položek dostalo `group` a nové `order` |
| `app/Http/Middleware/HandleInertiaRequests.php` | + `tenant.navGroups` |
| `resources/js/Layouts/Partials/SideNav.vue` | **nová** komponenta — kontrolovaná, stav drží layout |
| `resources/js/Layouts/Partials/TopBar.vue` | **nová** — plná šířka, `variant` tenant/platform |
| `resources/js/composables/useSideNav.ts` | **nový** — otevřené sekce, rail, drawer, `localStorage` |
| `resources/js/Components/Ui/NavIcon.vue` | **nový** — mapa názvů na Lucide ikony |
| `resources/js/Layouts/{AdminLayout,PlatformLayout}.vue` | přepsané na sdílené části |
| `app/Http/Controllers/Tenant/AdminHomeController.php` | z redirectu na skutečnou nástěnku |
| `resources/js/Pages/Tenant/Dashboard.vue` | **nová** obrazovka |
| `app/Core/Orders/Contracts/OrderBook.php` | + `dashboardSummary()` |
| `resources/js/Pages/{Dashboard,Profile/Edit}.vue` | z Breeze layoutu na `AdminLayout` |
| `e2e/tests/admin-nav.spec.ts` | **nových 10 scénářů** |
| `tests/Feature/Modules/{NavigationGroupTest,NavIconCoverageTest}.php` | **nové** |

## Struktura menu

| Sekce | Položky |
|---|---|
| *(bez nadpisu)* | Nástěnka |
| **PRODUKTY** | Produkty, Import / export, Kategorie |
| **OBJEDNÁVKY** | Expedice, Objednávky, Doklady, Zákazníci |
| **MODULY** | Nastavení modulů, Slevy, Účetní export, Feedy |
| **NASTAVENÍ** | Doména, Vzhled, Homepage, Stránky, Doprava a platby |

Dole Profil a Odhlásit, oboje i v horním panelu, jak si vlastník vyžádal.

## Rozhodnutí

### Menu se dál staví z manifestů

Napsat seznam natvrdo do layoutu by znamenalo, že vypnutý modul nechá viset odkaz do 404 — přesně to, čemu se `NavigationBuilder` od začátku vyhýbá. Manifest nově říká jen `group`; **kategorie a jejich pořadí drží jádro**, protože menu řazené podle toho, který modul se nainstaloval dřív, by se přeskládalo při každém zapnutí.

Vadná skupina padá při `modules:sync`, ne až při requestu: překlep by položku zařadil do záložní sekce, kde vypadá **zařazeně, ne špatně zařazeně** — a nikdo nehledá položku, která je vidět, jen jinde.

### Jádrové položky přidává layout, ne builder

Doména, Vzhled a Nastavení modulů nepatří žádnému modulu. `NavigationBuilder` je nezná a nemá znát — jinak by musel vědět o routách, které s modulovým systémem nesouvisejí.

### Sbalený rail nesmí zmizet odečítači

Ve sbaleném režimu se nadpisy sekcí nevykreslují, takže **všechny položky jsou vidět** — schovat je za sekci, kterou nelze otevřít, by polovinu administrace znepřístupnilo. Každá položka má `aria-label` s názvem sekce.

### Zavřený drawer je `invisible`, ne jen posunutý

Nález E2E sady. Menu odsunuté mimo obrazovku přes `-translate-x-full` **zůstává v pořadí tabulátoru a odečítač ho čte** — uživatel klávesnice na telefonu by procházel menu, které nevidí. `visibility` je animovatelná, takže vysunutí zůstalo plynulé.

### `localStorage` je volitelné

Čtení i zápis v `try/catch`. Administrace, která se neotevře v anonymním okně, je horší než ta, která zapomene rozbalené sekce.

Sbalení na rail je navíc vázané na `matchMedia('(min-width: 1024px)')`: kdo si menu sbalí na notebooku a otevře administraci na telefonu, nesmí najít menu, které nejde přečíst.

### `/admin` má konečně vlastní obrazovku

Dosud přesměrovával na první položku menu, takže vlastník přistál na výpisu produktů bez ponětí, jak si e-shop stojí. Nástěnka ukazuje čtyři čísla (čeká na vyřízení, nezaplaceno, objednávky a tržba za 30 dní), využití tarifu a rychlé odkazy.

`OrderBook::dashboardSummary()` počítá čtyři agregáty jedním dotazem — první obrazovka administrace nesmí být ta nejdražší.

### `Route::has()` nestačí

Odhaleno testem. `ModuleRouteRegistrar` montuje routy **všech nasazených** modulů bez ohledu na to, kdo co zapnul, takže `Route::has('admin.orders.index')` je pravda i pro e-shop, který objednávky nikdy nezapnul — a odkaz by vedl na 404 z modulové brány. Nástěnka se proto ptá registru.

### Superadmin: stejné rozvržení, jiná barva

Vlastník chtěl sjednotit obojí. Sjednoceno je **rozvržení**; tmavý panel superadminu zůstal, protože záměna dvou administrací je způsob, jak pozastavit špatný e-shop. Superadmin nemá sekce — šest obrazovek je nepotřebuje.

## Testy

- **E2E: 32 (bylo 22)**, z toho 10 nových nad administrací. Tři běhy zelené.
- **PHPUnit: `tests/Feature/Modules` 1071 zelených**, `Tenant`/`Platform`/`Onboarding`/`Auth`/`Core` 402.
- Nové: `NavigationGroupTest` (9), `NavIconCoverageTest` (1), přepsaný `AdminHomeTest` (5).

## Nálezy

- **Zavřený mobilní drawer zůstával v pořadí tabulátoru** — skutečná chyba přístupnosti, opravená `invisible`.
- **Nedostatečný kontrast** v „Moje e-shopy" (gray-400 na 12 px = 2,53 : 1). Zděděná chyba, kterou axe odhalil až tím, že stránka přešla pod nový layout a do auditu.
- **`Route::has()` nechrání před nezapnutým modulem** — viz výše.
- **`groups()` je v PHPUnit `final`** a nešlo ho použít jako pomocnou metodu v testu. Stejná past jako `run()` ve vlně 2.8.
- **Pořadí položek** po prvním nasazení neodpovídalo zadání: hodnoty `order` byly z plochého menu, kde jedna globální posloupnost musela proplétat všechny moduly.

## Technický dluh

- **Přihlašovací formulář je pořád Breeze default v angličtině** („Email", „Password", „Log in", „Remember me"). Ve vlně 3.2 se počeštila jen registrace. Nesouvisí s layoutem, ale je to první obrazovka, kterou nájemce vidí.
- **Menu se všemi sekcemi otevřenými přeteče** a roluje. Výchozí stav je sbalený, takže v běžném provozu nenastane, ale na malém displeji s otevřenými čtyřmi sekcemi se roluje.
- **Nástěnka neukazuje graf ani srovnání s minulým obdobím** — čtyři čísla a odkazy.
- **`AuthenticatedLayout.vue` zůstal v repu** bez jediného uživatele; smazat, až bude jisté, že ho nikdo nepotřebuje.
- **Superadmin nemá `Profil`** v menu — platformní administrátor svůj profil nemá kde upravit (předchází této vlně).

## Pre-deploy checklist

- [ ] `php artisan modules:sync` (manifesty dostaly `group` a nové `order`), pak `npm run build`
