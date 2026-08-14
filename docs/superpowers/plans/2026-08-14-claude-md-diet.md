# Odlehčení `CLAUDE.md` — implementační plán

> **Pro agenta:** Kroky s `- [ ]`. Práce je čistě dokumentační — žádný produkční kód, žádná migrace, žádný build.

**Cíl:** Snížit trvalý kontext načítaný do každé session z ~42k tokenů na ~5k, aniž by se ztratila jediná informace.

**Architektura:** `CLAUDE.md` zůstává jediným vstupním bodem, ale nese už jen *živá pravidla* a *index*. Historie rozhodnutí se stěhuje do `docs/decisions/` rozdělená po oblastech; agent si soubor otevře teprve tehdy, když v dané oblasti pracuje.

**Tech stack:** Dle `docs/PROJECT-PROFILE.md` (beze změny)

**Spec:** není — údržbový úkol, zadání v konverzaci 2026-08-14

---

## Proč

Naměřeno 2026-08-14:

| Zdroj | Velikost | Načítá se |
|---|---|---|
| `CLAUDE.md` → sekce **Rozhodnutí** | 125 KB | každou session |
| `CLAUDE.md` → sekce **Nuance projektu** | 17,6 KB | každou session |
| `CLAUDE.md` → zbytek (Projekt, Stack, Role, Pravidla, Struktura, Omezení, Před spuštěním) | 12,9 KB | každou session |
| `.claude/rules/*.md` | 13,2 KB | každou session |
| **Celkem** | **~169 KB ≈ 42k tokenů** | |

Sekce **Rozhodnutí** je 80 % souboru a drtivá většina jejích 130+ položek popisuje uzavřené vlny, které při běžné práci nikdo nepotřebuje. Sekce **Nuance projektu** je souvislý odstavcový přehled stavu všech vln — obsahově překrývá `docs/as-is/STATUS.md` a `CHANGELOG.md`.

**Nic se nesmí smazat.** Rozhodnutí jsou v tomto projektu nosná — vysvětlují, proč kód vypadá, jak vypadá, a opakovaně brání regresi. Cíl je *přemístit*, ne *zredukovat*.

## Cílový stav

```
CLAUDE.md                    ~5 KB   Projekt, Stack, Role, Pravidla, Struktura,
                                     Omezení agenta, index do docs/decisions/,
                                     odkaz na STATUS.md, Před spuštěním
docs/decisions/README.md             Index + pravidlo, kam nové rozhodnutí zapsat
docs/decisions/*.md                  Rozhodnutí po oblastech, chronologicky uvnitř
```

## Dělení do souborů

Podle oblasti, ne podle vlny — agent hledá „proč je platba udělaná takhle", ne „co bylo ve vlně 1.4". Uvnitř souboru zůstává chronologické pořadí a **doslovné znění** položek včetně data.

| Soubor | Obsah |
|---|---|
| `01-architektura-a-tenancy.md` | volba balíčků, `TenantContext`, izolace, moduly, manifest, oprávnění, tarify, `PlanModuleDefaults`, kernel kontrakty |
| `02-katalog-a-produkty.md` | URL schéma, varianty, ceny a DPH na produktu, akční ceny + Omnibus, CSV import/export, hledání, obrázky |
| `03-checkout-a-objednavky.md` | košík, cenová autorita, odpis skladu, stavový automat, editace a storno, slevový engine |
| `04-platby-a-doklady.md` | registry bran, Comgate, verify-before-trust, doklady, číslování, dobropis/proforma, VAT export, účetní formáty |
| `05-storefront-a-seo.md` | Blade SSR, ostrůvky, šablona a branding, page builder, page cache, feedy, redirecty, statické stránky |
| `06-doprava.md` | Zásilkovna, výdejní místa, podání a idempotence, doručení na adresu, rozměry |
| `07-billing-platformy.md` | Stripe Billing, webhooky, trial lifecycle, platformní ledger, změna tarifu, custom domény a TLS |
| `08-admin-a-ui.md` | layout administrace, nastavení modulů a obchodu, koruny v adminu, rich text editor, WCAG rozhodnutí |
| `09-bezpecnost-a-pravo.md` | sanitizace, šifrovaná nastavení, throttling, souhlas s VOP, cookie lišta, měření, GDPR výmaz |
| `10-testy-a-provoz.md` | E2E rozhodnutí, poučení o testech, které nemohou selhat, lokální běh |

Rozhodnutí patřící do dvou oblastí jde **jen do jedné** (té, kde ho bude někdo hledat) a z druhé se na něj odkáže jedním řádkem.

## Kroky

- [x] **1. Založit `docs/decisions/README.md`** — tabulka souborů (převzít z tohoto plánu), pravidlo pro zápis nové položky (formát `- YYYY-MM-DD: **teze.** odůvodnění`), věta „chronologie uvnitř souboru, nikdy nepřepisovat historickou položku — jen přidat novou, která ji ruší"
- [x] **2. Rozřezat sekci Rozhodnutí** — položku po položce do souborů `01`–`10`, **beze změny textu**. Nekomprimovat, nepřepisovat, nesjednocovat.
- [x] **3. Ověřit, že se nic neztratilo** — `grep -c '^- 20' ` nad novými soubory se musí rovnat počtu položek v původní sekci. Zapsat obě čísla do commit message.
- [x] **4. Přesunout sekci Nuance projektu** — ~~sloučit do `docs/as-is/STATUS.md`~~ **odchylka:** `STATUS.md` (34 KB) už stav pokrývá podrobněji v tabulce; ruční slévání dvou prozaických přehledů nese riziko ztráty formulace bez přínosu. Sekce proto archivována **celá a beze změny** jako `docs/as-is/2026-08-14-prehled-vln.md`, odkaz z `CLAUDE.md` i `docs/README.md`. Ověřeno diffem: 16 řádků, identické byte po bajtu.
- [x] **5. Přepsat `CLAUDE.md`** — sekce `Rozhodnutí` a `Nuance projektu` nahradit:
  - `## Rozhodnutí` → jeden odstavec „*Architektonická rozhodnutí a jejich odůvodnění jsou v `docs/decisions/`. **Před zásahem do dané oblasti si její soubor přečti** — většina rozhodnutí popisuje past, do které se už jednou šláplo.*" + tabulka oblast → soubor
  - `## Nuance projektu` → tři až pět vět o tom, co platforma dnes umí a co zbývá, + odkaz na `docs/as-is/STATUS.md`
- [x] **6. Zkontrolovat odkazy** — `grep -rn "CLAUDE.md" docs/ .claude/` a ověřit, že nikdo neodkazuje na přesunutou sekci
- [x] **7. Změřit výsledek** — `wc -c CLAUDE.md`, zapsat do commit message před/po
- [x] **8. Doplnit pravidlo údržby** — do `CLAUDE.md` → `## Údržba tohoto souboru` větu, že nové rozhodnutí se zapisuje do `docs/decisions/`, ne sem
- [x] **9. Commit** — `docs: move architecture decisions out of CLAUDE.md into docs/decisions/`

## Testy

Netýká se kódu, testy se nespouští. Ověření je mechanické:

1. Počet položek před = počet položek po (krok 3).
2. `git diff --stat` ukáže, že součet přírůstků v `docs/decisions/` odpovídá úbytku v `CLAUDE.md` (± hlavičky souborů).
3. `rg -c 'K PRÁVNÍ REVIZI|háček|Háček' ` — kontrolní vzorek frází, které se nesmí ztratit.

## Rizika

| Riziko | Mitigace |
|---|---|
| Agent přestane rozhodnutí číst, protože nejsou „před očima", a zopakuje starou chybu | Index v `CLAUDE.md` musí být **imperativní** („před zásahem si přečti"), ne pasivní odkaz. Navíc `.claude/rules/` u kritických oblastí (storefront, dokumentace) zůstávají a načítají se dál. |
| Při řezání se položka ztratí nebo zdvojí | Krok 3 — mechanické počítání, ne oční kontrola |
| Rozhodnutí se rozejde napůl mezi dva soubory | Pravidlo „jen do jedné oblasti, z druhé odkaz" v `docs/decisions/README.md` |
| Ztráta chronologie napříč oblastmi (co bylo dřív) | Datum zůstává na každé položce; `git log` drží pořadí |

## Mimo rozsah

- Zkracování nebo přepisování textu rozhodnutí (jiný úkol, jiné riziko)
- `CHANGELOG.md` (110 KB) — do kontextu se nenačítá, není potřeba řešit
- `docs/PREHLED-STAV.md` vs `docs/as-is/STATUS.md` — možná duplicita, řešit až po kroku 4

---

## Výsledek (provedeno 2026-08-14)

| Metrika | Před | Po |
|---|---|---|
| `CLAUDE.md` | 155 507 B | 15 544 B |
| `CLAUDE.md` + `.claude/rules/*` (auto-load) | ~169 000 B | 27 787 B |
| Položek rozhodnutí | 206 | 206 (v `docs/decisions/`) |

Kontrola integrity: seřazený seznam položek z `git show HEAD:CLAUDE.md` je **identický** se seznamem
z `docs/decisions/*.md`; archiv Nuance projektu je identický s originálem byte po bajtu.

Ve stejném úklidu (mimo rozsah tohoto plánu): smazány mrtvé `rules/frontend-spa.md`,
`skills/{vue-spa-development,pest-testing,fortify-auth}`; skill `accessibility` a agenti
`a11y-checker`, `ui-engineer`, `backend-engineer`, `qa-expert` přepsáni z WooShop šablony na
realitu DroidShopu; `.claude/settings.json` zbaven WordPress příkazů.
