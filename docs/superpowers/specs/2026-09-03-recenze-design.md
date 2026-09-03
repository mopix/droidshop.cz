# Recenze produktů a hodnocení obchodu

**Datum:** 2026-09-03
**Status:** draft
**Související plán:** `docs/superpowers/plans/2026-09-03-recenze.md` (vznikne po schválení)

## Kontext

Storefront má na recenze připravené místo a nic v něm není. `.claude/rules/storefront-rendering.md`
předepisuje na detailu produktu JSON-LD `Product` + `Offer` + `AggregateRating` s poznámkou
„až budou recenze"; blok `Product` v `Modules/Products/Resources/views/storefront/show.blade.php`
dnes hodnocení nenese, takže výsledky hledání nájemce nemají hvězdy a Heureka feed nemá čím doplnit
důvěryhodnost. Ve specifikaci platformy jsou recenze vedené jako post-MVP backlog (sekce „Explicitně MIMO MVP“).

Jde o jedinou funkci, na kterou už hotový kód aktivně čeká, a je přímo měřitelná v SEO nájemce.

**Právní rámec je součástí zadání, ne poznámka pod čarou.** Po novele zákona o ochraně spotřebitele
(implementace směrnice Omnibus) musí prodejce, který zveřejňuje recenze, uvést, zda a jak ověřuje, že
pocházejí od spotřebitelů, kteří výrobek skutečně koupili. Zveřejňování neověřených recenzí bez
tohoto sdělení i selektivní publikace jen příznivých hodnocení jsou nekalé obchodní praktiky. Model
recenzí je proto postavený na ověřeném nákupu a moderace nese povinný důvod zamítnutí.

## Cíle

- [ ] Recenze produktu píše **jen ověřený kupující** — vazba na vlastní dokončenou objednávku
- [ ] Hodnocení celého e-shopu ve stejném mechanismu
- [ ] Nájemce recenzi **schvaluje před zveřejněním**, zamítnutí má povinný důvod a stopu v audit logu
- [ ] Nájemce může na recenzi veřejně odpovědět
- [ ] Automatická e-mailová výzva N dní po doručení objednávky, jedna na objednávku
- [ ] Hvězdy na detailu produktu, ve výpisu kategorie a na homepage; `AggregateRating` v JSON-LD
- [ ] Modul `reviews` v tarifu **base** — mají ho všichni nájemci

## Mimo rozsah

- **Fotografie a videa v recenzi.** Zvedlo by to spotřebu `storage_mb`, kterou tarif měří, a otevřelo
  moderaci obrazového obsahu. Kandidát na pokračování.
- **Hlasování „byla recenze užitečná"** a řazení podle užitečnosti.
- **Import recenzí z Heureky nebo Zboží.cz** a export do „Ověřeno zákazníky" — třetí strana, vlastní
  souhlas se zpracováním, vlastní API. Samostatná vlna.
- **Recenze bez nákupu** v jakékoli podobě, včetně přepínače pro nájemce. Kdyby ji chtěl, musí se
  změnit i právní sdělení na storefrontu; sloupec `verified_purchase` to připouští bez migrace,
  rozhodnutí ale není součástí téhle vlny.
- **Recenze variant.** Recenzuje se produkt, ne konkrétní varianta.
- **Vícejazyčné recenze.** Storefront je jednojazyčný až do fáze 3.

## Požadavky

### Backend

#### Modul

Nový modul `reviews`, `core: false`, `billable: false`, **`level: "base"`** (`PlanModuleDefaults`
ho pak udělí automaticky, vlastní migraci to nepotřebuje). `requires` je **prázdné** — modul čte
katalog přes kontrakt `product-catalog` a bez modulu `products` degraduje na hodnocení obchodu.
Deklarovaná závislost by nájemci znemožnila katalog vypnout; totéž rozhodnutí padlo u modulu
`storefront`.

`permissions`: `reviews.moderate`. `provides`: `reviews`.

`settings_schema` (`settings.json`):

| klíč | typ | výchozí | význam |
|---|---|---|---|
| `invitations_enabled` | boolean | `true` | posílat výzvy k recenzi |
| `invite_after_days` | number 1–90 | `7` | kolik dní po doručení |
| `shop_reviews_enabled` | boolean | `true` | sbírat i hodnocení obchodu |
| `min_body_length` | number 0–500 | `0` | minimální délka textu; 0 = text nepovinný |

#### Schéma

**`reviews`**

| sloupec | typ | poznámka |
|---|---|---|
| `tenant_id` | FK | `BelongsToTenant` |
| `subject` | enum `product`\|`shop` | jedna tabulka pro obojí |
| `product_id` | unsigned **not null**, `0` = obchod | **bez cizího klíče**, stejně jako `order_items.product_id` — recenze musí přežít smazaný produkt. Nulová hodnota místo `NULL` schválně: MySQL považuje každé `NULL` v unikátním indexu za odlišné, takže s nullable sloupcem by unikát níž **nezabránil** dvěma hodnocením obchodu z jedné objednávky |
| `order_id` | unsigned | důkaz nákupu |
| `customer_id` | unsigned nullable | host nakupuje bez účtu |
| `author_name`, `author_email` | string | snímek z objednávky |
| `rating` | tinyint 1–5 | |
| `title` | string nullable | |
| `body` | text nullable | prochází `HtmlSanitizer` |
| `status` | enum `pending`\|`published`\|`rejected` | |
| `rejection_reason` | string nullable | **povinný**, když `status = rejected` |
| `moderated_by`, `moderated_at` | | kdo a kdy |
| `reply_body`, `reply_at` | | odpověď prodejce, jedna na recenzi |
| `verified_purchase` | boolean | vždy `true`; sloupec drží místo pro budoucí změnu pravidla |
| `published_at` | | řazení na storefrontu |

Unikát `(tenant_id, order_id, subject, product_id)` — jedna recenze na produkt z jedné objednávky
a jedno hodnocení obchodu z jedné objednávky.

**`review_aggregates`** — `tenant_id`, `product_id` (`0` = obchod, ze stejného důvodu jako výše), `rating_avg` (decimal 2,1),
`rating_count`, `count_1`…`count_5`. Průměr se **nepočítá dotazem**: výpis kategorie o 24 produktech
by dělal 24 agregací. Přepočítává se při každé změně stavu recenze. Tabulka patří modulu `reviews`,
**ne** denormalizovaným sloupcům v `products` — ty patří cizímu modulu a po vypnutí recenzí by
zůstala mrtvá data.

**`review_invitations`** — `tenant_id`, `order_id`, `token_hash` (sha256, nikdy samotný token),
`sent_at`, `expires_at`, `used_at`. Vzor převzatý z `customer_tokens`. Token, ne přihlášení: většina
objednávek je hostovská.

**`review_optouts`** — `tenant_id`, `email`, `created_at`. Odhlášení z výzev, per nájemce.

#### Tok

1. Objednávka přejde do `delivered` (`Order::FULFILLMENT_*`).
2. Denní příkaz `reviews:send-invitations` vybere objednávky doručené před `invite_after_days` dny,
   bez existující pozvánky a bez odhlášení, a pošle **jednu** výzvu na objednávku. Vědomě to není
   job odložený při přechodu stavu — týden dlouhé zpoždění ve frontě nepřežije restart workeru
   a cílový hosting nemá trvalý proces, jen cron.
3. `/recenze/{token}` — Blade SSR, `noindex`, průchozí bez JS. Jedna stránka: hvězdy obchodu plus
   každý zakoupený produkt zvlášť, zákazník vyplní kolik chce.
4. Odeslání založí řádky ve stavu `pending`, token se označí `used_at`. Platnost tokenu 60 dní.
   Throttling na odeslání formuláře.
5. Nájemce ve frontě publikuje nebo zamítá. Zamítnutí vyžaduje důvod. Každá akce jde do audit logu.
6. Publikace i skrytí přepočítají `review_aggregates` a bumpnou page cache.

#### E-mail

`MailKind::Bulk`. Není transakční: když nájemci dojde měsíční kvóta, má padnout výzva k recenzi, ne
potvrzení objednávky. Šablona nese odkaz s tokenem a **odhlašovací odkaz** v patičce.

#### Page cache

Publikace, skrytí i odpověď bumpnou dimenzi **`catalog`** (`Dimension::Catalog`). Nová dimenze
`reviews` by nic nezískala — hvězdy jsou na týchž stránkách, které `catalog` už drží (detail produktu
a výpis kategorie mají `page-cache:catalog,theme`, homepage `catalog,content,theme`). Psací stránka
a administrace se necachují.

#### Kolize URL slugu

`/hodnoceni` je skutečná routa a přebije `Route::fallback()` modulu `pages`, takže statická stránka
se slugem `hodnoceni` by se tiše stala nedostupnou. `PageWriter` proto při ukládání slugu **zeptá
router**, jestli na `/{slug}` už nějaká routa sedí, a takový slug odmítne s hláškou. Ne ručně
udržovaný blacklist — ten modul `pages` vědomě zamítl s tím, že by selhával mlčky.

### Frontend

#### Storefront (Blade SSR, žádný fetch po načtení)

- **Detail produktu** — souhrn (průměr, počet, rozpad po hvězdách jako pruhy) a pod ním stránkovaný
  seznam publikovaných recenzí včetně odpovědi prodejce. Vše v HTML první odpovědi.
- **Výpis kategorie a homepage** — hvězdy a počet pod produktem, jedním eager loadem z
  `review_aggregates`.
- **`/hodnoceni`** — hodnocení obchodu a seznam recenzí e-shopu.
- **`/recenze/{token}`** — psací stránka, `noindex`.
- **Povinné sdělení podle Omnibusu** u obou seznamů: kdo smí recenzi napsat a jak se ověřuje.
  Natvrdo v šabloně, **ne v nastavení** — je to zákonná náležitost, ne text, který si nájemce upraví
  nebo smaže.

#### JSON-LD

`AggregateRating` se přidá do existujícího `Product` bloku **jen když `rating_count > 0`** (prázdný
agregát je v Rich Results chyba, ne prázdno), plus pole `review` s několika posledními. Homepage
`Organization` dostane hodnocení obchodu za stejné podmínky.

#### Administrace (Vue 3 + Inertia)

Stránky v `resources/js/Pages/Modules/Reviews/` — do modulu je dát nejde, view finder je nenajde.
Fronta s filtrem podle stavu, hromadné publikování, zamítnutí s povinným důvodem, odpověď prodejce,
skrytí publikované recenze. Nav ve skupině `orders`.

U zamítacího dialogu je uvedeno, že nízké hodnocení není přípustný důvod — selektivní publikace
příznivých recenzí je nekalá praktika.

### Role a viditelnost

| Kdo | Co smí |
|---|---|
| `SUPERADMIN` | vidí modul v seznamu, nemoderuje cizí recenze |
| `TENANT_ADMIN` | plná moderace (`reviews.moderate`), odpověď, nastavení modulu |
| `TENANT_STAFF` | podle oprávnění `reviews.moderate` |
| `CUSTOMER` | napíše recenzi přes token; publikované vidí kdokoli |
| veřejnost | čte publikované recenze; psát bez tokenu nelze |

## Akceptační kritéria

1. Recenzi lze uložit **jen** k produktu, který je v objednávce svázané s tokenem. Pokus o cizí
   `product_id` skončí 422, ne tichým uložením.
2. Token je jednorázový a po 60 dnech neplatný; druhé odeslání týmž tokenem selže.
3. Recenze ve stavu `pending` ani `rejected` se **nikde** na storefrontu neobjeví — ani v seznamu,
   ani v agregátu, ani v JSON-LD.
4. Zamítnutí bez důvodu nelze uložit.
5. `review_aggregates` sedí po publikaci, po skrytí i po zamítnutí — ověřeno proti přímému výpočtu
   z tabulky `reviews`.
6. `AggregateRating` v JSON-LD **chybí** při nule recenzí a **je** při jedné; hodnota odpovídá
   agregátu.
7. Recenze nájemce A není vidět ani dohledatelná u nájemce B (izolace).
8. Publikace recenze zneplatní page cache detailu produktu i výpisu kategorie.
9. Výzva se pošle nejvýš jednou na objednávku, nepošle se odhlášenému e-mailu a při vyčerpané kvótě
   se nepošle vůbec (a potvrzení objednávky se pošle dál).
10. Uložení statické stránky se slugem, na kterém sedí routa (`hodnoceni`, `kosik`, `hledani`),
    skončí validační chybou.
11. Celá cesta prochází **bez JavaScriptu**: psací stránka i čtení recenzí.
12. E2E (Playwright): objednávka → doručení → výzva → napsání → moderace → hvězdy na detailu.
13. Přístupnost WCAG 2.2 AA na obou nových storefront stránkách i na moderační frontě; hvězdičkové
    hodnocení má textovou alternativu, ne jen grafiku.

## Technické poznámky

- Kontrakt katalogu: `product-catalog` (`Modules/Products`), `ProductCatalog::price()`.
- Stavy objednávky: `Order::FULFILLMENT_*`, doručeno = `delivered`.
- Sanitizace textu: `App\Core\Html\HtmlSanitizer` + `UrlGuard`.
- Audit: `AuditLog`.
- Tokeny — vzor `Modules/Customers` `customer_tokens` (hash, účel, expirace).
- Page cache: `App\Core\PageCache\Dimension`.
- JSON-LD: `Modules/Storefront/Resources/views/components/json-ld.blade.php`,
  blok v `Modules/Products/Resources/views/storefront/show.blade.php`.
- Před `migrate` spustit `php artisan modules:sync` (nový modul + nové manifestové schéma).

## Reference

- Produktová specifikace: sekce „Explicitně MIMO MVP" (recenze jako post-MVP) a bod o novelách
  spotřebitelského práva v seznamu legislativy ke sledování
- Pravidlo renderování: `.claude/rules/storefront-rendering.md`
- As-is (po dokončení): `docs/as-is/2026-09-03-recenze.md`
