# Vlna 3.3 — cookie lišta a měřicí kódy nájemce — zadání

Datum: 2026-08-05 · Stav: **návrh** · Fáze 2

Navazuje na: [`docs/as-is/2026-08-05-pravni-minimum.md`](../../as-is/2026-08-05-pravni-minimum.md) (vlna 3.2), [`docs/as-is/2026-08-03-page-cache.md`](../../as-is/2026-08-03-page-cache.md) (vlna 3.0)

## Proč

Nájemce dnes nemá jak měřit, odkud mu chodí zákazníci a co se z nich stane. E-shop bez GA4 a Skliku znamená, že provozovatel platí za reklamu naslepo — v Česku je to podmínka prodeje, ne nadstandard.

Měřicí kódy ale nejdou nasadit bez souhlasu: analytické a marketingové cookies vyžadují podle **§ 89 odst. 3 zákona č. 127/2005 Sb.** souhlas **předem**. Zásady cookies z vlny 3.2 to nájemcům výslovně slibují („nástroje pro správu souhlasu připravujeme; do té doby měřicí kódy na platformě nasadit nelze"). Obojí proto musí přijít najednou — lišta bez kódů nemá co blokovat, kódy bez lišty jsou protiprávní.

## Rozsah

### 1. Souhlas se třemi kategoriemi

| Kategorie | Co obsahuje | Souhlas |
|---|---|---|
| Nezbytné | session, XSRF, `cart`, samotný záznam souhlasu | vždy aktivní, nelze vypnout |
| Analytické | GA4 | vyžaduje souhlas |
| Marketingové | Sklik retargeting, Meta Pixel | vyžaduje souhlas |

**Tlačítka „Přijmout vše" a „Odmítnout vše" musí být rovnocenná** — stejná velikost, stejný kontrast, stejná vzdálenost od textu. Nerovnocenná volba znamená, že souhlas není svobodný (EDPB Guidelines 03/2022, dozorová praxe ÚOOÚ). Třetí tlačítko „Nastavení" otevře výběr po kategoriích.

Rozhodnutí se ukládá do cookie `cookie_consent` (JSON: verze, kategorie, čas), životnost **6 měsíců** — po ní se lišta zeptá znovu.

Odvolat souhlas musí jít stejně snadno, jako byl udělen: trvalý odkaz „Nastavení cookies" v patičce.

### 2. Lišta je cache-safe

Cachované HTML nese lištu **vždy**; JS ji podle cookie skryje dřív, než se stránka vykreslí. `PageCachePolicy` se nemění — cookie souhlasu se **nesmí** stát důvodem k obcházení cache, jinak by cache ztratila většinu návštěvníků (stejná úvaha, proč rozhodnutí 3.0 zrušilo cookie `has_cart`).

Návštěvník **bez JS** uvidí lištu i po souhlasu. Přijatelné: bez JS se žádný měřicí kód nespustí, takže lišta tam nic neblokuje. Formulář lišty přesto funguje bez JS (POST → cookie → redirect zpět), aby šel souhlas vůbec vyjádřit.

### 3. Modul `analytics` (base)

Nový modul, `level: base` — stejný argument jako u `feeds`. Nastavení přes generickou obrazovku z vlny 2.10 (`settings_schema`), takže nevzniká vlastní administrace.

| Nástroj | Co se nastavuje | Kategorie |
|---|---|---|
| GA4 | Measurement ID (`G-XXXXXXX`) | analytické |
| Sklik | ID retargetingu, ID konverze | marketingové |
| Meta Pixel | Pixel ID | marketingové |
| Heureka Ověřeno zákazníky | API klíč, přepínač | nezbytné (bez cookies) |

**Konverzní měření** na děkovné stránce: GA4 `purchase`, Sklik konverze, Meta `Purchase`. Ta stránka je `no-store`, takže smí nést hodnotu objednávky přímo — na cachované stránce by to byl únik mezi zákazníky.

### 4. GA4 Consent Mode v2

GA4 se od 2024 v EU bez Consent Mode v2 nespáruje s Google Ads. Implementace: `gtag('consent', 'default', {...'denied'})` **před** načtením gtag.js, po souhlasu `gtag('consent', 'update', {...})`. Kód smí být v HTML vždy; co je gated, je souhlas, ne přítomnost skriptu.

Sklik a Meta Pixel Consent Mode nemají — jejich skripty se do stránky vkládají **až po** souhlasu.

### 5. Heureka Ověřeno zákazníky

Mimo souhlasový režim: neukládá cookies, jen po dokončení objednávky předá Heurece e-mail a obsah košíku, aby mohla poslat dotazník. Právní titul je oprávněný zájem, ne souhlas — ale zákazník o tom musí vědět, takže vzor zásad ochrany údajů z 3.2 dostane odpovídající odstavec.

## Akceptační kritéria

1. Anonymní návštěvník bez cookie souhlasu dostane lištu; „Přijmout vše" i „Odmítnout vše" mají stejnou velikost i kontrast.
2. Bez JS lze souhlas vyjádřit i odmítnout (POST → redirect) a rozhodnutí se uloží.
3. Bez souhlasu **nesmí** v HTML být načten gtag.js, Sklik ani Meta Pixel a nesmí vzniknout jejich cookies.
4. Po souhlasu s analytickými, ale ne marketingovými, běží GA4 a neběží Sklik ani Meta.
5. Stránka s lištou je nadále cachovatelná — druhý požadavek se servíruje z cache a lišta v něm je.
6. Návštěvník, který souhlasil, nedostane novou generaci cache ani nezpůsobí miss.
7. Konverze na děkovné stránce se odešle jen pro kategorie, se kterými návštěvník souhlasil.
8. Nájemce bez vyplněného měřicího ID nemá v HTML žádný měřicí kód, ale lištu ano (nezbytné cookies existují vždy).
9. Odkaz „Nastavení cookies" v patičce otevře znovu výběr a umožní souhlas odvolat.
10. Vypnutý modul `analytics` = žádné kódy, ale lišta zůstává.

## Omezení a rizika

**Lišta patří do jádra, kódy do modulu.** Cookie lišta je právní povinnost každého e-shopu, i toho, který nic neměří — modul, který jde vypnout, ji držet nemůže. Kódy naopak modul jsou. Hranice: jádro renderuje lištu a čte souhlas, modul `analytics` na souhlas reaguje.

**Souhlas jako důkaz.** Cookie je u návštěvníka, takže nájemce nemá čím prokázat, že souhlas dostal. Server-side log souhlasů (IP, čas, verze) je mimo rozsah této vlny — a je otázka, jestli je vůbec žádoucí, protože sám o sobě zpracovává osobní údaje.

**Verze souhlasu.** Cookie nese verzi; změní-li nájemce seznam nástrojů, staré souhlasy by měly propadnout. Vlna zavádí pole, ale automatickou invalidaci při změně nastavení **ne** — dořešit, až bude jasné, jak často nájemci nástroje mění.

**Cizí skripty na storefrontu.** Každý měřicí kód je požadavek na cizí doménu a zpomalení. Načítají se asynchronně a až po souhlasu, ale výkonový cíl (JS bundle < 100 kB gzip, Lighthouse ≥ 90) se týká **našeho** kódu — nájemce, který zapne všechny tři nástroje, si výkon zhorší sám.

## Mimo rozsah

- **Google Tag Manager** — nájemce by přes něj mohl nasadit cokoli, včetně kódu, který obchází souhlas. Vlastní obal kolem GTM by byl jen zdánlivá kontrola.
- **Vlastní HTML kód od nájemce** (libovolný `<script>`) — stored XSS na vlastním e-shopu a nekontrolovatelné z hlediska souhlasu.
- **Server-side tagging**, měření bez cookies, A/B testy.
- **Log souhlasů na serveru.**
- **Cookie lišta v administraci** — admin je `noindex` a jede jen na nezbytných cookies.
