# As-is status — DroidShop.cz

Poslední aktualizace: **2026-07-22** · Verze: **0.15.0**

## Oblasti

| Oblast | Stav | Spec | Poznámka |
|--------|------|------|----------|
| Laravel skeleton (Breeze + Inertia) | hotovo | — | výchozí app |
| AI / docs workflow | hotovo | bootstrap | `claude-laravel-vue` + WooShop struktura |
| Multi-tenancy — jádro | **hotovo** | §4.2, §4.3, §15.2 | [detail](2026-07-19-tenancy-jadro.md) |
| Izolace dat + CI brána | **hotovo** | §4.2 pojistky 1–3 | pojistka 4 (export) chybí |
| Audit log | **hotovo** | §15.1 | e-mail o změně stavu chybí |
| Kernel služby — Money, Settings, Limits, Sequences, FeatureFlags | **hotovo** | §15.1 | [detail](2026-07-19-kernel-sluzby.md) |
| Kernel služba — FileStorage | **hotovo** | §15.1 | [detail](2026-07-19-filestorage.md); lokální disk, ne S3 |
| Kernel služba — MailService | **hotovo** | §15.1 | [detail](../superpowers/plans/2026-07-20-faze-1-vlna-13-etapa-1-mailservice.md); šablony verifikace a reset hesla dodal modul `customers`, potvrzení objednávky a stavové e-maily dodal modul `orders` |
| Kernel služba — EventBus | odloženo | §15.1 | čeká na prvního volajícího |
| Module system | **hotovo** | kap. 5, §15.5 | [detail](2026-07-19-system-modulu.md) — bez odinstalace |
| Referenční modul `Pages` | **hotovo** | — | statické stránky, Blade SSR |
| Superadmin auth / `platform_admins` / 2FA / impersonace | **hotovo** | §15.4, §6.12 | [detail](2026-07-19-superadmin-auth.md) |
| Superadmin management UI — tenanti, stavy, tarify, moduly, kill switch | **hotovo** | §6.12, §15.5 | [detail](2026-07-20-superadmin-ui.md); bez metrik a bez zakládání tenantů |
| Admin nájemce — shell, navigace z modulů, oprávnění | **hotovo** | §15.4, §15.5 | [detail](2026-07-20-katalog-jadro.md) |
| Kernel — sazby DPH, redirects, sanitizace HTML | **hotovo** | §6.2, §15.3, §16.1 | [detail](2026-07-20-katalog-jadro.md) |
| Modul `categories` — strom, admin, 301 | **hotovo** | §6.3, §16.2 | max 4 úrovně; řazení tlačítky, ne drag&drop |
| Modul `products` — katalog, ceny, sklad, obrázky, SEO | **hotovo** | §6.2, §16.1 | bez variant, CSV importu, řezů obrázků a hromadných operací |
| Modul `checkout` — košík, pokladna, odeslání objednávky | **hotovo** | §3.1, §16.3 | [detail](2026-07-21-checkout.md); Blade SSR + progressive enhancement, funguje bez JS; cenová autorita `ProductCatalog`, ne `cart_items.unit_price`; SPAYD QR pro bankovní převod; online platba kartou přes bránu (vlna 1.4) redirectuje na `PaymentGatewayRegistry` |
| Modul `orders` — perzistence, admin, dvojitý stavový automat | **hotovo** | §16.4 | [detail](2026-07-21-checkout.md); idempotentní odeslání, odpis skladu v téže transakci, edice s deltou skladu, ruční založení, storno; historie objednávek v účtu zákazníka hotová |
| Modul `shipping` — způsoby dopravy a platby, matice | **hotovo** | §16.5 | admin-only + storefront options renderuje checkout; kontrakty `ShippingOptions`/`PaymentOptions` s guest-safe null bindingy; účet pro QR šifrovaný (`encrypted:array`), adminovi jen maskovaný; prázdná řada matice = všechny platby povoleny; provider `comgate` s maskovanými credentials přidán vlnou 1.4 |
| Modul `payments` — online brána Comgate, návrat, webhook, expirace | **hotovo** | §16.6 | [detail](2026-07-21-payments.md); registry/driver (víc bran per tenant), verify-before-trust, idempotentní webhook mimo CSRF, `OrderSettlement` kontrakt, expirační job vrací sklad; jen Comgate driver, GoPay/Stripe = design-for |
| Modul `docs` — faktury, PDF, číselná řada, e-mail | **hotovo** | §16.6 | [detail](2026-07-22-docs.md); base modul, `DocumentIssuer`/`DocumentBook` kontrakty, immutable doklad, auto-vystavení přes doménový event (`order.paid`/`order.shipped`, `DB::afterCommit`), dompdf, plátce/neplátce render distinkce |
| Modul `docs` — dobropis, proforma, CSV VAT export, roční číslování | **hotovo** | §16.6 | [detail](2026-07-22-docs-1-6.md); registry + `DocumentWriter`, dobropis (plný storno, gated, bez QR), proforma (nedaňová, QR), export dle DUZP (CSV formula injection ošetřena) |
| Modul `storefront` — layout, homepage, hledání, chybové stránky | **hotovo** | §4.1.1 | [detail](2026-07-20-storefront-katalog.md) |
| Veřejný katalog — kategorie, produkt, řazení a filtr bez JS | **hotovo** | §16.1, §16.2 | bez košíku |
| SEO výstupy — canonical, OG, JSON-LD, sitemap, robots, 301, 410 | **hotovo** | §3.1, §15.3 | page cache §15.6 chybí |
| Modul `customers` — registrace, přihlášení, reset hesla, verifikace, účet, admin + GDPR výmaz | **hotovo** | §6.7, §15.1 | čtvrtý guard `customer` nad tenant-scoped tabulkou (unikátní `(tenant_id, email)`); vlastní tenant-scoped tokeny místo Laravelího password brokeru; verifikace e-mailu se nikde nevynucuje (čeká na rozhodnutí etapy `checkout`); historie objednávek v účtu hotová, čte přes `OrderBook::forCustomer`/`findForCustomer` s kontrolou vlastnictví |
| Tarify / trial / billing | částečně | §3.1 | tabulka `plans` stojí, přiřazení tenantovi jde z UI; fakturace a trial logika ne |
| Playwright E2E | není | CLAUDE.md | blokováno omezením certifikátu, viz níže |
| Design handoff | prázdné | `docs/design-droidshop/` | |

## Odchylky od produktové specifikace

Detail a odůvodnění: [`2026-07-19-tenancy-jadro.md`](2026-07-19-tenancy-jadro.md) sekce Odchylky.

Nejdůležitější:

1. `SESSION_DOMAIN` je `null` (host-only cookie) — drží session tenanta na jeho doméně.
2. `past_due` nechává storefront běžet — nechceme trestat zákazníky nájemce za jeho nezaplacenou fakturu.
3. `tenants.plan_id` je nullable — onboarding zakládá tenanta před výběrem tarifu.
4. **Inertia stránky modulů leží v core stromu** (`resources/js/Pages/Modules/<Modul>/`), ne uvnitř modulu — Inertia view finder neumí namapovat krátký název na cestu uvnitř modulu. Detail: [`2026-07-20-katalog-jadro.md`](2026-07-20-katalog-jadro.md).
5. **Řazení kategorií je tlačítky ↑/↓**, ne drag&drop podle §16.2 — tažení nejde ovládat klávesnicí (WCAG 2.1.1).
6. **Vyloučení košíku a pokladny z page cache je explicitní hlavička `Cache-Control: private, no-store`**, ne pravidlo na úrovni routy — page cache jako mechanismus ještě neexistuje. Detail: [`2026-07-21-checkout.md`](2026-07-21-checkout.md).

## Známá omezení, na která se narazí dřív než na cokoliv jiného

- **`curl` na subdoménách potřebuje `-k`** — OpenSSL nebere wildcard `*.droidshop` nad jedinou úrovní. Blokuje kontrolní seznam ve `storefront-rendering.md` i Playwright. Oprava = lokální doména `droidshop.test`.
- **Platformní joby musí implementovat `NotTenantAware`** — jinak je tenant-aware fronta tiše zahodí.
- **Routa Pages je provizorně `/stranka/{slug}`**, ne `/{page-slug}` podle pravidla storefrontu. Modul šablony to nevyřešil — catch-all v kořeni by spolkl ostatní routy, takže to čeká na explicitní pořadí registrace routů.
- **Hledání běží přes `LIKE '%term%'` nad `products.search_text`** — index se nepoužije. U desítek tisíc produktů bude potřeba přepsat (fulltext nebo externí index).
- **Page cache podle §15.6 zatím není.** Šablony jsou na ni připravené (žádný osobní obsah v HTML), ale TTFB nechrání nic.
- **Soft-deleted produkty dál počítají do `storage_mb`** — obrázky zůstávají, aby šel produkt obnovit a staré objednávky ho zobrazily.
- **Kill switch přebíjí i core moduly** — vypnutí core modulu vezme e-shopům základní funkčnost. Je to záměr (nouzová brzda), ne chyba.
- **Stav tenanta se mění bez e-mailu nájemci** — `MailService` už existuje, ale notifikace na změnu stavu na něj zatím není napojená.

## Otevřené chyby

Žádné v `docs/superpowers/errors/`.
