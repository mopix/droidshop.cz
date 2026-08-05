# Vlna 3.2 — právní minimum platformy — zadání

Datum: 2026-08-05 · Stav: **návrh** · Fáze 2

Navazuje na: [`docs/specs/2026-07-17-eshop-platforma-specifikace.md`](../../specs/2026-07-17-eshop-platforma-specifikace.md) kap. 11 (VOP, odpovědnost nájemce)

## Proč

Platforma je funkčně hotová a chybí jí věci mimo kód. `docs/legal/` je prázdné. Bez nich nelze spustit:

- **Nájemce s námi uzavírá smlouvu**, ale žádné VOP neexistují — smluvní vztah nemá obsah a nemáme čím ohraničit odpovědnost.
- **Zpracováváme osobní údaje koncových zákazníků nájemce**, tedy jsme podle GDPR **zpracovatel** a nájemce **správce**. Čl. 28 GDPR vyžaduje písemnou zpracovatelskou smlouvu; bez ní je zpracování protiprávní pro obě strany.
- **Zpracováváme osobní údaje samotných nájemců** (registrace, fakturace), kde jsme naopak správce a musíme plnit informační povinnost podle čl. 13.
- Registrace nájemce dnes **nezaznamenává žádný souhlas** — neexistuje důkaz, že nájemce VOP viděl.
- Nový e-shop dostane tři **prázdné nepublikované stránky** (`obchodni-podminky`, `ochrana-osobnich-udaju`, `kontakt`) a žádné vodítko, co do nich napsat. Nájemce, který je nevyplní, provozuje e-shop bez VOP a bez informační povinnosti vůči svým zákazníkům.

## Rozsah

### 1. Právní texty jako dokumenty (`docs/legal/`)

Čtyři drafty v Markdownu, zdrojová pravda pro renderované stránky:

| Soubor | Co je | Kdo jsme v něm |
|---|---|---|
| `vseobecne-obchodni-podminky.md` | VOP platformy vůči nájemci | poskytovatel služby |
| `zasady-zpracovani-osobnich-udaju.md` | informační povinnost vůči nájemci (čl. 13) | **správce** |
| `zpracovatelska-smlouva.md` | DPA podle čl. 28 GDPR | **zpracovatel** |
| `zasady-cookies.md` | cookies platformy (ne e-shopu nájemce) | správce |

Provozovatel je **OSVČ Miroslav Opletal**; formulace tedy pro fyzickou osobu podnikající, zápis v živnostenském rejstříku, bez obchodního rejstříku.

**Bez právní revize.** Formulace se drží konzervativně — raději širší povinnosti nám a užší omezení nájemci, aby draft nebyl horší než žádný text. Místa, kde je právní rozhodnutí nutné (limitace náhrady škody, výpovědní doba, odpovědnost za výpadek, sankce), nesou v textu viditelný marker `> **K PRÁVNÍ REVIZI:**`.

### 2. Renderované stránky platformy

Cesty pod prefixem `/pravni/`:

- `/pravni/obchodni-podminky`
- `/pravni/ochrana-osobnich-udaju`
- `/pravni/zpracovani-udaju`
- `/pravni/cookies`

**Blade SSR**, ne Inertia: musí být dostupné bez přihlášení, bez JS a indexovatelné.

Prefix je nutný, ne kosmetický — viz sekce Omezení.

### 3. Souhlas nájemce při registraci

- Povinný checkbox s odkazy na VOP a zásady zpracování údajů.
- Server-side validace (`accepted`), ne jen atribut `required` v HTML.
- Zaznamenat **kdy** a **jakou verzi** nájemce odsouhlasil: `users.terms_accepted_at` + `users.terms_version`. Souhlas bez data a verze není důkaz.
- Registrační formulář je dnes v angličtině (Breeze default) — v rámci vlny počeštit.

### 4. Vzorové stránky nájemce

`Modules\Pages\Lifecycle` už tři stránky seeduje, jen prázdné. Vlna je naplní **šablonou s viditelnými placeholdery** (`[DOPLŇTE …]`):

- `obchodni-podminky` — VOP e-shopu vůči jeho zákazníkům (odstoupení do 14 dnů, reklamace, doprava, platby)
- `ochrana-osobnich-udaju` — informační povinnost nájemce vůči jeho zákazníkům
- `kontakt` — identifikační a kontaktní údaje

Zůstávají **nepublikované**. V administraci stránek stojí upozornění, že za obsah odpovídá nájemce a že šablona není právní radou.

### 5. Odkazy

- Patička storefrontu odkazuje na publikované stránky nájemce.
- Souhlas v pokladně (existuje) dostane odkazy na VOP a zásady nájemce.

## Akceptační kritéria

1. `/pravni/obchodni-podminky` odpovídá 200 na platform hostu, bez přihlášení, bez JS; obsahuje identifikační údaje z `config('billing.company')`.
2. Tytéž cesty na hostu nájemce odpovídají 404 a **nezastiňují** žádnou stránku nájemce.
3. Nájemce se slugem `cookies`, `obchodni-podminky` nebo `ochrana-osobnich-udaju` má stránku dál funkční na `/{slug}`.
4. Registrace bez zaškrtnutého souhlasu skončí validační chybou; registrace se souhlasem uloží `terms_accepted_at` i `terms_version`.
5. Nově založený e-shop má tři nepublikované stránky s vyplněnou šablonou obsahující aspoň jeden `[DOPLŇTE …]`.
6. Publikovaná stránka nájemce se objeví v patičce storefrontu; nepublikovaná ne.
7. Právní stránky platformy nesou `<link rel="canonical">` a nejsou `noindex`.

## Omezení a rizika

**Kolize URL.** Od vlny 3.1 obsluhuje `/{slug}` na hostu nájemce `Route::fallback()` modulu `pages`. Explicitní routa `/cookies` registrovaná pro platformu by se namatchla i na hostu nájemce; `RequirePlatformHost` sice odpoví 404, ale to už je **po** matchi, takže fallback se neuplatní a stránka nájemce zmizí. Protože `Modules\Pages\Lifecycle` sám seeduje `ochrana-osobnich-udaju`, nešlo by o teoretické riziko.

Prefix `/pravni/` to vylučuje konstrukcí: stránky nájemce jsou vždy jednosegmentové, takže dvousegmentová platformní cesta se s nimi nemůže potkat.

Zvažovaná alternativa `Route::domain(config('tenancy.platform_domain'))` neprošla: doména se do routy zapeče při bootu, kdežto testy nastavují `tenancy.platform_domain` až v `setUp()`, takže by se rozešly. `DomainTenantFinder::isPlatformHost()` čte config za běhu, a proto funguje.

**Právní riziko.** Drafty nejsou právní služba. Konzervativní formulace snižují riziko, neodstraňují ho. Zejména DPA a limitace odpovědnosti patří před spuštěním právníkovi.

**Verze souhlasu.** `terms_version` je řetězec z configu, ne odkaz do tabulky verzí. Historie znění dokumentů je v gitu. Pokud vznikne potřeba prokázat *znění* k datu, bude potřeba samostatná tabulka — mimo tuto vlnu.

## Mimo rozsah

- **Cookie lišta a souhlas s cookies na storefrontu nájemce** — vlna 3.3 spolu s měřicími kódy. Dnes běží jen technické cookies (session, `cart`, XSRF), pro které se souhlas nevyžaduje, takže lišta by dnes neměla co blokovat.
- **Měřicí kódy** (GA4, Sklik, Meta Pixel, Heureka ověřeno zákazníky) — vlna 3.3.
- **Marketingová homepage platformy** — `Welcome.vue` je dodnes Laravel default včetně obrázku načítaného z `laravel.com`. Vlna se ho dotkne jen odkazy do patičky.
- **Platební účet platformy** z checklistu „Před spuštěním" — obchodní úkon, ne kód.
