# Účetní export — další kroky

Vlna 2.11 dodala premium modul `accounting`: dávka dokladů za období do Pohoda XML a ISDOC 6.0.1, plus jednotlivý doklad jako `.isdoc`. Spec: [`docs/superpowers/specs/2026-07-30-vlna-211-accounting-export-design.md`](../superpowers/specs/2026-07-30-vlna-211-accounting-export-design.md).

Odloženo vlastníkem při brainstormingu 2026-07-30:

## ISDOC jako příloha k faktuře pro odběratele

Přiložit `.isdoc` k e-mailu s dokladem (`DocumentIssued`) a nabídnout ho ke stažení v účtu zákazníka, aby si firemní odběratel fakturu naimportoval do svého účetnictví. Generátor už existuje, práce je jinde:

- **Transakční pošta** — příloha zvětší každý e-mail s fakturou; `MailService` dnes přílohy neposílá vůbec, takže je to nová schopnost jádra, ne jen parametr.
- **Zákaznická plocha** — vedle `/faktura/{number}/pdf` by přišla druhá gated routa; ownership a `customer.session` politika platí stejně.
- **Kdy soubor vzniká** — PDF se dnes generuje odloženým jobem a příloha by na něj musela počkat, jinak by e-mail odešel bez ní.

## Money S3 a další proprietární formáty

Registry formátů (`AccountingFormats`) je na přidání připravené — nový formát je nový soubor bez zásahu do stávajících. Co každý formát stojí: vlastní XSD, vlastní číselníky (předkontace, členění DPH), vlastní golden file test a vlastní pre-deploy ověření reálným importem. Bez nájemce, který formát konkrétně žádá, je to slepá investice.

Kandidáti podle rozšíření v ČR: Money S3, ABRA Flexi, iDoklad (ten ISDOC umí, takže je pravděpodobně pokrytý).

## Filtry exportu

Filtr podle typu dokladu nebo odběratele je malý přírůstek k `ExportDocumentsRequest`. Naopak **„jen nezaúčtované"** není filtr, ale nová evidence: doklad by musel nést stav zaúčtování, někdo by ho musel nastavovat (ručně po importu? potvrzením v adminu?) a export by ho měnil, takže by z čistě čtecí operace byla zapisující. Vlastní vlna, ne přírůstek.

## Plná mapovací obrazovka

Dnes je předkontace **jedna pro celý doklad** a sazby DPH se mapují fixní tabulkou. Nájemce, který chce různé předkontace podle druhu zboží (zboží / služba / doprava), si doklady v Pohodě dorovnává ručně. Plné mapování by znamenalo:

- předkontace per kategorie produktu nebo per daňová sazba (vlastní tabulka mapování, vzor `feed_category_mappings` z vlny 2.9),
- párování číselných řad Pohody s našimi řadami dokladů,
- vlastní obrazovku, protože se to do plochého schématu nastavení modulu nevejde.

## Fronta a historie běhů

Vlna streamuje v requestu se stropem `config('accounting.max_documents')` = 5 000 dokladů. Strop je odhad, ne měření. Až se objeví nájemce, kterému nestačí, změří se skutečná doba generování a buď se strop zvedne, nebo se export přesune do fronty s tabulkou běhů a stavovou obrazovkou (vzor `product_imports` z vlny 2.8) — včetně `failed()` hooku, který tam podle as-is 2.8 dodnes chybí.
