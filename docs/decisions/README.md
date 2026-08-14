# Rozhodnutí

Architektonická rozhodnutí projektu a **jejich odůvodnění**. Do 2026-08-14 žila tahle sbírka v sekci
`## Rozhodnutí` v `CLAUDE.md`, kde měla 125 KB a načítala se agentovi do kontextu při každé session.
Obsah je přenesený **beze změny textu**, jen rozdělený po oblastech.

## Proč to čti

Většina položek nepopisuje preferenci, ale **past, do které se v tomhle projektu už jednou šláplo**.
Typicky ve tvaru „udělali jsme X, protože Y vypadalo správně, ale tiše rozbíjelo Z". Bez přečtení se
taková chyba zopakuje — několik položek je zápisem přesně takového opakování.

**Před zásahem do oblasti si přečti její soubor.**

## Oblasti

| Soubor | Obsah |
|---|---|
| [`01-architektura-a-tenancy.md`](01-architektura-a-tenancy.md) | Volba balíčků, přepínání nájemce, modulový systém a manifest, oprávnění, kernel kontrakty, jádrové služby (pošta, sekvence, nastavení) |
| [`02-katalog-a-produkty.md`](02-katalog-a-produkty.md) | URL schéma katalogu, varianty, DPH na produktu, akční ceny a evidence nejnižší ceny, CSV import/export, vyhledávání, administrace cen |
| [`03-checkout-a-objednavky.md`](03-checkout-a-objednavky.md) | Cenová autorita, odpis skladu, stavový automat objednávky, editace a storno, slevový engine, pravidla pokladny |
| [`04-platby-a-doklady.md`](04-platby-a-doklady.md) | Registry bran, Comgate, verify-before-trust, doklady, číselné řady, dobropis a proforma, VAT export, účetní formáty |
| [`05-storefront-a-seo.md`](05-storefront-a-seo.md) | Blade SSR a ostrůvky, šablona a branding, bloková homepage, page cache a invalidace, feedy, redirecty, statické stránky |
| [`06-doprava.md`](06-doprava.md) | Zásilkovna — výdejní místa, podání a idempotence, doručení na adresu, rozměry, matice doprava×platba |
| [`07-billing-platformy.md`](07-billing-platformy.md) | Zakládání nájemce, onboarding, Stripe Billing a webhooky, trial lifecycle, platformní ledger, změna tarifu, vlastní domény a TLS |
| [`08-admin-a-ui.md`](08-admin-a-ui.md) | Layout administrace, nastavení obchodu, zadávání v korunách, rich text editor, přístupnostní rozhodnutí, čas obchodu |
| [`09-bezpecnost-a-pravo.md`](09-bezpecnost-a-pravo.md) | Sanitizace vstupů, tokeny a autentizace zákazníka, GDPR výmaz, souhlas s VOP, cookie lišta a měření, právní stránky |
| [`10-testy-a-provoz.md`](10-testy-a-provoz.md) | E2E sada, poučení o testech, které nemohou selhat, lokální a CI běh |

## Jak zapsat nové rozhodnutí

Formát je jeden řádek, chronologicky na konec příslušného souboru:

```markdown
- RRRR-MM-DD: **Teze jednou větou.** Proč právě takhle — co se zvažovalo, co to stálo, co by se
  stalo při opačné volbě. Odkazy na třídy a tabulky v `backticích`.
```

Pravidla:

- **Teze musí říkat, co platí, ne o čem se rozhodovalo.** „Cenová autorita je `ProductCatalog`",
  ne „řešili jsme, odkud brát cenu".
- **Odůvodnění je povinné.** Rozhodnutí bez „proč" je jen konvence a příští čtenář ho beze všeho poruší.
- **Historickou položku nikdy nepřepisuj.** Když se rozhodnutí ruší, přidej novou položku, která na
  starou odkazuje datem — tak zůstane dohledatelné, proč se to kdysi dělalo jinak.
- **Jedna položka jen do jedné oblasti.** Když patří i jinam, odkaž se z druhého souboru jedním řádkem.
- Rozsah nezkracuj kvůli délce. Tyhle soubory se do kontextu nenačítají automaticky — platí se za ně,
  jen když se čtou.

## Související

- Aktuální stav platformy: [`../as-is/STATUS.md`](../as-is/STATUS.md)
- Vrstvy dokumentace (spec / plán / as-is / rozhodnutí): [`../DOCUMENTATION-LAYERS.md`](../DOCUMENTATION-LAYERS.md)
- Známá bezpečnostní rizika: [`../../security_warnings.md`](../../security_warnings.md)
