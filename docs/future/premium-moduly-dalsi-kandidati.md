# Premium moduly — další kandidáti

Rozhodnutí vlastníka 2026-07-30: tarifní rozdělení je **base = celý prodejní e-shop**, **premium = base + marketingové nástroje a vyšší limity**. Dnes je premium odlišené modulem `discounts` (vlna 2.6) a limity (produkty 500→5000, storage 2→20 GB, e-maily 3k→30k). Přiřazení se odvozuje z `level` v manifestu, viz `App\Core\Modules\PlanModuleDefaults`.

Jako první nový premium modul se staví **`accounting`** (ISDOC / Pohoda XML nad hotovými `documents`). Dva kandidáti, které vlastník odložil, jsou tady.

> **Pozor na přesun modulu mezi tarify:** vzít modul z base do premium po spuštění znamená, že ho stávající base nájemci ztratí při první rekonciliaci (`PlanModuleReconciler`, `TenantPlanSwitcher`). Grandfathering systém neumí. Nový premium modul tímto problémem netrpí — nikdo ho ještě nemá.

## `abandoned-cart` — záchrana opuštěných košíků

Spec kap. 18 (naznačeno). Nejsilnější prodejní argument pro premium tarif z celého seznamu: nájemce vidí přímo vrácené objednávky, takže se tarif prodává sám. Nesahá na cenovou autoritu ani na doklady.

Co bude potřeba promyslet:

- **Detekce opuštění** — `carts` už drží stav i `updated_at`; kritérium je „košík s položkami, bez objednávky, starší než N", ale musí přežít i to, že zákazník dokončil nákup jiným košíkem po přihlášení (`CartMerger`).
- **E-mailová řada** — víc zpráv s odstupem (např. 1 h / 24 h / 72 h), každá vypnutelná. Jde přes `MailService` s `MailKind::Bulk`, takže se **počítá do limitu** `emails_month` a vyčerpaný tarif je smí zastavit (na rozdíl od transakční pošty, rozhodnutí 2026-07-20).
- **Obnovení košíku podepsaným odkazem** — vzor `onboarding.enter` (krátká TTL, podpis kryje celou absolutní URL). Odkaz obnoví cizí košík, takže je to citlivá plocha: nesmí jít uhodnout ani recyklovat.
- **Host vs. přihlášený zákazník** — u hosta máme e-mail jen tehdy, když ho vyplnil v pokladně a nedokončil. Bez souhlasu je to marketingová pošta na adresu z nedokončeného nákupu; **potřebuje právní stanovisko** (ePrivacy, oprávněný zájem vs. souhlas) dřív, než se to postaví.
- **Statistiky záchran** — kolik odesláno, kolik se vrátilo, jaká hodnota. Bez čísla nájemce nepozná, že mu modul vydělává.
- **GDPR** — `CustomerEraser` musí čistit i frontu připomínek; anonymizovaný zákazník nesmí dostat e-mail.

## `licensing` — digitální produkty a licenční klíče

Spec **kap. 17** (premium, fáze 2) — jediný modul, který spec označuje za premium explicitně a rozepisuje. Nejsilnější odlišení, ale největší rozsah; počítat s vlnou výrazně větší než feedy (2.9).

Co bude potřeba promyslet:

- **Typ produktu „digitální"** — dnešní `products` počítá se skladem a hmotností; digitální položka nemá ani jedno a nesmí spadnout do výpočtu dopravy (`ShippingOptions`, hmotnostní pásma) ani do podání zásilky (`packeta`).
- **Generování a doručení klíčů** — vlastní zásoba klíčů (import) vs. generování na objednávku; doručení v e-mailu i v účtu zákazníka, s možností opětovného stažení.
- **Aktivační API** — veřejný endpoint pro cizí software (ověření klíče, počet aktivací, revokace). Netenantová autentizace, rate limit, a přemyslet, že je to první veřejné API platformy.
- **Vrácení a revokace** — storno objednávky s digitálním produktem musí umět klíč zneplatnit; dnešní `returnStock` logika tady nemá význam.
- **Účetní režim** — digitální služba má jiné pravidlo místa plnění DPH (OSS u zákazníků z EU), což se dotýká `docs` i VAT rekapitulace. **Nutné konzultovat s účetní**, ne odhadnout.
- **Vazba na `accounting`** — pokud vznikne dřív, klíče a digitální položky musí do exportu sednout beze změny formátu.
