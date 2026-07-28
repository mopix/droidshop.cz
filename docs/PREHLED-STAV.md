# DroidShop.cz — přehled stavu (co je hotové, co nás čeká)

Stav k 2026-07-28 · verze 0.24.x · 1514 automatických testů zelených · vlny 2.2–2.7 na větvi před mergem, zbytek v `main`.

Zdroj pravdy detailů: [`docs/as-is/STATUS.md`](as-is/STATUS.md). Tady je čtivý přehled po rolích + roadmapa.

---

## Jak to číst

DroidShop je **multi-tenant SaaS e-shop platforma** (typ Shoptet). To, co je vidět na obrazovce (výpis produktů), je ~20 % práce. Zbylých 80 % je neviditelná infrastruktura: izolace nájemců, modulární systém, fakturace, platby, doklady, onboarding, automatické domény. **Tahle neviditelná část je hotová** — a je to ta těžká a riziková část, na které SaaS platformy stojí a padají. Proto ti „to, co vidíš" přijde jako málo — demo má jen 4 produkty a holou šablonu. Ale motor je kompletní.

---

## 1. SUPERADMIN (ty jako provozovatel platformy) — `droidshop:8000/superadmin`

### Co vidí a může
- **Dashboard** platformy.
- **Tenanti (nájemci):** seznam, detail, **změna stavu** (trial → active → past_due → suspended → pending_deletion → deleted), přiřazení **tarifu**, **aktivace/deaktivace modulů** per tenant.
- **Kill switch modulu** globálně (vypne modul všem najednou).
- **Tarify (plans):** správa tarifů a jejich modulů/cen.
- **Platformní faktury** (co my fakturujeme nájemcům za předplatné) + PDF, číselná řada.
- **Read-only stav předplatného** tenanta (Stripe subscription).
- **Impersonace nájemce** — přihlásíš se „jako nájemce" do jeho adminu přes podepsaný odkaz.
- **2FA** (TOTP) — povinné, setup při prvním loginu.

### Co ještě NEMÁ (čeká)
- Metriky / grafy / reporting (tržby platformy, MRR, churn).
- Zakládání tenanta ručně z UI (dnes jen self-service onboarding nájemcem).

---

## 2. NÁJEMCE — admin e-shopu · `obchod.droidshop:8000/admin`

### Co vidí a může
- **Produkty:** CRUD, ceny, DPH, sklad, obrázky, SEO pole (title/description/slug).
- **Kategorie:** strom (max 4 úrovně), 301 redirecty ze starých slugů, řazení tlačítky (i bez myši).
- **Objednávky:** výpis, detail, **editace položek** (přepočet skladu), **ruční objednávka**, **storno**, **dvojitý stavový automat** (zvlášť stav vyřízení × stav platby).
- **Zákazníci:** výpis, detail, **GDPR export** i **výmaz** (anonymizace, ne smazání — drží historii objednávek).
- **Doprava a platby:** způsoby dopravy (kurýr paušál, osobní odběr), platby (dobírka, převod + QR, karta Comgate), **matice doprava × platba**, bankovní účet pro QR šifrovaný.
- **Doklady:** faktury (ruční i automatické při zaplacení), **dobropis**, **proforma**, PDF, číselné řady per rok, e-mail zákazníkovi, **CSV VAT export** pro účetní.
- **Fakturační profil** nájemce (dodavatel na dokladech).
- **Předplatné:** platba kartou přes Stripe (hostovaný Checkout), správa přes Billing Portal, **měsíční/roční** interval, **upgrade/downgrade** tarifu, faktury předplatného.
- **Vlastní doména (vlna 2.1):** přidat doménu, DNS instrukce s tokenem, „Ověřit", stavový badge, smazat. *(Reálné ověření + certifikát funguje jen na veřejné VPS, ne lokálně.)*
- **Statické stránky** (VOP, kontakt…), **nastavení identity e-mailu**.

### Co přibylo ve vlnách 2.2–2.7
- **Vzhled e-shopu:** vlastní barvy, logo, favicon (`/admin/nastaveni/vzhled`).
- **Bloková homepage:** nájemce skládá úvodní stránku z 5 typů bloků.
- **Varianty produktu** (Velikost × Barva): osy, hodnoty, generování matice, cena a sklad per varianta.
- **Zásilkovna:** výdejní místa, hromadné podání, štítky, expediční fronta, tracking.
- **Slevy:** kupóny i automatická pravidla (procento, pevná částka, doprava zdarma, cíl na kategorii/produkty, limity).
- **Akční ceny** produktu i varianty s obdobím + povinný údaj o nejnižší ceně za 30 dní (Omnibus).
- **CSV import a export** katalogu včetně variant: stáhnout, upravit v Excelu, nahrát zpět; chybné řádky v protokolu, režim „jen zkontrolovat".
- **Feedy pro Heureku a Zboží.cz**: zapnout, namapovat kategorie, adresu vložit do porovnávače; varianty jako samostatné položky.

### Co ještě NEMÁ (čeká)
- Hromadné operace nad výběrem, automatické řezy obrázků, obrázky z URL v importu.
- Filtry v katalogu, štítky, výrobci, blog, recenze.
- Volba mezi **víc šablonami** storefrontu (dnes jedna, ale přebarvitelná).
- Další dopravci (PPL, DPD) — dnes Zásilkovna + paušál + osobní odběr.

---

## 3. STOREFRONT — zákazník nájemce · `obchod.droidshop:8000`

Celý veřejný web je **Blade SSR** (server-rendered) a **funguje bez JavaScriptu** — kvůli SEO a robustnosti.

### Co funguje
- Homepage, **detail produktu**, kategorie, **vyhledávání**, statické stránky.
- **Košík → pokladna (doprava+platba → údaje) → děkovná stránka** — celé bez JS.
- **Platby:** dobírka, bankovní převod + **QR** (SPAYD), **karta přes Comgate** (ověření platby server-to-server, webhook, expirace nezaplacených).
- **Faktura** ke stažení v účtu, e-mailem.
- **Zákaznický účet:** registrace, přihlášení, reset hesla, ověření e-mailu, **historie objednávek**, adresy.
- **SEO výstupy:** title/meta/canonical/OG, JSON-LD, `sitemap.xml`, `robots.txt`, 301/410.
- **Onboarding nájemce:** registrace → průvodce → e-shop na subdoméně **do 10 minut**, auto-login napříč hosty.

### Co ještě NEMÁ (čeká)
- Galerie/zoom, našeptávač, mini-košík (ostrůvky) — výběr varianty už ostrůvek má.
- Page cache (rychlost, spec §15.6) — čeká na stabilní Redis.
- Vícejazyčnost (fáze 3), Google Merchant feed (Heureka a Zboží hotové ve vlně 2.9).
- Filtr „ve slevě" a fasety v katalogu.

---

## 4. Co je hotové „pod kapotou" (jádro)
- **Multi-tenancy:** sdílená DB + `tenant_id`, host → tenant, tvrdá izolace dat + CI brána, která ověřuje, že nájemce A nevidí data nájemce B.
- **Modulární systém:** moduly jdou zapínat/vypínat per nájemce i globálně (kill switch); manifest, oprávnění z manifestů.
- **Kernel služby:** Money (peníze v haléřích), Settings, Limits (kvóty tarifu), Sequences (číselné řady), FileStorage, **MailService** (per-tenant, limity, transakční pošta nikdy neblokovaná), Audit log.
- **Superadmin auth + 2FA + impersonace.**
- **Platformní billing:** reálné inkaso předplatného přes Stripe (žádné karetní údaje u nás, PCI SAQ-A), webhook-driven, český daňový doklad.
- **Vlastní domény + automatické TLS** (Caddy on-demand, ověření vlastnictví přes DNS).

---

## 5. Co je před námi (roadmapa, hrubě dle priorit)

### A) Pre-launch (nutné před ostrým spuštěním)
- Právní: **VOP platformy, GDPR, cookies** (odpovědnost nájemce za obsah).
- Provozní: **wildcard DNS + TLS `*.droidshop.cz`**, Caddy on-demand + `PLATFORM_TLS_CHECK_TOKEN` (runbook `docs/as-is/2026-07-23-custom-domains.md`), platformní platební účet, cron `schedule:run`, vyplnit `config('billing.company')`.
- Ověřit reálná Stripe + Comgate volání s ostrými klíči (smoke test).

### B) Storefront hodnota pro nájemce (nejvíc „vidět")
- ~~Šablona/vzhled, varianty produktu, Zásilkovna~~ — **hotovo** (vlny 2.2–2.5).
- Filtry katalogu, štítky, výrobci.
- Page cache (rychlost, Lighthouse ≥ 90).

### C) Provozní komfort
- ~~CSV import/export produktů~~ — **hotovo** (vlna 2.8). Zbývá hromadné operace, řezy obrázků.
- ~~Heureka/Zboží feedy~~ — **hotovo** (vlna 2.9). Zbývá Google Merchant.
- Superadmin metriky (MRR, churn, tržby).

### D) Premium / později (fáze 3)
- Vícejazyčnost storefrontu (multilang + hreflang).
- Licence / digitální produkty s aktivačním API.
- ISDOC / Pohoda export dokladů.
- Recenze/hodnocení, blog.

### E) Kvalita
- **E2E testy (Playwright)** — zavést od hlavních flow (dnes blokováno certifikátem).

---

## 6. Historie vln (co už proběhlo)
Fáze 0 (tenancy jádro, moduly, superadmin) → 1.1–1.2 (katalog) → 1.3 (košík/pokladna/objednávky) → 1.4 (platby Comgate) → 1.5–1.6 (faktury/dobropis/proforma/VAT export) → 1.7 (onboarding + platformní billing) → 1.8 (Stripe předplatné) → 1.9 (roční interval + změna tarifu) → 2.1 (vlastní domény + auto TLS) → 2.2 (šablona + branding) → 2.3 (bloková homepage) → 2.4 (varianty produktu) → 2.5 (Zásilkovna) → 2.6 (slevový engine) → 2.7 (akční ceny + Omnibus) → 2.8 (CSV import/export) → **2.9 (feedy Heureka a Zboží)**. Každá vlna: spec → plán → TDD implementace → review → merge.

**Závěr:** motor SaaS platformy stojí celý (platby, fakturace, domény) a viditelná vrstva ho dohnala — šablona, varianty, dopravce, slevy i akční ceny jsou hotové. Zbývá hlavně **nasazení a právní minimum**, plus provozní komfort (feedy, CSV, page cache).
