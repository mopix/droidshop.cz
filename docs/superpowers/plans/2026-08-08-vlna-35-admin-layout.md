# Vlna 3.5 — layout administrace (plná šířka, seskupené levé menu) — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nájemce dostane administraci na celou šířku obrazovky se stálým levým menu, které je rozdělené do kategorií, sbaluje se do ikon a na mobilu vyjíždí zleva.

**Architecture:** Menu se dál skládá z manifestů modulů — přibývá jen `group` v `nav`, takže modul říká, kam patří, a jádro definuje pořadí a názvy skupin. Layout je nová sdílená komponenta, kterou používá administrace nájemce i superadmin; obě si drží svou barvu horního panelu.

**Tech Stack:** Vue 3 + Inertia, Tailwind, `lucide-vue-next` (schváleno vlastníkem 2026-08-08).

**Spec:** není samostatná — zadání je v konverzaci z 2026-08-08, shrnuté níže v „Co si vlastník vyžádal".

## Co si vlastník vyžádal

1. Administrace přes **celou šířku** (dnes `max-w-7xl`), na všech obrazovkách včetně modulových.
2. Horní panel zůstává roztažený přes celou šířku i po vstupu do modulu.
3. Levé menu **úplně vlevo**, rozdělené do kategorií. Nadpis kategorie je velkými písmeny a **není odkaz** — kliknutí rozbalí položky pod ním. Menu je ve výchozím stavu sbalené.
4. Responzivní: na tabletu a mobilu **hamburger** a vysunutí zleva; na užším desktopu tlačítko na **zúžení menu na ikony**.
5. Ke každé položce ikona, všechny z jedné free sady.
6. **Profil a Odhlásit** zůstávají v horním panelu a přibývají i dole v levém menu.
7. Prostřední část co nejvíc místa; na tabletu a mobilu vše responzivní.

## Struktura menu

| Skupina | Položky | Odkud |
|---|---|---|
| *(bez skupiny)* | Nástěnka | jádro `dashboard` |
| **PRODUKTY** | Produkty, Import / export, Kategorie | `products`, `products`, `categories` |
| **OBJEDNÁVKY** | Expedice, Objednávky, Doklady, Zákazníci | `packeta`, `orders`, `docs`, `customers` |
| **MODULY** | Nastavení modulů, Slevy, Účetní export, Feedy | jádro, `discounts`, `accounting`, `feeds` |
| **NASTAVENÍ** | Doména, Vzhled, Homepage, Stránky, Doprava a platby | jádro, jádro, `storefront`, `pages`, `shipping` |

Všech třináct modulových položek už v manifestech existuje — přibývá jim jen `group`. Fakturace a Předplatné zůstávají dostupné z banneru a z profilu; do menu je vlastník nezařadil.

## Global Constraints

- **Jedna nová závislost** (`lucide-vue-next`) — schváleno. Žádná další.
- **Grafický jazyk se nemění.** Vlastník výslovně chce jen jiné rozvržení; barvy, typografie a tvary komponent zůstávají.
- **Menu se dál staví z manifestů.** Natvrdo napsaný seznam v layoutu by znamenal, že vypnutý modul nechá viset odkaz do 404 — přesně to, čemu se `NavigationBuilder` od vlny 0.x vyhýbá.
- **WCAG 2.2 AA.** Rozbalovací nadpis je `<button>` s `aria-expanded` a `aria-controls`, ne `<div>` s klikem; hamburger má `aria-expanded`; vysunuté menu na mobilu má focus trap a zavírá se Escapem; sbalené menu na ikony si nechává přístupný název (`aria-label`, ne jen `title`). Sada axe z vlny 3.4 musí zůstat zelená.
- **`npm run e2e` musí projít.** Scénáře klikají na odkazy administrace jen okrajově, ale axe audity běží nad storefrontem — a `npm run build` je součást obojího.

---

### Task 1: `group` v manifestu a v `NavigationBuilder`

**Files:**
- Modify: `app/Core/Modules/NavigationBuilder.php`
- Modify: `app/Core/Modules/Manifest.php` (pokud `nav` validuje `ManifestValidator`, doplnit i tam)
- Modify: `Modules/*/module.json` (13 nav položek dostane `group`)
- Test: `tests/Feature/Modules/NavigationGroupTest.php`

**Interfaces:**
- Produces: `NavigationBuilder::groupedForTenant(Tenant): Collection` — seznam skupin v pevném pořadí jádra, každá s položkami setříděnými podle `order`
- Produces: klíče skupin `products|orders|modules|settings`; položka bez `group` spadne do `modules` (viditelně, ne potichu)

- [ ] **Step 1: Napiš padající testy**

1. Modul s `group: "products"` se objeví ve skupině PRODUKTY.
2. Skupiny mají **pevné pořadí** dané jádrem, ne pořadím modulů na disku.
3. Prázdná skupina se nevrací vůbec — nájemce bez modulu `discounts` ani `accounting` ani `feeds` nesmí vidět prázdné MODULY (jádrové „Nastavení modulů" ji ale drží neprázdnou).
4. Položka bez `group` skončí v MODULY a **nezmizí**.
5. Vypnutý modul nemá v menu nic.
6. Neznámý klíč skupiny shodí `modules:sync`, ne až request — stejná úvaha jako u vadného `settings_schema` (rozhodnutí 2026-07-30).

- [ ] **Step 2: Implementuj.** Skupiny (klíč, český název, pořadí) jsou v jádře; manifest zná jen klíč.
- [ ] **Step 3:** `php artisan modules:sync` + `php artisan test tests/Feature/Modules --compact`
- [ ] **Step 4: Commit** — `feat(modules): let a manifest say which admin menu group its entry belongs to`

---

### Task 2: Ikony

**Files:**
- Modify: `package.json`
- Create: `resources/js/Components/Ui/NavIcon.vue`
- Test: `tests/Feature/Modules/NavigationGroupTest.php` (rozšířit)

- [ ] **Step 1:** `npm install lucide-vue-next`
- [ ] **Step 2: `NavIcon.vue`** — mapa `název → komponenta` nad ikonami, které manifesty skutečně používají. Importovat po jedné, ne celou sadu: `import * as icons` by do bundle přitáhl 1500 ikon.
- [ ] **Step 3:** Neznámý název ikony vykreslí **výchozí ikonu**, ne prázdno a ne výjimku — chybějící ikona nesmí rozbít celé menu.
- [ ] **Step 4: Test**, že každý název v manifestech má v mapě záznam. Jinak se na to přijde až tím, že v menu chybí ikona.
- [ ] **Step 5: Commit** — `feat(admin): add Lucide icons for the navigation`

---

### Task 3: Sdílený layout

**Files:**
- Create: `resources/js/Layouts/Partials/SideNav.vue`
- Create: `resources/js/Layouts/Partials/TopBar.vue`
- Create: `resources/js/composables/useSideNav.ts`
- Modify: `resources/js/Layouts/AdminLayout.vue`
- Modify: `resources/js/Layouts/PlatformLayout.vue`

**Chování (`useSideNav.ts`):**

| Šířka | Menu |
|---|---|
| `lg` a víc | stálé vlevo, tlačítko na zúžení na ikony |
| pod `lg` | skryté, hamburger v horním panelu, vysouvá se zleva přes overlay |

- Skupina s **aktivní stránkou** je otevřená vždy, i po tvrdém načtení.
- Co uživatel rozbalí navíc, přežije přechod na jinou stránku (`localStorage`).
- Zúžení na ikony taky v `localStorage`.
- `localStorage` je **volitelné**: v privátním okně nebo se zakázaným úložištěm menu funguje dál, jen si nic nepamatuje. Čtení i zápis v `try/catch` — administrace nesmí spadnout kvůli nastavení prohlížeče.

- [ ] **Step 1: `SideNav.vue`** — skupiny jako `<button aria-expanded aria-controls>` + `<ul :id>`; „Nástěnka" nad skupinami bez nadpisu; dole Profil a Odhlásit. Ve sbaleném (ikonovém) režimu se nadpisy skupin nevykreslují jako text, ale skupiny zůstávají oddělené a položky mají `aria-label`.
- [ ] **Step 2: `TopBar.vue`** — plná šířka, název e-shopu, hamburger pod `lg`, Profil a Odhlásit. Bere `variant` (`tenant` světlý / `platform` tmavý), aby si obě administrace nechaly svou barvu — vlastník chce sjednotit **rozvržení**, ne zrušit rozlišení, které brání záměně.
- [ ] **Step 3: `AdminLayout.vue`** — `max-w-7xl` pryč, obsah `flex-1 min-w-0`, bannery (impersonace, fakturace, trial) zůstávají nad vším v plné šířce.
- [ ] **Step 4: `PlatformLayout.vue`** — týž layout, `variant="platform"`, položky E-shopy / Moduly / Tarify bez skupin (šest obrazovek skupiny nepotřebuje).
- [ ] **Step 5:** `npm run build`
- [ ] **Step 6: Commit** — `feat(admin): full-width layout with a grouped, collapsible side navigation`

---

### Task 4: Přístupnost a responzivita

**Files:**
- Modify: `e2e/tests/accessibility.spec.ts`
- Create: `e2e/tests/admin-nav.spec.ts`

- [ ] **Step 1: E2E scénáře**

1. Skupina se rozbalí kliknutím na nadpis a `aria-expanded` se změní.
2. Skupina s aktivní stránkou je po načtení otevřená.
3. Rozbalení přežije přechod na jinou stránku.
4. Pod `lg` je menu skryté a hamburger ho vysune; Escape ho zavře a **vrátí focus** na hamburger.
5. Sbalení na ikony schová texty, ale položky zůstanou dosažitelné klávesnicí a mají přístupný název.
6. Menu neobsahuje odkaz na modul, který e-shop neběží.

- [ ] **Step 2: Axe** nad nástěnkou, výpisem produktů a otevřeným mobilním menu. Práh `critical`/`serious` jako v 3.4.
- [ ] **Step 3:** `npm run e2e`
- [ ] **Step 4: Commit** — `test(e2e): cover the admin navigation and its accessibility`

---

### Task 5: Uzavření

- [ ] **Step 1:** PHPUnit po adresářích + `npm run e2e` třikrát (stabilita).
- [ ] **Step 2: Ruční ověření** na demu — poučení z 2.9: proklikat nástěnku, jeden modul, mobilní šířku a sbalené menu. Zelené testy neznamenají použitelné menu.
- [ ] **Step 3:** `docs/as-is/`, `STATUS.md`, rozhodnutí do CLAUDE.md.
- [ ] **Step 4:** `VERSION` → `0.40.0`, `CHANGELOG.md`, merge, push.

---

## Rizika

| Riziko | Dopad | Mitigace |
|---|---|---|
| Sbalené menu skryje texty i asistivním technologiím | Administrace nepoužitelná pro odečítač | `aria-label` na položkách, E2E scénář 5 |
| Mobilní menu bez focus trapu | Klávesnice se ztratí za overlayem | Scénář 4 včetně Escapu a návratu focusu |
| `localStorage` nedostupné | Bílá obrazovka administrace | Čtení i zápis v `try/catch`, menu funguje bez paměti |
| Prázdná skupina po vypnutí modulů | Nadpis, pod kterým nic není | Prázdné skupiny se nevracejí (Task 1, test 3) |
| Plná šířka rozbije široké tabulky | Vodorovné rolování celé stránky | Tabulky už mají vlastní `overflow-x`; ověřit na objednávkách a produktech při ručním průchodu |
| Superadmin ztratí odlišení od administrace nájemce | Záměna dvou administrací | `variant` drží tmavý panel superadminu |
