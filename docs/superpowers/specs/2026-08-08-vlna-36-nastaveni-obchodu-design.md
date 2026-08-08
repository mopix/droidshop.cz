# Vlna 3.6 — nastavení obchodu pro nájemce — zadání

Datum: 2026-08-08 · Stav: **návrh** · Fáze 2

Zadání: konverzace 2026-08-08 (vlastník doložil pět snímků ze Shoptetu a Eshop-rychle).

## Proč

Superadmin vidí o e-shopu spoustu údajů, ale **nájemce si jich většinu nemá kde nastavit**. Dnes má pod NASTAVENÍ jen Doménu a Vzhled; fakturační profil existuje, ale není ani v menu.

Konkrétně nejde nastavit:

- **slogan a časové pásmo** — e-shop běží na serverovém čase a v hlavičce má jen název,
- **kontakt na provozovatele** — zákazník nemá kam napsat ani zavolat, sociální sítě nikde,
- **title a description homepage** — do vyhledávače jde jen automaticky odvozený název,
- **zaheslování e-shopu** — rozpracovaný katalog je celý veřejný a indexovatelný od první minuty.

## Rozsah

Čtyři nové obrazovky pod NASTAVENÍ, plus fakturační profil doplněný do menu.

### 1. Obchod (`/admin/nastaveni/obchod`)

| Pole | Poznámka |
|---|---|
| Název obchodu | dnes `tenants.name`, jen se zpřístupní |
| Slogan / podtitul | vedle názvu v hlavičce a v `og:site_name` |
| Časové pásmo | výchozí `Europe/Prague`; ovlivňuje čas na dokladech i v administraci |
| Formát data, formát času | výběr z několika, ne volný text |

### 2. Kontakty (`/admin/nastaveni/kontakty`)

E-mail, telefon, adresa provozovny (ulice, město, PSČ, země) a odkazy na Facebook, Instagram, X, YouTube, TikTok.

**Vyplněné = zobrazí se** (rozhodnutí vlastníka). Žádné přepínače „kde se to objeví": tabulka s desítkami zaškrtávátek je kontrola, kterou většina nájemců nikdy nepoužije, a každý přepínač navíc je způsob, jak si e-shop rozbít.

Údaje se objeví v patičce storefrontu; adresa a otevírací doba **nenahrazují** stránku Kontakt, kterou má nájemce z vlny 3.2 — doplňují ji.

### 3. SEO (`/admin/nastaveni/seo`)

Title a description homepage, obrázek pro sdílení (OG), přepínač `noindex` pro celý e-shop.

Prázdný title a description **degradují na dnešní chování** (název obchodu), ne na prázdno.

### 4. Zobrazení (`/admin/nastaveni/zobrazeni`)

| Nastavení | Chování |
|---|---|
| Skrývat prázdné kategorie | kategorie bez produktů zmizí z navigace |
| Text u prázdného hledání | vlastní věta místo výchozí |
| Kontaktní box v patičce | zapnout/vypnout blok s kontakty ze sekce 2 |
| **Zaheslovat e-shop** | celý storefront za heslem, `noindex` napevno |

### Zaheslování — co to obnáší

Vlastník ho výslovně chce. Není to zaškrtávátko:

- Storefront za heslem musí odpovídat i **robotům** stránkou s formulářem, ne katalogem.
- **Page cache se pro zamčený e-shop vypíná.** Uložená stránka by se dala servírovat i bez odemčení, což by zámek obešlo.
- Odemčení drží **cookie**, ne session vázaná na zákazníka — zamčený e-shop nemá zákazníky.
- Administrace, webhooky plateb a dopravců, sitemap a feedy zůstávají **mimo zámek**: zamčený e-shop, který přestane přijímat notifikaci o zaplacení, by tiše ztratil objednávky.

## Co ze snímků vědomě nepřebírám

| Vynecháno | Proč |
|---|---|
| Sortiment, formát adresy | Shoptet je používá pro vlastní doporučovací logiku; u nás by to bylo pole, které nic nedělá |
| Peppol ID, spisová značka | fakturační pole pro firmy, které je potřebují; doplnit až si o ně někdo řekne |
| Diskuze u produktů a článků, IP adresy v diskuzi | recenze ani blog v platformě nejsou |
| Rozvržení úvodní strany (články vs. e-shop) | řeší bloková homepage z vlny 2.3, a to podrobněji |
| Měny, jazyky, daně jako obrazovky | vícejazyčnost je post-MVP; sazby DPH už jsou v jádře |
| Captcha | nemáme veřejný formulář, který by ji potřeboval — recenze ani diskuze zatím nejsou |
| Kontaktní box v hlavičce a v objednávkovém procesu | patička stačí jako první krok; hlavička je nejcennější místo na stránce a nemá se zaplňovat kontaktem, dokud si o to někdo neřekne |
| Pověřenec GDPR, osoba odpovědná za OÚ | patří do textu zásad, který si nájemce píše sám (vlna 3.2), ne do dvou polí, ze kterých se nikam nepropíšou |

## Akceptační kritéria

1. Všech pět položek je v menu pod NASTAVENÍ a otevře se.
2. Vyplněný slogan se objeví na storefrontu; prázdný nic nerozbije.
3. Vyplněný kontakt a sociální síť se objeví v patičce; nevyplněné se nezobrazí vůbec (ne prázdný řádek).
4. Vlastní title/description homepage jde do `<title>` a `<meta name="description">`; prázdné degraduje na název obchodu.
5. `noindex` přepínač se projeví v `<meta name="robots">` **i v `robots.txt`**.
6. Zaheslovaný e-shop vrátí formulář místo katalogu, správné heslo odemkne, špatné ne.
7. Zaheslovaný e-shop **neuloží stránku do page cache** a nikdy neservíruje uloženou stránku bez odemčení.
8. Webhook platby projde i na zaheslovaném e-shopu.
9. Změna nastavení se projeví okamžitě, ne až po vypršení cache.
10. Časové pásmo se projeví na datu objednávky v administraci.

## Omezení a rizika

**Kam nastavení uložit.** Existují tři místa: sloupce `tenants` (identita), `tenant_theme` (vzhled) a `settings` (per modul, vlna 2.10). Nic z toho nesedí: tohle je nastavení e-shopu jako celku, ne modulu — a `SettingsService` klíčuje podle modulu, takže by se muselo vymyslet, který modul „vlastní" časové pásmo. Vzniká proto vlastní tabulka.

**Zaheslování je bezpečnostní prvek.** Heslo se ukládá hashované, ověřuje se v konstantním čase a pokus se omezuje počtem — jinak je to jen dekorace.

**Page cache.** Sedm z těchto nastavení mění vyrenderované HTML, takže každý zápis musí zvednout generační čítač. Bez toho by nájemce změnil slogan a viděl ho až za deset minut.

## Mimo rozsah

- Vícejazyčnost čehokoli z toho.
- Otevírací doba jako strukturovaný údaj (dny, hodiny) — zatím volný text.
- Kontaktní box v hlavičce a v košíku.
- Vlastní `robots.txt` psaný nájemcem.
