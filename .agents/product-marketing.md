# Product Marketing Context

> Čte ho každý marketing skill z pluginu `marketing-skills@marketingskills` (a `/product-marketing` ho umí aktualizovat).
> **Draft v1 z technické specifikace — čísla, ceny a metriky nejsou ověřené. Před použitím v copy je potvrď.**

**Document version:** v1
**Last updated:** 2026-08-14

## Product Overview
**One-liner:** Pronajmi si vlastní český e-shop — od registrace k první objednávce do 10 minut.
**What it does:** Multi-tenant SaaS platforma. Nájemce si za měsíční poplatek pronajme e-shop, naplní produkty a provozuje ho pod vlastní značkou na subdoméně (`nazev.droidshop.cz`) nebo na vlastní doméně. Platforma dodává software, hosting, aktualizace a napojení na české služby (Zásilkovna, Comgate/GoPay, Heureka, Pohoda/ISDOC).
**Product category:** Hostovaná e-commerce platforma (SaaS)
**Product type:** B2B SaaS, self-service onboarding
**Business model:** Měsíční / roční předplatné (Stripe Billing), tarify **base** a **premium**; funkce se zapínají po modulech podle tarifu.

## Target Audience
**Target companies:** České mikro a malé firmy a živnostníci prodávající fyzické zboží; e-shopy do řádu tisíců objednávek měsíčně; začínající prodejci bez vlastního vývojáře.
**Decision-makers:** Majitel firmy / OSVČ (rozhoduje i platí). Nikdo mezi tím — žádný nákupní proces.
**Primary use case:** Spustit prodejní e-shop bez vývojáře a bez správy serveru.
**Jobs to be done:**
- Začít prodávat online rychle, bez projektu a bez agentury
- Nemít starost o server, zálohy, TLS a bezpečnostní záplaty
- Vystavit doklady správně a dostat je do účetnictví
- Odbavit dopravu a platby tak, jak je český zákazník očekává
**Use cases:**
- První e-shop k existujícímu kamennému nebo Facebook prodeji
- Přechod z předražené nebo přerostlé platformy zpět k jednoduchosti
- Druhý (značkový) e-shop vedle hlavního

## Personas
| Persona | Cares about | Challenge | Value we promise |
|---------|-------------|-----------|------------------|
| OSVČ prodejce | Rychlost spuštění, cena | Neumí technicky nic nastavit | Do 10 minut funkční e-shop |
| Majitel malé firmy | Objednávky, doklady, účetní | Data ručně přepisuje do účetnictví | Export ISDOC / Pohoda XML z krabice |
| Marketér / provozní | Organický traffic, feedy | Předchozí platforma renderovala katalog v JS a neindexovala se | Storefront serverem renderovaný, feedy pro Heureku a Zboží |

## Problems & Pain Points
**Core problem:** Spustit důvěryhodný český e-shop dnes znamená buď drahou agenturu, nebo platformu, která je předražená a přerostlá potřebám malého prodejce.
**Why alternatives fall short:**
- Shoptet a spol. — funkčně napřed, ale cenově a složitostí míří výš
- WooCommerce / open-source — „zdarma", dokud nemusíš platit někoho na aktualizace, zálohy a bezpečnost
- Marketplace (Aukro, Allegro) — cizí značka, cizí zákazník, provize z každé objednávky
**What it costs them:** Peníze za funkce, které nepoužijí, a týdny do spuštění.
**Emotional tension:** „Chci prodávat, ne administrovat platformu."

## Competitive Landscape
**Direct:** Shoptet, Eshop-rychle, Upgates, Webnode e-shop
**Secondary:** WooCommerce / PrestaShop na sdíleném hostingu, Shopify
**Indirect:** Prodej přes Facebook/Instagram, marketplace, žádný e-shop

## Differentiation
**Key differentiators:**
- Modulární architektura — nájemce platí a vidí jen to, co používá
- Storefront renderovaný serverem (Blade SSR) — SEO a rychlost jako výchozí stav, ne příplatek
- Česká realita v základu: DPH podle sazby na produktu, ISDOC a Pohoda XML, Zásilkovna (výdejní místa i doručení na adresu), Comgate/GoPay, Heureka
- Checkout funguje i bez JavaScriptu; veškerá cenová logika na serveru
**How we do it differently:** Vypnutelné moduly místo jednoho velkého balíku funkcí; tarif rozhoduje, co se nájemci zapne.
**Why that's better:** Nižší cena a jednodušší administrace pro malého prodejce, bez stropu při růstu.
**Why customers choose us:** Rychlost spuštění a to, že jim nikdo nemusí nic dolaďovat.

## Objections
| Objection | Response |
|-----------|----------|
| „Neznámá platforma, co když skončí?" | Data nájemce jsou exportovatelná; vlastní doména zůstává jeho |
| „Umí to fakturaci a DPH správně?" | Doklady, sazby DPH na produktu, režim neplátce, export do účetnictví |
| „Půjde napojit Zásilkovna a brána?" | Ano, v základu — výdejní místa, doručení na adresu, Comgate/GoPay |
| „Budu se v tom vyznat?" | Průvodce onboardingem, do 10 minut první produkt a objednávka |

**Anti-persona:** Velký e-shop s vlastním vývojovým týmem, nestandardními integracemi nebo B2B ceníky per zákazník.

## Switching Dynamics
**Push:** Rostoucí měsíční poplatek na stávající platformě, nebo hosting, který je potřeba spravovat.
**Pull:** Nižší cena, rychlé spuštění, české integrace bez doplňků.
**Habit:** Zvyk na stávající administraci, nechuť migrovat produkty.
**Anxiety:** Ztráta SEO při migraci, ztráta objednávkové historie.

## Customer Language
**How they describe the problem:** *(doplnit z reálných rozhovorů — zatím odhad)*
- „Potřebuju e-shop, ale nechci do toho dát sto tisíc"
- „Nemám na to nikoho technického"
**How they describe us:** *(doplnit po prvních zákaznících)*
**Words to use:** e-shop, nájem, spuštění, doprava, doklad, sazba DPH
**Words to avoid:** tenant, multi-tenancy, modul (interně ano, směrem k zákazníkovi ne), deploy
**Glossary:**
| Term | Meaning |
|------|---------|
| Nájemce | Provozovatel e-shopu = náš platící zákazník (navenek říkáme „provozovatel" / „vy") |
| Koncový zákazník | Kdo nakupuje na e-shopu nájemce; s námi nemá smluvní vztah |
| Tarif | base / premium, měsíčně nebo ročně |

## Brand Voice
**Tone:** Moderní, technický, spolehlivý. Bez marketingového nafukování.
**Style:** Konkrétní, krátké věty, česky správně včetně diakritiky. Čísla místo přídavných jmen.
**Personality:** Kolega, který to už jednou postavil a ví, kde to bolí.

## Proof Points
**Metrics:** *(doplnit po spuštění — čas do první objednávky, počet aktivních e-shopů)*
**Customers:** *(zatím žádní — platforma před nasazením)*
**Testimonials:** *(zatím žádné)*
**Value themes:**
| Theme | Proof |
|-------|-------|
| Rychlost spuštění | Cíl: 10 minut od registrace k první objednávce |
| SEO jako výchozí stav | Storefront Blade SSR, JSON-LD, sitemapy, feedy, page cache |
| České účetnictví | ISDOC (validovaný proti oficiálnímu XSD) + Pohoda XML |

## Goals
**Business goal:** První platící nájemci po nasazení na VPS.
**Conversion action:** Registrace a dokončený onboarding (spuštěný e-shop na subdoméně).
**Current metrics:** Žádné — produkt před nasazením.

## Changelog
*Newest first. One line per revision: what changed and why.*
- v1 (2026-08-14) — Draft odvozený z `CLAUDE.md` a produktové specifikace; ceny, metriky a jazyk zákazníka nejsou ověřené.
