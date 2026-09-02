# Changelog

Historie verzí projektu DroidShop.cz. Aktuální verze je vždy v souboru [`VERSION`](VERSION).

Formát: [Keep a Changelog](https://keepachangelog.com/), verzování [SemVer](https://semver.org/).
Pravidla: [`.claude/skills/versioning/SKILL.md`](.claude/skills/versioning/SKILL.md).

- **patch** (`+0.0.1`) — každý commit (až bude `pre-commit` hook)
- **minor** (`+0.1.0`) — start nového implementačního plánu
- **major** (`+1.0.0`) — jen na explicitní pokyn

> CHANGELOG vede milníky (minor/major). Detail patchů je v `git log`.

## [0.48.0] – 2026-09-03

**Uzavření technických mezer.** Plán `docs/superpowers/plans/2026-09-02-uzavreni-technickych-mezer.md`, as-is `docs/as-is/2026-09-03-export-a-odinstalace.md`. Bez migrace; `npm run build` kvůli dvěma obrazovkám.

### Odinstalace modulu je opt-in, ne schopnost
Deaktivace data **zachovává** a zůstává výchozí, protože je vratná. Odinstalace je to druhé a nevratné — a mapa cizích klíčů ukázala, že většina modulů ji mít nesmí: `documents.order_id` míří do `orders` a daňové doklady se archivují deset let. Modul, který nedeklaruje `ModuleUninstall`, odinstalovat nejde; první dva, které ho deklarují, jsou `discounts` a `feeds`. Seznam tabulek je deklarativní, mazání i transakci vlastní registry — patnáct modulů by jinak každý zvlášť mohlo zapomenout `where tenant_id`.

Export mazaných tabulek se před smazáním **provede**, ne nabídne: „upozornili jsme, ať si to zazálohuje" není cesta zpět.

Z téže mapy vyšla i dobrá zpráva: `order_items` nemá cizí klíč na `products`, objednávky nesou snímek.

### Fulltext podruhé zamítnut a `STATUS.md` už to nebude popírat
Etapa vlny přepsala hledání na FULLTEXT index a byla zrušena. Práce byla zbytečná — `docs/decisions/02` ten přepis zamítá od 2026-08-05 po měření (3,2 ms na base tarifu, 31 ms na stropu premium) a jmenuje i `innodb_ft_min_token_size`, na který pokus narazil znovu. Spustil ji `STATUS.md`, kde hledání stálo jako otevřený dluh se slovy „Přepis = vlna 3.2". **Ten rozpor je skutečná vada** a je opravený: STATUS teď odkazuje na rozhodnutí, místo aby s ním soupeřil.

Pokus přinesl jedno nové zjištění, zapsané jako dodatek: InnoDB neaktualizuje FULLTEXT index do commitu, takže `MATCH` nevidí řádek vložený v transakci, zatímco `LIKE` ano. Celá sada jede na `RefreshDatabase`, takže cena fulltextu není „přepsat dotaz", ale přepsat testovací strategii.

### Nájemce se poprvé dostane ke svým datům
Pojistka 4 z §4.2 chyběla od začátku a spec ji označuje jako nutnou před produkcí. Nájemce nemohl dostat kopii vlastních dat — ani pro žádost podle GDPR, ani při odchodu jinam, ani pro obnovu. Nově `tenant:export`, obrazovka „Export dat" pro majitele e-shopu a tlačítko na detailu nájemce v superadminu. Archiv je ZIP: `manifest.json`, `data/<tabulka>.json` a soubory z obou disků.

Co je tenantovo, se odvozuje **ze schématu** (tabulka se sloupcem `tenant_id`), ne z traitu `BelongsToTenant`. Trait byl první nápad a byl by špatně: `ShopSettings` a `TenantTheme` ho záměrně nemají, pivoty `product_category` a `shipping_method_payment_method` nemají model vůbec. Trait najde 36 modelů, schéma 43 tabulek — sedm by z exportu vypadlo, aniž by to archiv přiznal.

Manifest pojmenovává, co v archivu není: `customer_tokens` jsou živé přihlašovací údaje a hashe hesel jsou terč na offline lámání, který neodpovídá na žádnou otázku o tom, jaká data držíme.

### `jobs_log` má po roce prvního zapisovatele
Tabulka existuje od vlny 0.x a nikdo do ní nepsal ani z ní nečetl. Export je přesně ten dlouhý job, který podle §4.4 má nájemce vidět.

### Dvě chyby, které našly testy
Adresář `exports/` leží v tenantově prefixu, takže export zabalil předchozí export do nového — 5,9 MB se na druhý běh změnilo na 12 MB a dál by se to zdvojovalo. Stejný prefix se navíc počítal do kvóty, přestože zápis limit záměrně obchází; nájemce nejblíž limitu by byl ten, kdo se ke svým datům nedostane.

### Odkaz jako klíč od databáze
Stažení archivu je za přihlášením, ne za podepsanou URL. Platformní routa `storage.private` má jen `signed`, tedy žádnou autentizaci — u jedné faktury fér obchod, u archivu se všemi zákazníky e-shopu ne. Odkaz uniká historií prohlížeče, hlavičkou `Referer` i sdíleným screenshotem.

### Otevřený redirect v obsahu psaném nájemcem
`HtmlSanitizer::isSafeUrl` považoval za bezpečné cokoli začínající `/`, včetně `//evil.com` — prohlížeč to vyhodnotí jako `https://evil.com`. `BlockUrl::isSafe` ten guard měl, sanitizer ho nikdy nedostal; rozhodnutí se přestěhovalo do `App\Core\Html\UrlGuard`, který volají oba a zrcadlí ho editor. Duplikace byla příčina, ne detail.

Původní návrh opravy propouštěl tabulátor: parser maže tab, CR a LF kdekoli v URL, takže `/<TAB>/evil.com` dorazí jako `//evil.com`. Reálně přitom unikal jen tvar `//evil.com` — zbylé dvě varianty tlumilo enkódování v `DOMDocument`, tedy vedlejší efekt serializace, ne guard.

## [0.47.0] – 2026-08-14

**Úklid agentského kontextu.** Plán `docs/superpowers/plans/2026-08-14-claude-md-diet.md`. Žádná změna produkčního kódu, žádná migrace, žádný build.

### `CLAUDE.md` stál 39 000 tokenů při každé session
Sekce Rozhodnutí narostla na 125 KB, sekce Nuance projektu na 17,6 KB — dohromady 91 % souboru, který se agentovi načítá do kontextu při každém spuštění, ať dělá cokoli. Drtivá většina těch 206 položek popisuje uzavřené vlny, které při běžné práci nikdo nepotřebuje mít před očima.

Rozhodnutí se přestěhovala do `docs/decisions/`, rozdělená po deseti oblastech a **beze změny textu** — jde o přemístění, ne redukci. Ověřeno mechanicky: seřazený seznam položek z původního souboru je identický se seznamem z nových, byte po bajtu. Prozaický přehled vln je archivovaný v `docs/as-is/2026-08-14-prehled-vln.md`, rovněž beze změny.

`CLAUDE.md` teď nese jen živá pravidla a index, který říká imperativně: *než sáhneš na kód v oblasti, přečti si její soubor*. To je podstatné — většina těch položek nepopisuje preferenci, ale past, do které se v projektu už jednou šláplo.

| | Před | Po |
|---|---|---|
| `CLAUDE.md` | 155 507 B | 15 544 B |
| Trvalý kontext (`CLAUDE.md` + `.claude/rules/`) | ~169 000 B | 27 787 B |
| Položek rozhodnutí | 206 | 206 |

### Konfigurace popisovala cizí projekt
Skill přístupnosti a agent `a11y-checker` byly celé psané pro **WooShop.cz** — jiný projekt, marketplace s prodejci, moderací a digitálními produkty ke stažení. Agent by tedy auditoval obrazovky, které tu neexistují, a minul by ty, které tu jsou. Přepsané na realitu DroidShopu: dvě UI vrstvy (Blade SSR storefront proti Inertia administraci), bez-JS cesta jako blokující kritérium, kontrast nad brandingem nájemce, cookie lišta s rovnocennými tlačítky, rich text editor.

Stejnou vadou trpěli `ui-engineer`, `backend-engineer` a `qa-expert` — nabízeli SPA větev, Pinia, Fortify a Pest, tedy věci, které v projektu nejsou.

### Mrtvá pravidla a skilly
Smazáno `rules/frontend-spa.md` (načítalo se do kontextu, přestože `resources/app/` neexistuje) a skilly `vue-spa-development`, `pest-testing`, `fortify-auth` — ověřeno, že Fortify není v `composer.lock`, Pest nemá `tests/Pest.php` a SPA závislosti nejsou v `package.json`.

### `settings.json` povoloval WordPress
Allowlist obsahoval `wp plugin list`, `wp theme list`, `wp option get` a `Read(wp-config.php)` na Laravel projektu, zatímco `php artisan`, `pint` a `npm run e2e` v něm chyběly.

## [0.46.0] – 2026-08-12

**Zásilkovna — doručení na adresu.** Uzavření vlny (`docs/as-is/2026-08-11-packeta-home-delivery.md`). 376 PHPUnit testů v dotčených oblastech, 83 E2E včetně nákupu na adresu bez JavaScriptu.

### Zásilka konečně dojede domů
Vlna 2.5 uměla jen výdejní místa, takže zákazník, který chtěl balík domů, ho u žádného e-shopu na této platformě nedostal — a to je většina objednávek. Zásilkovna doručení na adresu zprostředkovává přes partnerské dopravce; nájemce k tomu nepotřebuje novou smlouvu ani nové přihlašovací údaje, jen si v nastavení dopravní metody vybere dopravce, kterého má povoleného.

### Přidání dalšího dopravce už nevyžaduje zásah do pokladny
Tři místa zůstala od vlny 2.5 přidrátovaná k Zásilkovně natvrdo a bez jejich rozpojení by doručení na adresu nemohlo fungovat vůbec: snímek objednávky neměl kam uložit dopravce a hmotnost, vyhledávání výdejních míst hledalo napříč všemi katalogy a pokladna znala jediného dopravce jménem. Tím je splněné akceptační kritérium, které od 2.5 splněné nebylo.

### Objednávku šlo vytvořit, ale nebylo ji odkud podat
Nejzávažnější nález vlny, a našla ho až závěrečná revize celé větve: objednávka na adresu se nikdy neobjevila v expediční frontě, protože dotaz filtroval podle výdejního místa — které u ní z definice neexistuje. Chybělo i tlačítko na detailu objednávky a štítek by se tiskl přes endpoint pro výdejny.

Pět dílčích revizí to minulo, protože všechny testy volaly podací službu přímo. Díra ležela mezi obrazovkami, ne uvnitř kódu.

### Balík, za který by zákazník zaplatil dvakrát
Doručení na adresu je poprvé dvě volání tam, kde výdejní místo mělo jedno. Když první uspěje a druhé selže, zásilka u Zásilkovny existuje, u nás je objednávka neúspěšná — a další pokus vytvoří druhou. U dobírky to znamená kurýra, který vybere peníze dvakrát. Osiřelá zásilka se nově ruší, a když se zrušit nepodaří, její číslo jde do logu i do chybové hlášky, kterou nájemce vidí.

### Z-BOXy zatím ne
Vlna je měla odlišit od poboček. Ukázalo se, že v katalogu vůbec nejsou: voláme feed poboček, boxy má Zásilkovna ve zvláštním feedu na jiném hostu. Odvozovat typ z názvu místa bylo zamítnuto — heuristika nad cizím textem se rozbije při přejmenování, a rozbije se tiše. Popsáno v `docs/future/zasilkovna-z-boxy.md`.

### Deploy
`php artisan migrate` (rozšíření enumu providerů) + `npm run build`. Před ostrým provozem ověřit odpovědi `packetCourierNumber` a `packetCourierLabelPdf` proti reálnému účtu — v testech jsou stubované.

## [0.45.0] – 2026-08-10

**Rich text editor pro HTML pole administrace.** Uzavření vlny (`docs/as-is/2026-08-10-rich-text-editor.md`). 17 E2E scénářů v novém souboru (celkem 81), server nezměněn.

### Konec psaní HTML rukou
Popis produktu, obsah statické stránky a textový blok homepage byly holá `<textarea>` s nápovědou, které značky jsou povolené. Nájemce je provozovatel e-shopu, ne autor HTML — a u vzorů VOP z vlny 3.2 to byla práce na tisíce znaků, ve které se markery `[DOPLŇTE …]` mezi značkami ztrácely.

Nově jedna sdílená komponenta nad Tiptapem: tučné, kurzíva, podtržení, nadpisy H2–H4, seznamy, citace, odkaz a tabulky. Bez vkládání obrázků a bez zdrojového HTML (rozhodnutí vlastníka).

### Co editor nabídne, to server neumaže — a naopak
Schéma editoru je ruční zrcadlo allowlistu `HtmlSanitizer`. Tlačítko, jehož výstup se při uložení zahodí, je lež o tom, co e-shop umí. Opačný směr je ale horší a míň vidět: Tiptap zahazuje uzly, které nezná, takže popis nesoucí obrázek nebo odkaz by o ně přišel už tím, že se pole otevře. Obrázky proto schéma zná, přestože je editor neumí vložit.

`HtmlSanitizer` se nezměnil a zůstává jedinou autoritou nad tím, co se uloží.

### Tři chyby, které by se jinak dostaly ven
Stock Tiptap přidával `target="_blank"` **každému** odkazu, kterého se editor dotkl — včetně interního odkazu na zásady ochrany údajů ve vzoru VOP. Nájemcovy vlastní podmínky by posílaly zákazníka na jeho vlastní stránku do nového okna.

`aria-label` seděl na `<div>` bez role, což ARIA zakazuje — odečítač by editační plochu nepojmenoval vůbec.

A schéma bez odkazového marku by existující `<a>` zahodilo při otevření pole.

### Čtyři testy, které nemohly selhat
Uložení formuláře bez editace posílá hodnotu z Inertia props, která nikdy neprojde přes `getHTML()`. Tři testy „obsah přežije uložení" na tom stály a prošly by i se schématem, které tabulky a obrázky zahazuje. Čtvrtý ověřoval varování o nedoplněných místech na šabloně, která je obsahuje už při načtení.

Všechny přepsané a s doloženým červeným během. Zelený a slepý test jsou zvenčí k nerozeznání — poučení z vlny 3.4, tady zopakované čtyřikrát.

### Nález mimo rozsah
`HtmlSanitizer::isSafeUrl` propouští protokolově relativní `//evil.com` v odkazu psaném nájemcem, tedy open redirect maskovaný jako interní cesta. Server byl v této vlně zmrazený, nález je zapsaný v `security_warnings.md` i s návrhem opravy.

### Deploy
`npm run build`. Žádná migrace.

## [0.44.0] – 2026-08-09

**Fáze 2 / vlna 3.9 — záložka Ceny na kartě produktu.** Uzavření vlny (`docs/as-is/2026-08-09-zalozka-ceny.md`). 3 nové E2E scénáře (celkem 46), 7 nových PHPUnit testů.

### Ceny v pořadí, v jakém se o nich přemýšlí
Tři sekce pod sebou — Prodejní cena, Nákupní cena, Akce — a v každé **bez DPH → daň (sazba) → s DPH**. Neplátce DPH vidí v každé sekci jediné pole s částkou.

### Sleva jde zadat procentem
Procento se **ukládá**: když nájemce zdraží, sleva zůstane dvacetiprocentní místo aby se tiše proměnila na dvanáctiprocentní. Zadaná částka ale vyhrává a procento zahodí — ručně napsaná částka je vlastní pokyn, a přepočítávat z procenta při každém uložení by cenu posouvalo pokaždé, kdy někdo otevře formulář a stiskne Uložit. Rozsah 1–99 %: sto procent je „zdarma", což je jiný nástroj.

### Výpis produktů jako pracovní plocha (v0.44.11)
Slevy se přesunuly z MODULY do PRODUKTY. Ve výpisu je stav rozbalovací seznam, který se ukládá hned při změně; pod názvem produktu je krátký popis místo kategorií, kategorie mají vlastní sloupec se štítky, a přibyl sloupec AKCE s ikonami pro úpravu a smazání (do koše, s potvrzením). Řádek je jemně podbarvený podle stavu — modrý koncept, zelený aktivní, červený skrytý.

Podbarvení je jen pomůcka pro rychlé procházení: stav je pořád i slovy ve svém sloupci, protože barva sama o sobě nic neříká tomu, kdo ji nevidí.

### EAN varuje místo blokování (v0.44.9)
Poslední číslice čárového kódu se dopočítává z těch předchozích, takže vymyšlené číslo skoro nikdy neprojde — a produkt se kvůli tomu nedal uložit. Nově pole odmítne jen to, co čárový kód být nemůže (písmena, víc než čtrnáct číslic); zbytek se uloží a formulář rovnou při psaní řekne, jestli je kód platný: u 7 nebo 12 číslic nabídne chybějící kontrolní číslici, u plné délky pojmenuje tu správnou.

Do feedů pro Heureku a Zboží.cz neplatný kód **neodejde**. Právě tam totiž škodí: porovnávač na něj páruje, takže vymyšlené číslo se buď nespáruje, nebo přilepí produkt k cizí nabídce.

### Miniatura ve výpisu a oprava URL souborů (v0.44.6)
Výpis produktů má před názvem miniaturu hlavního obrázku, která se při najetí myší i při zaostření klávesnicí zvětší.

Při tom se ukázalo, že URL nahraných souborů se stavěla z `APP_URL` — tedy obrázky na vlastní doméně nájemce ukazovaly na platformní host. Nově jsou relativní; feedy a `og:image` si říkají o absolutní tvar. Tím se vysvětlila i záhada z vlny 3.8: „padání dev serveru“ v E2E byl Chromium otevírající TLS spojení na prostý HTTP server kvůli `https://` adrese obrázku. Uříznuté testy (nahrání přetažením, řazení obrázků) jsou zpátky.

Axe pak ve výpisu našlo skutečnou starší vadu: zašedlé popisky stránkování měly kontrast 2,8:1.

### Sloučené cenové sloupce a živý přepočet (v0.44.3)
Výpis dostal EAN a každá dvojice cen se složila do jednoho sloupce — čistá nahoře šedě, hrubá pod ní. Na detailu se obě poloviny hýbou spolu, jak se do nich píše. Prohlížeč ale jen ukazuje: která polovina se editovala, jde na server jako `price_source` a převod se dělá z ní, protože „to druhé pole je prázdné" už neříká, co bylo myšleno.

### Výpis produktů má cenové sloupce
`/admin/m/products` nesl jediný údaj „Cena s DPH", který neplátci tvrdil něco nepravdivého a nikomu neřekl, za kolik se produkt nakupuje ani za kolik se právě prodává v akci. Plátce teď čte Nákupní cena · Akční cena · Koncová cena bez DPH · Daň (sazba) · Koncová cena (s DPH), neplátce tytéž sloupce bez daňových. Nevyplněná částka je pomlčka, ne 0 Kč. Nákupní cena bez práva `products.costs` vůbec neopustí server.

### Nákupní cena má vlastní sazbu
S volbou „Stejná jako u prodeje". Dodavatel může účtovat jinou sazbu, než nájemce prodává, a přepočet prodejní sazbou hlásí marži, která je tiše špatně.

## [0.43.0] – 2026-08-09

**Fáze 2 / vlna 3.8 — koruny v administraci, rozměry produktu, obrázky.** Uzavření vlny (`docs/as-is/2026-08-09-produkt-a-ceny.md`). 2 nové E2E scénáře (celkem 43), 37 nových PHPUnit testů.

### Ceny se zadávají v korunách
Vnitřní jednotka se protlačila až do formuláře: nájemce prodávající za 1 790 Kč psal `179000`. Napříč celou administrací — produkt, varianty, akce, nákupní cena, doprava, platby, slevy, minimum objednávky. Sloupce zůstávají v haléřích.

Nikdy float na float: `(int) (0.07 * 100)` je 6, ne 7. Prázdné pole není nula — nevyplněná nákupní cena znamená „nevyplněno", ne „zdarma". Sleva se převádí jen u pevné částky; u procentní je hodnota promile a korunový parser by z desetiny slevy udělal desetinásobek košíku.

### Produkt má rozměry
Milimetry, všechny tři nebo žádný. Zákazník je vidí v novém bloku **Parametry** (dosud se technické údaje daly zjistit jen z popisu), dopravce je dostane při podání — ale jen když je zásilka jeden produkt: sečíst tři krabice do jedné sady vnějších rozměrů nejde bez znalosti, jak jsou zabalené, a odhad podaný dopravci rozhoduje o tom, jestli je zásilka nadrozměrná.

### Obrázky jde seřadit a přetáhnout
Endpoint pro řazení existoval od vlny 1.2 a nikdo ho nikdy nezavolal. Tlačítka, ne tažení — pořadí musí jít změnit z klávesnice; plocha na přetažení je doplněk k tlačítku.

Tlačítka Uložit a Smazat produkt se přestala kreslit nad panely obrázků a variant. Patří hlavnímu formuláři a četla se jako součást toho, co bylo pod nimi — právě proto zůstalo „Nastavit jako hlavní" přehlédnuté.

## [0.42.0] – 2026-08-09

**Fáze 2 / vlna 3.7 — plátcovství DPH a čas obchodu.** Uzavření vlny (`docs/as-is/2026-08-09-dph-a-cas.md`). 1 nový E2E scénář (celkem 41), 35 nových PHPUnit testů.

### Neplátce DPH už o dani nikde nemluví
`tenants.vat_payer` řídil od vlny 1.5 doklady, ale nikdy se nedostal do katalogu. Neplátce povinně vybíral sazbu, kterou nemá jak uplatnit, a jeho zákazníkům se na detailu produktu tisklo „s DPH · bez DPH 826 Kč“ — nepravdivý údaj o cizím daňovém postavení na veřejné stránce.

Nově jedno místo (`VatMode`), které čte administrace, storefront i košík: neplátce nevidí sazbu ani cenu bez DPH v administraci, na detailu, ve výpisu ani v rozpisu pokladny. Uložená sazba mu zůstává, aby registrace k DPH později nechala katalog dávat smysl.

### Plátce může zadat cenu bez DPH
Velkoobchodní ceníky jsou bez daně. Převod dělá server přes `TaxRate`, nikdy JavaScript — sazba spočítaná v JS a v PHP zaokrouhlí tentýž haléř jinak dost často na to, aby nájemce viděl cenu měnit se při uložení. Když přijdou obě pole, rozhoduje cena s DPH.

### Sazby DPH konečně jde spravovat
`/superadmin/dph`. Platformní, ne per nájemce: sazba je zákon, ne volba obchodníka. Sazbu, kterou někdo používá, nelze smazat — doklad snímkuje procento, ale produkt by ukazoval na řádek, který zmizel. Změna procenta **nesahá na vystavené doklady**.

### Doplněno v v0.42.2
Cena bez DPH i u variant produktu (převod přes sazbu produktu — varianta vlastní sazbu nemá). CSV export DPH se neplátci nenabízí: bez registrace není co podat. E-maily formát data nepotřebují, žádná šablona ho netiskne; superadmin zůstává na české locale vědomě, platformní konzole není e-shop nájemce.

### Uzavřen dluh z vlny 3.6
`ShopClock` používá nastavené časové pásmo a formát v administraci objednávek, v účtu zákazníka a ve třech PDF. Objednávka ve 23:30 UTC se v Praze konečně čte jako druhý den. `DATE` sloupce (DUZP, splatnost) se formátují, ale neposouvají — posun o den zpět není detail zobrazení, ale jiné zdaňovací období. Strojové formáty zůstávají `Y-m-d`.

## [0.41.0] – 2026-08-08

**Fáze 2 / vlna 3.6 — nastavení obchodu pro nájemce.** Uzavření vlny (`docs/as-is/2026-08-08-nastaveni-obchodu.md`). 7 nových E2E scénářů (celkem 39), 55 nových PHPUnit testů.

### Čtyři obrazovky pod NASTAVENÍ
Obchod (název, slogan, časové pásmo, formáty), Kontakty (e-mail, telefon, adresa, sociální sítě), SEO (titulek a popis úvodní stránky, obrázek pro sdílení, `noindex`) a Zobrazení (skrývání prázdných kategorií, text prázdného hledání, kontakty v patičce, zaheslování). Do menu přibyl i fakturační profil — dosud k němu vedl jen banner, který nájemce jednou odklikl a už ho nenašel.

Vyplněné se zobrazí, nevyplněné ne. Žádné přepínače „kde se to objeví“: tabulka zaškrtávátek je ovládání, které většina nájemců nikdy nepoužije, a každé z nich je způsob, jak si rozbít patičku.

### Vlastní tabulka, ne `settings`
`tenants` je platformní záznam o zákazníkovi, `tenant_theme` branding čtený na každém requestu a `settings` klíčuje **podle modulu** — muselo by se vymyslet, který modul „vlastní“ časové pásmo. Nájemce bez řádku dostane výchozí hodnoty, ne `null`; každý zápis zvedne všechny tři generace page cache.

### `noindex` na obou místech
Přepínač jde do meta tagu **i** do `robots.txt`. Jedno bez druhého je poloviční zákaz: crawler, který stránku nestáhne, meta tag nepřečte, a crawler, který `robots.txt` ignoruje, tag přečte. S přepínačem konkrétní stránky se slučuje, nikdy nepřepisuje — košík zůstane `noindex`.

### Zaheslování e-shopu
Middleware ve `web` skupině před page cache, takže odmítnutý požadavek se k cache vůbec nedostane a stránka uložená před zamčením se po něm neservíruje. Heslo hashované, ověřované `Hash::check`, pokusy omezené počtem. Administrace a **webhooky plateb a dopravců** zůstávají mimo zámek: zamčený e-shop, který přestane přijímat „objednávka je zaplacená“, ztratí platbu tiše.

Vlajka zámku je zrcadlená na `tenants` — čtení ze `shop_settings` stálo dva dotazy na každý cache hit a shodilo rozpočet dotazů z vlny 3.0.

### Skrývání prázdných kategorií se ptá na celou větev
Běžný katalog má všechno v listech; počítat jen vlastní produkty kořene by smazalo celé horní menu. E-shop bez publikovaných produktů si menu nechá celé — schovat všechno vypadá jako rozbitý e-shop, ne prázdný.

## [0.40.0] – 2026-08-08

**Fáze 2 / vlna 3.5 — layout administrace.** Uzavření vlny (`docs/as-is/2026-08-08-admin-layout.md`). 10 nových E2E scénářů (celkem 32), 15 nových PHPUnit testů.

### Administrace na celou šířku, menu vlevo
`max-w-7xl` pryč. Levé menu běží přes celou výšku a je rozdělené do čtyř kategorií — PRODUKTY, OBJEDNÁVKY, MODULY, NASTAVENÍ. Nadpisy jsou tlačítka s `aria-expanded`, ne odkazy (vlastní stránku nemají), ve výchozím stavu sbalená; sekce s aktuální stránkou se otevře sama a co si uživatel rozbalí navíc, přežije přechod jinam.

Pod `lg` je menu drawer s hamburgerem, na desktopu se dá sbalit na ikony. Profil a Odhlásit jsou dole v menu i v horním panelu.

### Menu se dál staví z manifestů
Manifest nově říká `group`; kategorie a jejich pořadí drží jádro, protože menu řazené podle pořadí instalace modulů by se přeskládalo při každém zapnutí. Vadná skupina padá při `modules:sync`. Vypnutý modul dál nenechá viset odkaz do 404.

### `/admin` má konečně nástěnku
Dosud přesměrovával na první položku menu, takže vlastník přistál na výpisu produktů bez ponětí, jak si e-shop stojí. Nová obrazovka ukazuje čtyři čísla za 30 dní, využití tarifu a rychlé odkazy — vše jedním agregačním dotazem.

### Superadmin: stejné rozvržení, tmavý panel zůstal
Sjednoceno je rozvržení, ne barva: záměna platformní konzole s administrací nájemce je způsob, jak pozastavit špatný e-shop.

### Co odhalily testy
Zavřený mobilní drawer zůstával v pořadí tabulátoru a odečítač ho četl (opraveno `invisible`). `Route::has()` je pravda i pro modul, který e-shop nezapnul — odkaz by vedl na 404. A axe našel zděděný nedostatečný kontrast (2,53 : 1) na stránce „Moje e-shopy", jakmile přešla pod nový layout.

## [0.39.0] – 2026-08-06

**Fáze 2 / vlna 3.4 — E2E testy v prohlížeči (Playwright).** Uzavření vlny (`docs/as-is/2026-08-06-e2e-playwright.md`). 22 E2E testů, tři běhy po sobě zelené.

### Poprvé něco spouští JavaScript
Projekt měl 2003 zelených testů a ani jeden nespouštěl skripty. Tři závazné vlastnosti tím zůstávaly neověřené: že měřicí kódy respektují souhlas (3.3), že checkout funguje bez JS (§16.3) a že storefront splňuje WCAG 2.2 AA.

### Obě premisy vlny neplatily
V `STATUS.md` od vlny 1.x viselo, že Playwright blokuje omezení certifikátu — týká se `curl` přes HTTPS, ne headless prohlížeče na HTTP. Plán proto stavěl na samostatné `droidshop.test`; ověřeno, že ani ta není potřeba, protože Chromium explicitní `http://` URL s portem neupgraduje. Sada jede na `obchod.droidshop` a nevyžaduje žádný nový záznam v `/etc/hosts`.

### Testy, o kterých víme, že umí selhat
Gate souhlasu byl dočasně porušen, aby se ověřilo, že sada zčervená. Zčervenala — ale jiné testy, než plán čekal: nosné jsou scénáře **odmítnutí**, protože bez zaznamenaného rozhodnutí se snippet nespustí vůbec. Totéž u axe: `axe-sanity` podstrčí obrázek bez `alt`, protože zelený a slepý audit jsou zvenčí k nerozeznání.

### Nálezy
Bez JS je checkout o krok delší (platby závisí na zvolené dopravě) — funkční, jen to dosud nikdo neviděl. Demo neseeduje jedinou variantu, takže si scénář produkt zakládá sám. Axe nenašel žádné porušení `critical` ani `serious` na sedmi stránkách.

## [0.38.0] – 2026-08-05

**Fáze 2 / vlna 3.3 — cookie lišta a měřicí kódy nájemce.** Uzavření vlny (`docs/as-is/2026-08-05-cookie-lista-mereni.md`). 49 nových testů; celkem 2003 zelených.

### Souhlas se třemi kategoriemi
Nezbytné (vždy), analytické, marketingové. Tlačítka „Přijmout vše" a „Odmítnout vše" mají **shodné třídy** a hlídá to test — nerovnocenná volba znamená, že souhlas není svobodný, a okem se to v review neuhlídá. Funguje bez JavaScriptu: prostý form POST a redirect. Lišta žije v jádře (`app/Core/Consent/`), protože je to povinnost i pro e-shop, který nic neměří.

### Měření bez ztráty page cache
Server renderuje **konfiguraci, nikdy rozhodnutí**: měřicí id jsou per tenant a smějí do cachovaného HTML, souhlas je per návštěvník a nesmí. Blade `@if` na souhlasu by z cachované stránky udělal roznašeč cizího rozhodnutí — táž třída chyby, kvůli které 3.0 zrušila cookie `has_cart`. Test asertuje, že HTML je byte-identické pro nerozhodnutého, souhlasícího i odmítajícího.

### GA4 se nenačítá s „denied"
Consent Mode v2 to dovoluje a Google to doporučuje, ale požadavek stejně dorazí a nese IP adresu návštěvníka — a právě požadavek před souhlasem ePrivacy zakazuje. Volání `consent default denied` přesto běží první, protože bez něj se GA4 v EU nespáruje s Google Ads.

### Nový base modul `analytics`
GA4, Sklik (retargeting i konverze), Meta Pixel a Heureka Ověřeno zákazníky. Ids se validují **tvarem**, ne jen jako řetězec — typo měří do prázdna a nájemce to zjistí za měsíc. Heureka stojí mimo souhlas (neukládá nic do prohlížeče, titulem je oprávněný zájem) a vzor zásad z 3.2 ji nově jmenuje.

### `SettingsField` umí credentials
Tabulka `settings` ukládá prostý JSON, takže Heureka klíč dostal příznak `secret`: šifrování, maskování v administraci, keep-on-update. Dešifrování selhává do prázdna, ne do ciphertextu — po rotaci `APP_KEY` by se jinak odeslal třetí straně jako by to byl klíč.

## [0.37.0] – 2026-08-05

**Fáze 2 / vlna 3.2 — právní minimum platformy.** Uzavření vlny (`docs/as-is/2026-08-05-pravni-minimum.md`). 41 nových testů; celkem 1954 zelených.

### Čtyři právní dokumenty
`docs/legal/` bylo prázdné. Vznikly VOP platformy, zásady zpracování osobních údajů, **zpracovatelská smlouva podle čl. 28 GDPR** a zásady cookies — jako Markdown i jako stránky pod `/pravni/*` (Blade SSR, čitelné před registrací a bez JS). Dvojí GDPR role je v nich oddělená schválně: vůči nájemci jsme správce, vůči jeho zákazníkům zpracovatel. Drafty jsou bez právní revize (rozhodnutí vlastníka), místa vyžadující právníka nesou marker `K PRÁVNÍ REVIZI`.

Prefix `/pravni/` není kosmetika: jednosegmentová platformní routa by na hostu nájemce zastínila jeho vlastní stránku, protože `RequirePlatformHost` 404uje až po matchi a fallback z vlny 3.1 by se neuplatnil — a `Lifecycle` seeduje `ochrana-osobnich-udaju` každému e-shopu.

### Prokazatelný souhlas nájemce
Registrace nezaznamenávala nic. Nově `users.terms_accepted_at` + `users.terms_version`, validované server-side: důkaz, který klient může přeskočit, není důkaz. Registrační formulář byl dodnes Breeze default v angličtině — počeštěn.

### Vzory právních stránek a editor
Nový e-shop dostával tři **prázdné** nepublikované stránky. Nově nesou vzor s viditelnými `[DOPLŇTE …]` a varováním, že nejde o právní radu. Zároveň musel vzniknout **editor stránek**: modul `pages` byl read-only, takže vzory by nešlo doplnit ani publikovat a celá vlna by byla inertní. Zápis jde přes `PageWriter` (sanitizace při zápisu, unikátní slug, 301 při přejmenování).

### Vyhledávání: přepis zrušen po měření
`LIKE '%term%'` běží 3,2 ms na base tarifu a 31 ms na stropu premium — `tenant_id` scope zúží scan na katalog jednoho nájemce a výsledek se od 3.0 cachuje. FULLTEXT by přinesl regresi kvality (celá slova místo substringu, „TV" pod minimální délkou tokenu) za úsporu, kterou nikdo nepozná.

## [0.36.0] – 2026-08-05

**Fáze 2 / vlna 3.1 — technické dluhy.** Uzavření vlny (`docs/as-is/2026-08-05-technicke-dluhy.md`). 35 nových testů; celkem 1913 zelených.

### Přepnutí nájemce má jednu cestu
`TenantContext::set()` dostal ve vlně 3.0 obezličku kolem short-circuitu `spatie/laravel-multitenancy`, ale `runAs()` a `runWithoutTenant()` volaly `makeCurrent()` napřímo — díra tedy zůstala otevřená pro každého volajícího, který přes ně přepíná: superadmin změna stavu, oba Stripe handlery, lifecycle sweeper, `TenantProvisioner`, `AuditLog`. Předaný čerstvě načtený model téhož nájemce se nesvázal a callback četl atributy staré instance.

### Změna dopravy invaliduje feed
Oba feedy tisknou blok `DELIVERY` per způsob dopravy, ale `ShippingMethod` chyběl v mapě `PageCacheObserver` — přejmenovaný nebo zdražený dopravce zůstal v Heurece až hodinu, tedy cena dopravy, kterou e-shop neúčtuje.

### Nájemce se dozví o změně stavu svého e-shopu
Poštu posílal jen lifecycle sweeper ze svých dvou míst; superadmin pozastavení i selhaná platba přes Stripe byly němé. `Tenant::changeStatus()` teď dispatchne `TenantStatusChanged` (odloženě přes `DB::afterCommit`, protože Stripe handler mění stav uvnitř transakce s idempotenčním claimem) a jediný listener mapuje **oba konce** přechodu na zprávu: `trial→past_due` je expirace trialu, cokoli jiného`→past_due` je selhaná platba. Tři nové zprávy (selhaná platba, obnovení, čeká na smazání); sweeper svá vlastní odeslání ztratil.

### Statické stránky na `/{page-slug}`
Uzavření odchylky od závazného pravidla storefrontu. Mechanismus je `Route::fallback()`, ne catch-all ani blacklist: Laravel ho řadí za všechny ostatní routy bez ohledu na pořadí registrace, což je load-bearing — `ModuleRouteRegistrar` iteruje `glob()` abecedně, takže `pages` se registruje před `products` i `storefront`. Controller odmítá cokoli s lomítkem a padá na `RedirectResponder`, takže přejmenované slugy dál 301ují. Stará `/stranka/{slug}` odpovídá 301 bez DB dotazu.

## [0.35.0] – 2026-08-05

**Fáze 2 / vlna 3.0 — page cache storefrontu (§15.6), etapa 1.** Uzavření vlny (`docs/as-is/2026-08-03-page-cache.md`). 96 nových testů v `tests/Feature/PageCache/` a `tests/Unit/PageCache/` (224 assertions).

### Whole-HTML cache pro anonymní GET
Storefront do teď renderoval každý požadavek od nuly — cíl TTFB < 200 ms ze specifikace §8 nechránil nic. Nová jádrová infrastruktura `app/Core/PageCache/` (ne modul — slouží všem storefront modulům) přidává opt-in middleware `page-cache:{dimenze}` za `StartSession`. Klíč `page:{tenant}:{host}:{gen-stamp}:{path}[:{qs-hash}]` s query whitelistem (`razeni`, `skladem`, `page`, `q`); přihlášený zákazník i personál cache vždy obchází; CSRF token se v cache nahrazuje HTML-komentářovou značkou a při servírování vrací čerstvý.

### Invalidace generačními čítači ve sloupcích `tenants`
Spec §15.6 psala tagy, ale ty umí jen Redis, kterou projekt dosud nikde nepoužívá. Tři čítače (`page_gen_catalog/content/theme`) leží přímo na `tenants`, ne v cache store — vystěhovaný čítač by se vrátil na 1 a obživil staré stránky. Bump spouští observer modelů (`Product`, `ProductVariant`, `Category`, `HomepageBlock`, `Page`, `TenantTheme`, …), s explicitními výjimkami tam, kde zápis obchází Eloquent (odpis skladu na hranici skladem/vyprodáno, reorder kategorií). Sjednocuje pod sebe dřívější ad-hoc hodinovou cache sitemap a feedů (2.9) — přecenění se teď projeví okamžitě, ne až po TTL.

### Co zachytilo review
- **`mayStore()` kontrolovala i `Cache-Control: private`** — Symfony razítkuje `no-cache, private` na každou odpověď se session cookie jako framework default, takže by se podle plánu neuložilo vůbec nic. Oprava: kontroluje jen `no-store`.
- **CSRF značka `@@PAGECACHE_CSRF@@` procházela beze změny Blade escapingem i `HtmlSanitizer`** — nájemce, který by ji napsal do popisu produktu, by dostal živý token náhodného návštěvníka vlepený do svého obsahu. Tvar změněn na `<!--PAGECACHE_CSRF-->`, který sanitizace/escaping vždy pozmění.
- **404 na neexistující cestě middleware routy nikdy neuvidí** — routing vyhodí výjimku dřív, než doběhne k middlewaru. `RedirectResponder` (handler `NotFoundHttpException`) proto cachuje výsledek hledání v `redirects` sám, klíčovaný na katalogovou generaci.
- **Fold hledaného termínu měl dvě nezávislé definice** — klíč jen trimoval `q`, hledání samo folduje case i diakritiku (`SearchText::normalise()`). Sjednoceno do `PageCacheKey::foldSearchTerm()`, použité v klíči, v hlavičce i na `/hledani`.
- **`spatie/laravel-multitenancy`'s krátký obvod v `makeCurrent()`** nechával `TenantContext::current()` servírovat atributy z prvního requestu na workeru, který přežije víc než jeden request — objeveno přes čítače, které se v DB měnily, ale do cache klíče se nepropisovaly. Oprava v `app/Core/Tenancy/TenantContext.php`, mimo deklarovaný rozsah vlny, ponechána ve větvi na rozhodnutí vlastníka.

### Mimo rozsah, odloženo na nasazení
Etapa 2 (statický soubor servírovaný web serverem, s otevřenou otázkou CSRF u staticky servírovaných stránek) a etapa 3 (datová a fragmentová cache — menu, patička, číselník sazeb).

## [0.34.0] – 2026-07-31

**Fáze 2 / vlna 2.12 — doprava a poplatek na dokladu + dotažení účetního exportu.** Uzavření vlny (`docs/as-is/2026-07-31-doprava-na-dokladu.md`). 1755 testů (6206 assertions).

### Faktura konečně sečte
Doklad tiskl položky za 1 998 Kč a pod nimi „Celkem k úhradě: 2 097 Kč“, aniž kdekoli uvedl, odkud rozdíl je — doprava a poplatek za platbu žily jen v součtu a v DPH rekapitulaci, která je členěná po sazbách, ne podle toho, co se prodalo. `InvoiceSnapshot` i `ProformaSnapshot` je nově nesou jako řádky ve **stejném tvaru** jako položky, takže se nemění tabulka, PDF šablona ani modul `accounting`.

Účtuje se `charged`, ne `price` (liší se, když sleva udělala dopravu zdarma); u neplátce DPH je sazba **nula, ne dohadovaný default**; nulový řádek se zobrazuje, aby zákazník viděl, co si zvolil. Historické doklady se nemění (immutable snímek, PDF už odešla) — export si u nich dopočte řádek „Doprava a poplatky“, který u nových vyjde na nulu a sám zmizí.

### ISDOC se validuje proti oficiálnímu XSD
Formát byl psaný z dokumentace a nikdy ověřený; golden files porovnávají výstup se sebou samým, takže vlastní chybu odhalit nemohou. Vendorované schéma 6.0.1 (`tests/Fixtures/isdoc/`, vestavěný `libxml`, žádná nová závislost) našlo **osm skutečných porušení** — mimo jiné `TaxSubTotal` pod jménem elementu `ClassifiedTaxCategory`, `TaxPointDate` psaný jako prázdný řetězec místo vynechání a dvě sady povinných polí pro zúčtování záloh. Každý dřívější ISDOC byl odmítnutelný.

### Uzavřené dluhy z vlny 2.11
- Tiché vynechání dopravy u **neplátce DPH** se starým tvarem dokladu (prázdná `vat_summary` i chybějící řádky) — export by zaúčtoval nižší částku, než se zaplatilo. Nově se reconciluje proti `documents.total`.
- Fakturační profil sbírá **zemi** (ISO 3166-1 alpha-2, výchozí `CZ`, regex s `/D`); bez ní by ISDOC vyšel s prázdnou zemí dodavatele. Fallback sedí ve snímku, takže z něj těží i zákaznické PDF.

### Mimo rozsah, opraveno cestou
Dva testy používaly `subMonth()`, což z 31. 7. dělá 1. 7. (červen má 30 dní, datum se normalizuje dopředu) — sada byla červená jen 31. v měsíci.

### Zbývá
Reálný import Pohoda XML do Pohody (potřebuje licenci) — poslední položka pre-deploy checklistu účetního exportu.

## [0.33.0] – 2026-07-31

**Fáze 2 / vlna 2.12 — doprava a poplatek na dokladu.** Start implementačního plánu (`docs/superpowers/plans/2026-07-31-vlna-212-doprava-na-dokladu.md`).

Zadání: faktura, kterou zákazník dostává, tiskne položky za 1 998 Kč a pod nimi „Celkem k úhradě: 2 097 Kč“, aniž kdekoli uvede, odkud rozdíl je — `InvoiceSnapshot` nesnímkuje dopravu ani poplatek za platbu, ty žijí jen v součtu a v DPH rekapitulaci. Odhalila to až vlna 2.11, protože účetní export si musel rozdíl dopočítávat. Doklad, jehož řádky se nesečtou na částku k úhradě, si nemůže zkontrolovat ten, kdo ho platí.

Součástí je dotažení dvou otevřených bodů z 2.11: konzistence ISDOC u neplátce DPH a validace ISDOC proti oficiálnímu XSD 6.0.1 (vestavěný `libxml`, žádná nová závislost) — formát byl dosud psaný z dokumentace, ne ze schématu.

Historické doklady se nemění (immutable snímek, PDF už odešla); modul `accounting` si u nich dál dopočítá řádek „Doprava a poplatky“.

## [0.32.0] – 2026-07-30

**Fáze 2 / vlna 2.11 — modul `accounting`: export dokladů do Pohody a ISDOC (premium).** Uzavření vlny (`docs/as-is/2026-07-30-accounting-export.md`). 1734 testů (6126 assertions).

Nájemcova účetní dostávala z e-shopu jen CSV se souhrnem DPH — doklady musela do účetního programu opsat. Modul vydá tytéž doklady ve formátech, které se importují: **Pohoda XML** (dávka za období) a **ISDOC 6.0.1** (ZIP, jeden soubor per doklad), plus jednotlivý doklad jako `.isdoc` ze seznamu dokladů. První nový **premium** modul; premium tarif dosud odlišoval jen `discounts` a limity.

### Modul
- `level: premium`, právo `accounting.export`, **bez `requires` na `docs`** — null binding `DocumentLedger` vrací prázdno a obrazovka řekne, že není co exportovat. Přiřazení tarifu proběhne samo přes `PlanModuleDefaults`, žádná migrace.
- Registry formátů uvnitř modulu (`AccountingFormat` + `AccountingFormats`, vzor `PaymentGatewayRegistry`); třetí formát bude nový soubor bez zásahu do stávajících.
- Konfigurace předkontací (předkontace faktury/dobropisu, členění DPH, středisko, činnost) jede na generické obrazovce nastavení modulů z vlny 2.10 — nulový nový UI kód.
- `DocumentLedger` rozšířen o `findTaxDocument($number, $type)`; **typ je povinný**, protože číslo je od vlny 1.6 unikátní jen v rámci `(tenant, type)`.

### Co zachytilo až finální review (a bylo by to v účetnictví vidět)
- **Ceny ve snímku jsou s DPH, obě cílová pole jsou bez DPH.** Hrubé částky se zapisovaly do čistých polí, takže import by seděl zhruba o 21 % výš. `DocumentLines` teď převádí přes `TaxRate::net()` celočíselně, Pohoda značí `inv:payVAT`. Golden files to nechytily (porovnávaly jen strukturu), proto k nim přibyly hodnotové aserce počítané nezávisle na exportéru.
- **Snímek nenese dopravu ani poplatek za platbu**, takže řádky neseděly s `documents.total`; rozdíl se dopočítá per sazba jako řádek „Doprava a poplatky“. Tatáž díra je v zákaznickém PDF — dluh v `Modules/Docs`.
- `ZipArchive::addFromString()` a `close()` se nekontrolovaly (archiv s tiše chybějící fakturou), audit se zapisoval před generováním, neznámá sazba vracela 500 místo 422, ISDOC obcházel kontrolu sazeb, validační chyby nebyly na obrazovce vidět. Vše opraveno.

### Deploy
1. `php artisan modules:sync` **před** `migrate`
2. `npm run build`
3. Pre-deploy: reálný import Pohoda XML do Pohody a validace ISDOC proti XSD 6.0.1 — tvary elementů i znaménko dobropisu jsou z veřejné dokumentace, ne z XSD.

### Follow-up
Zákaznické PDF faktury nesečte řádky na „Celkem k úhradě“ (`InvoiceSnapshot`, patří do `Modules/Docs`); u neplátce DPH se dopočtený řádek nevytvoří, ale `PayableAmount` se bere z celkové částky; ISDOC příloha pro odběratele, Money S3, filtry a plná mapovací obrazovka = `docs/future/accounting-dalsi-kroky.md`.

## [0.31.0] – 2026-07-30

**Fáze 2 / vlna 2.11 — modul `accounting`: export dokladů do Pohody a ISDOC (premium).** Start implementačního plánu (`docs/superpowers/plans/2026-07-30-vlna-211-accounting-export.md`), spec `docs/superpowers/specs/2026-07-30-vlna-211-accounting-export-design.md`.

Zadání: nájemcova účetní dnes dostane jen CSV se souhrnem DPH — doklady musí do účetního programu opsat. Modul `accounting` vydá tytéž doklady v Pohoda XML (dávka za období) a ISDOC 6.0.1 (ZIP, jeden soubor per doklad), plus jednotlivý doklad jako `.isdoc` z detailu. Je to zároveň **první nový premium modul**; premium tarif dosud odlišoval jen `discounts` a limity.

Adresátem je účetní nájemce, ne odběratel — ISDOC příloha k faktuře pro zákazníka, Money S3, filtry exportu a plná mapovací obrazovka jsou v `docs/future/accounting-dalsi-kroky.md`.

## [0.30.0] – 2026-07-30

**Tarify se skládají z `level` v manifestu.** `App\Core\Modules\PlanModuleDefaults` je jediné místo, které říká, který tarif modul uděluje.

Vyšlo to z otázky „proč seznam tarifů hlásí 13 modulů, když detail nabízí 12". Odpověď (core `storefront` v `plan_modules`) byla jen vrchol: `level` v manifestu **nikdy nic neautorizoval** — jediný gate je řádek v `plan_modules` — a ordinární moduly tam nikdo nevložil. V čerstvé produkční DB udělovaly tarify jen `discounts` a `feeds`, takže onboardovaný nájemce dostal e-shop **bez katalogu a bez pokladny**.

### Pravidlo
- `core` → žádný tarif (běží všude tak jako tak; grant řádek by nic neudělal a spadl by do deaktivační sady, kde `deactivate()` vyhazuje)
- `level: base` → všechny tarify
- `level: premium` → jen tarify s vlastním levelem premium

Volají ho `PlanSeeder` (čerstvá instalace), migrace `2026_07_30_100000_grant_plan_modules_by_manifest_level` (backfill nasazené DB) a `modules:sync` **jen pro modul, který právě vytvořil** — vědomé odebrání modulu z tarifu na `/superadmin/tarify` deploy nepřepíše. Nový modul tedy nepotřebuje vlastní attach migraci, jen správný `level`.

### Rozdělení tarifů (rozhodnutí vlastníka)
- **base** = celý prodejní e-shop: katalog, pokladna, objednávky, doprava, platby, zákazníci, stránky, faktury, feedy pro Heureku/Zboží, Zásilkovna
- **premium** = base + marketingové nástroje (dnes `discounts`) a vyšší limity (produkty 500→5000, storage 2→20 GB, e-maily 3k→30k)

`DemoShopSeeder` zakládá demo na premium a moduly tarifu nepřiřazuje sám — dřív demo tarifu přiřadil všechno včetně core, čímž se do `plan_modules` dostal `storefront`.

### Testy
1670 zelených (5895 assertions). `PlanModuleDefaultsTest::test_the_real_manifests_compose_the_shipped_tarifs` hlídá nad **reálnými manifesty**, že base tarif uděluje všechno, s čím se prodává — kontrola, která ve vlně 2.9 chyběla.

## [0.29.0] – 2026-07-30

**Fáze 2 / vlna 2.10 — nastavení modulů (nájemce) a správa tarifů (superadmin).** Uzavření vlny (`docs/as-is/2026-07-29-nastaveni-modulu.md`). 1658 testů (5845 assertions).

Do této vlny existoval `SettingsService`, ale **nikdo nevolal jeho `set()`**: sedm nastavení modulu `docs` (splatnost, prefixy číselných řad, patička faktury, kdy se faktura vystaví) šlo změnit jen zásahem do kódu. Přiřadit modul tarifu šlo jen migrací — past, do které vlna 2.9 spadla, protože modul, který není v žádném tarifu, si nájemce nemůže zapnout.

### Jádro — schéma nastavení
- `SettingsField` + `SettingsSchema`: `rules` je autorita nad tím, co smí být uloženo, `type` jen informace, čím to nakreslit. Starý tvar „klíč → string pravidel" se dál parsuje (typ se odvodí z pravidel), takže žádný existující manifest se nemusel měnit.
- Pole **bez** pravidel je chyba — takové by tiše přijalo cokoli. Padá při `modules:sync`, ne uvnitř `SettingsService::all()` na hot path (checkout, vystavení faktury, generování PDF); runtime cesta ji přesto propaguje, aby druhá brána tu samou vadu neschovala za tiše prázdné pole.
- `SettingsService::setMany()` je all-or-nothing (validace celé sady, pak zápis v jedné transakci); `all()` slévá defaulty ze schématu, takže schéma je jediná pravda o tom, na čem běží nedotčený e-shop.
- Manifestový klíč `settings_permission` + křížová kontrola ve `ManifestValidator`, že jmenované právo modul opravdu deklaruje.

### Obrazovka nájemce
- `/admin/nastaveni/moduly` a `/admin/nastaveni/moduly/{modul}` — jeden generický formulář z manifestového schématu, takže nový modul dostane nastavení bez vlastní obrazovky. Modul, který e-shop neběží, je **404** (403 by prozradilo, které moduly e-shopu chybí); právo je to, které jmenuje manifest.
- Obrazovka sedí v jádře, ne pod `/admin/m/{modul}/nastaveni`: modulová cesta by u `products` kolidovala s vazbou produktu na slug.

### Nové nastavení
- `products.variant_display` — přesun z `tenant_theme` (+ drop sloupce). Migrace hodnoty přenese **před** dropem, aby se e-shop nastavený na rozbalovací seznam po deployi nepřepnul na přepínače. Uzavírá odchylku vlny 2.4.
- `checkout.min_order_total` a `checkout.guest_checkout` (nové právo `checkout.manage`) — obojí vynucuje server v `details()` i `place()` před jakýmkoli zápisem; skryté tlačítko v košíku je prezentace, ne pravidlo. Minimum se měří na zboží po slevě, **bez dopravy a platby**: drahý dopravce nesmí zákazníka přenést přes hranici, kterou si nájemce sám nastavil.
- `orders.number_prefix` — platí od další objednávky, už vydaná čísla se nemění (stejné dělení, jaké `InvoiceIssuer` používá pro doklady).

### Superadmin — složení tarifů
- `/superadmin/tarify`: checkboxy modulů, endpoint „dopad" (kolika e-shopů se změna dotkne, co se zapne a co vypne) a povinný důvod při odebrání — vyžádaný podle **spočítaného dopadu**, ne podle formuláře.
- `PlanModuleReconciler` počítá z **živě zapnuté sady** tenanta, ne z diffu editace (idempotentní, nezávislý na pořadí; stejný tvar jako `TenantPlanSwitcher` z 1.9). Katalog plan-grantable klíčů se snímá **před** zápisem — přečtený po `sync()` už neobsahuje klíč, který ta samá editace odebrala, a nevypnulo by se nikdy nic. Globálně kill-switchnutý modul se přeskočí místo výjimky, core modul se nikdy nevypne, audit per tenant.

### Deploy
1. `php artisan modules:sync` **před** `migrate` (nová manifestová pole a schémata; sync odmítne vadné schéma)
2. `php artisan migrate` (přesun `variant_display` + drop sloupce)
3. `npm run build`

### Follow-up
Ruční proklikání dema; rekonciliace tarifu běží synchronně v requestu (kandidát na queued job); ručně vypnutý tarifní modul se editací tarifu znovu zapne (stejný trade-off jako `TenantPlanSwitcher`); nastavení se needituje hromadně přes moduly.

## [0.28.0] – 2026-07-29

**Fáze 2 / vlna 2.10 — nastavení modulů a správa tarifů.** Start implementačního plánu (`docs/superpowers/plans/2026-07-29-vlna-210-nastaveni-modulu.md`), spec `docs/superpowers/specs/2026-07-29-vlna-210-nastaveni-modulu-design.md`.

Zadání: nájemce nastaví chování modulu z adminu na jedné generické obrazovce vygenerované ze schématu v manifestu (`SettingsService::set()` dosud nevolal nikdo, takže sedm nastavení modulu `docs` šlo změnit jen v kódu), `variant_display` se stěhuje z obrazovky Vzhled tam, kam patří, `checkout` dostává minimum objednávky a přepínač nákupu bez registrace, `orders` prefix čísla objednávky. Superadmin dostává obrazovku nad `plan_modules` — přiřazení modulu tarifu dnes jde jen migrací, což je past, do které vlna 2.9 spadla.

Před tím ještě tři opravy nalezené proklikáním dema: prázdná obrazovka produktů v adminu (chybějící import `computed`), slepá ulička v pokladně (krok dopravy se překresloval místo aby pustil dál) a superadmin zamčený ven z `/superadmin/login` tím, že byl přihlášený.

## [0.27.0] – 2026-07-28

**Fáze 2 / vlna 2.9 — XML feedy pro Heureku a Zboží.cz.** Nájemce zapne feed v adminu a porovnávač si z jeho domény stáhne katalog: `/feed/heureka.xml` a `/feed/zbozi.xml`. Bez feedu český e-shop prakticky neprodává. 1600 testů (5603 assertions).

### Nový modul `feeds`
- `level: base`, bez `requires`, runtime gate přes `ShopModules`. Base vědomě: feed do Heureky je v Česku podmínka prodeje, ne nadstandard — schovat ho za vyšší tarif by srazilo hodnotu základního tarifu pod běžný standard.
- Migrace přiřazuje modul **všem tarifům** plus řádek v `PlanSeeder`. Modul, který není v žádném tarifu, si nájemce nemůže zapnout (`PlanDoesNotIncludeModule`) — chování hlídá test.
- Dvě tabulky: `product_feeds` (stav a nastavení feedu) a `feed_category_mappings` (kategorie v číselníku porovnávače). Mapování je vlastní tabulka, ne sloupce na `categories`: modul jde vypnout a nesmí po sobě nechat sloupce v cizím modulu.

### Feed
- XML se staví na request a cachuje hodinu (vzor `SitemapController`). **Vypnutý feed i vypnutý modul vracejí 404**, ne prázdné XML — prázdný feed čte porovnávač jako „e-shop nemá zboží" a odstraní z výpisu celý katalog.
- Varianta je samostatný `SHOPITEM` se sdíleným `ITEMGROUP_ID` a osami v `PARAM`; `ITEM_ID` je `{produkt}-{varianta}`, protože holé id varianty by mohlo kolidovat s id produktu.
- Cena vždy z `catalogPrice()`, tedy včetně akční ceny z vlny 2.7 — feed nesmí inzerovat jinou částku, než jakou zákazník zaplatí v košíku. Do feedu jdou jen `published()` produkty, takže koncept se ven nedostane.
- Vyprodané zboží zůstává ve feedu s `DELIVERY_DATE` místo aby zmizelo a ztratilo historii. `CATEGORYTEXT` z mapování, prázdné degraduje na vlastní strom nájemce. Blok `DELIVERY` z `ShippingOptions`; bez modulu `shipping` chybí celý místo nulové ceny.

### Admin
- `/admin/m/feeds` (permission `feeds.manage`): přepínač per feed s adresou ke zkopírování, výchozí dodací lhůta a tabulka kategorií se dvěma textovými poli. Uložení invaliduje cache, aby nájemce nečekal hodinu na vlastní opravu.

### Odloženo
- Google Merchant, Glami/Favi, import číselníků s našeptávačem, per-product opt-out, invalidace cache při editaci katalogu, hmotnostní pásma dopravy ve feedu = `docs/future/feedy-dalsi-kroky.md`.

### Deploy
- **`php artisan modules:sync` musí běžet před `php artisan migrate`** — migrace přiřazující modul tarifům jinak neudělá nic (stejné pořadí jako u vlny 2.6). Pak `migrate` (dvě tabulky + přiřazení k tarifům) a `npm run build`.

## [0.26.0] – 2026-07-28

**Fáze 2 / vlna 2.8 — CSV import a export produktů.** Nájemce naplní a udržuje katalog hromadně: stáhne si ho jako CSV, upraví v Excelu a nahraje zpět. Import zakládá i aktualizuje produkty a varianty podle SKU, chybné řádky přeskočí do protokolu ke stažení. 1565 testů (5475 assertions).

### Formát a round-trip
- `ProductCsvSchema` je **jediná pravda o formátu pro oba směry** — export produkuje přesně to, co import přijme, a `CsvRoundTripTest` (export → import → katalog beze změny) to hlídá. Sloupec přidaný jen do jednoho směru shodí test okamžitě.
- Hlavička česká, pořadí sloupců volné, oddělovač `;`, UTF-8 s BOM, ceny v korunách s desetinnou čárkou. Parser je shovívavý ke vstupu (BOM, `,` jako oddělovač, `.` jako desetinná tečka), export přísný na výstup.
- Varianty mají vlastní řádek (`varianta_rodic_sku` + osy jako `Velikost:M|Barva:černá`); nový `VariantWriter::upsertVariant()` zakládá nebo aktualizuje jednu konkrétní kombinaci.

### Import
- Upsert **podle SKU**: prázdné zakládá nový produkt, duplicitní (v souboru i proti DB) je chyba řádku, protože import nemá jak vybrat, který aktualizovat.
- Zápis jde **výhradně přes `ProductWriter`/`VariantWriter`**, takže hromadné nahrání dostane stejnou sanitizaci HTML, unikátní slug, 301 redirect a zápis do historie ceny (Omnibus, vlna 2.7) jako ruční editace.
- Kategorie se importem **nezakládají** — neexistující cesta je chyba řádku. Sdílený strom by z jednoho překlepu ve 3 000 řádcích dostal větev k ručnímu úklidu. Výrobce naopak firstOrCreate.
- Limit tarifu platí; vyčerpaná kvóta shodí jen svůj řádek. Prázdná buňka při aktualizaci znamená „neměnit", ne „vymazat".
- Běh je queued job nad novou tabulkou `product_imports`, **jedna transakce na řádek**, průběžné počty a chybové CSV s číslem řádku a důvodem. Přepínač „jen zkontrolovat" jede stejnou cestou bez zápisu.

### Export a admin
- Streamovaný export nad `lazy(200)`; nákupní cena jen pro `products.costs`, aby export nebyl zadní vrátka k marži. Volné textové sloupce neutralizované proti CSV formula injection (CWE-1236).
- Admin `/admin/m/products/import`: upload, přepínač suchého běhu, historie posledních běhů, stažení protokolu chyb. Nahraný soubor i protokol leží na privátním disku, cizí běh vrací 404.
- Import a export routy jsou registrované **nad** `/{product}` — produkt se váže slugem, takže by se jinak hledal produkt jménem „export".

### Odloženo
- Obrázky z URL (SSRF plocha), mapování sloupců, plánovaný import z feedu dodavatele, mazání importem, XLSX = `docs/future/csv-import-dalsi-kroky.md`.

### Deploy
- Jedna nová migrace (`product_imports`). Ověřit běžící `queue:work` — bez workera zůstane import ve stavu `pending`. Zkontrolovat `upload_max_filesize`/`post_max_size` proti `products.import.max_size_kb` (výchozí 5 MB).

## [0.25.0] – 2026-07-28

**Fáze 2 / vlna 2.7 — akční ceny produktu + evidence nejnižší ceny za 30 dní.** Nájemce zlevní produkt i variantu na určené období; storefront ukáže akční cenu, přeškrtnutou nominální a povinný údaj o nejnižší ceně za posledních 30 dní podle § 12a zákona č. 634/1992 Sb. (směrnice Omnibus). Zároveň se zavírá dluh nesený z vlny 2.6: poplatek za dopravu a platbu bez sazby DPH se účtoval, ale vypadával z rekapitulace. 1514 testů (5326 assertions).

### Cenová autorita
- `products.sale_price` + okno kampaně (`sale_starts_at`/`sale_ends_at`) a `product_variants.sale_price`; okno sedí **jen na produktu** (jedna kampaň, částky per varianta). Varianta bez vlastní ceny dědí i akční částku produktu; varianta s vlastní cenou musí mít vlastní, jinak jede na nominální — absolutní částka na jiném cenovém základu by tiše prodávala pod nákladem.
- `ProductCatalog::price()` a `CatalogProduct::catalogPrice()` vracejí **efektivní** cenu, takže košík, `OrderPlacer`, doklady i slevový engine z 2.6 účtují akční cenu bez jediné změny volajícího kódu; kupón se tím počítá z akční ceny, ne z nominální. Kontrakty rozšířeny o `catalogRegularPrice()`, `catalogIsOnSale()`, `catalogLowestPriceIn30Days()` a variantní protějšky.
- Řazení katalogu podle ceny jede přes `CASE WHEN` nad efektivní cenou (MySQL i SQLite), takže zlevněný produkt sedí ve výpisu tam, kam podle skutečně placené ceny patří. Mrtvý sloupec `compare_at_price` zahozen.

### Evidence ceny (Omnibus)
- Nová tabulka `product_price_history` — časová řada efektivních cen. `PriceHistoryRecorder` zapisuje i **plánované budoucí** intervaly, takže konec akce nepotřebuje cron ani job. Uzavřený řádek se nikdy nemění; běžícímu se smí posunout jen konec, který ještě nenastal (jinak by přeplánování kampaně vyrobilo dva překrývající se intervaly).
- `LowestPriceCalculator` (okno 30 dní jako konstanta, ne nastavení) počítá referenci **ke startu běžící kampaně** — akce není součástí své vlastní reference, jinak by se reference vždy rovnala akční ceně a každá oznámená sleva vyšla 0 %. Badge `−N %` se počítá z této reference. Produkt nasazený rovnou do akce nemá starší historii: řádek se zobrazí, badge ne.
- Storefront (Blade SSR, bez JS): akční a přeškrtnutá cena na detailu i ve výpisu, povinný řádek na detailu; JSON-LD `Offer` kvotuje efektivní cenu. Vanilla JS ostrůvek variant přepíná i přeškrtnutou cenu — jen server-formátované řetězce, žádná aritmetika v JS.
- Admin: pole akční ceny a okna na detailu produktu (`sale_price` musí být nižší než běžná cena, konec po začátku), sloupec akční ceny v mřížce variant.

### DPH poplatků (uzavření dluhu 2.6)
- `tax_rate_id` u dopravy i platby je povinné, když je nájemce plátce DPH; neplátce ho dál nemusí vyplňovat. Existující metody plátců dostaly výchozí sazbu backfill migrací (nevratnou). Tichý fallback zamítnut — účetní číslo nemá vznikat z domněnky, kterou nájemce nepotvrdil.

### Odloženo
- Řádek nejnižší ceny ve **výpisu kategorie** (dnes jen na detailu) čeká na právní review; Omnibus u automatických pravidel z 2.6, hromadné nastavení akcí, filtr „ve slevě" a akční ceny ve feedech = `docs/future/slevy-dalsi-kroky.md`.

### Deploy
- Čtyři nové migrace (dvě strukturální, dvě backfill). Plátci DPH musí mít výchozí sazbu v `tax_rates` **před** migrací, jinak se backfill poplatků pro daného nájemce přeskočí. Reference „nejnižší cena za 30 dní" začíná běžet od nasazení — starší historii nikdo nezaznamenal a migrace ji nevymýšlí.

## [0.24.0] – 2026-07-28

**Fáze 2 / vlna 2.6 — slevový engine (kupóny + automatická pravidla).** Nájemce dává slevy: kódový kupón, který zákazník zadá v košíku nebo v pokladně, a automatické pravidlo, které platí bez kódu. Sleva se rozpustí do řádků košíku i objednávky s haléřovou přesností, takže DPH rekapitulace vždy sedí na skutečně zaplacenou částku. 1461 testů (5110 assertions).

### Jádro a nový modul
- Kontrakt `DiscountEngine` (`apply(DiscountContext): AppliedDiscount`) + `DiscountBook`/`DiscountRedemption` a guest-safe null bindingy v `app/Core/Discounts/` — vzor `PaymentGatewayRegistry`/`CarrierRegistry`, takže vypnutý modul znamená „žádná sleva", ne chybu.
- Nový premium modul `Modules/Discounts` (klíč `discounts`, odchylka od spec `coupons` — obsluhuje i pravidla bez kódu): `DiscountEvaluator` vyhodnocuje podmínky (platnost, min. košík, cíl kategorie/produkty, přihlášení, první nákup, limity), `DiscountAllocator` rozpouští částku do řádků přes `Money::allocateByRatios()`, capacity-aware (víc slev na tentýž řádek nikdy nepřekročí jeho vlastní součet).

### Košík, pokladna, objednávka
- `CartPricer` cení košík přes engine; pole „Slevový kód" na `/kosik` i `/pokladna/udaje` je čistý `<form method="post">`, funguje bez JS, chyba vázaná `aria-describedby` + `role="alert"`.
- Automatické pravidlo „doprava zdarma nad X" přebije `freeFrom()`; nejvýš jeden kupón na košík + `combinable` automatická pravidla (kupónův vlastní `combinable` se ignoruje).
- `OrderPlacer` vyhodnocuje slevu znovu při odeslání (poslední vyhodnocení je závazné, politika `PriceChanged`), čerpá limit uvnitř téže transakce jako odpis skladu; storno a expirace vrací čerpání gated na `returnStock`, takže podezřelý storno bez vrácení skladu nevrací ani kupón. E-mailem gatovaný kupón, který selže až při odeslání, objednávku odmítne s vysvětlením místo tiché plné ceny.
- Objednávka za 0 Kč po slevě se settluje přímo, platební brána se nevolá; QR a platební instrukce se potlačí na děkovné stránce i v e-mailu.

### Doklady a admin
- Faktura nese zlevněné řádky + informační poznámku o uplatněné slevě; při editaci objednávky se podíl slevy na přeživších řádcích zachová a `orders.discount_total` se přepočítá bez opětovného běhu enginu — poznámka na faktuře degraduje bez jmen slev, když se snímek rozejde se živým součtem. Dobropis zůstává prostou negací.
- Admin `/admin/m/discounts` (Inertia): výpis, formulář s generátorem kódu a ohraničeným hledáčkem produktů, potvrzovací dialog u mazání, chyby vázané na pole.

### Odloženo
- Akční ceny produktu (přeškrtnutá cena, evidence nejnižší ceny za 30 dní) = **vlna 2.7** — mění `ProductCatalog::price()`, tedy cenovou autoritu. Dárkové poukazy, dávkové generování kódů, procentní sleva na dopravu, víc kupónů najednou, plný přepočet slevy při editaci objednávky = `docs/future/slevy-dalsi-kroky.md`.

## [0.23.0] – 2026-07-27

**Fáze 2 / vlna 2.5 — Zásilkovna.** Nájemce prodává s dopravou na výdejní místo a celý životní cyklus zásilky odbaví z adminu: zákazník si místo vybere v pokladně i s vypnutým JavaScriptem, nájemce objednávku podá do Zásilkovny přes API, stáhne štítek a zákazník dostane sledovací odkaz. 1359 testů (4653 assertions).

### Jádro a nový modul
- Kontrakty `Carrier`, `CarrierRegistry`, `PickupPointCatalog`, `ShipmentBook` + guest-safe null bindingy v `app/Core/Shipping/` — vzor `PaymentGatewayRegistry` z vlny 1.4, takže druhý dopravce je další driver, ne zásah do checkoutu. `ShippingOption` rozšířen o `provider()` a `defaultWeightGrams()`.
- Nový modul `Modules/Packeta` (bez manifestového `requires`, runtime gate přes `ShopModules`, tarif base): REST/XML klient přes `Http` fasádu **bez SOAP a bez composer balíčku**, driver, registry, sync katalogu, podání, štítky.

### Výdejní místa
- Netenantová sdílená tabulka `pickup_points` (allowlist v `SchemaConventionTest`) plněná denním `packeta:sync-points`. Guard odmítne prázdný nebo useknutý feed — jedna špatná odpověď nesmí deaktivovat výdejní místa všem nájemcům.
- Server-rendered výběr místa (`/pokladna/vydejni-misto`) je **primární cesta**, ne fallback; oficiální widget je vanilla ostrůvek načítaný **až na kliknutí**, takže do té doby checkout nedělá žádný request na cizí doménu. Klient posílá jen kód, adresa se vždy čte z katalogu.

### Zásilky
- `shipments` s unique `(tenant_id, order_id)`; podání je idempotentní přes compare-and-swap claim, staleness reclaim vrací zásilku zaseknutou po pádu procesu, a fail-fast guard hlídá, že práh reclaimu nikdy nevyprší nad ještě běžícím voláním. Cizí HTTP nikdy uvnitř transakce.
- Hromadné podání nespadne na první chybě, štítky se streamují bez ukládání na disk, zrušení volá `cancelPacket`. Expediční fronta `/admin/m/packeta/expedice`, blok Doprava v detailu objednávky, sledovací odkaz v účtu zákazníka.

### Bezpečnost
- `shipping_methods.settings` je nově `encrypted:array` — revize rozhodnutí z 2026-07-21, které platilo jen dokud sloupec nenesl credential. Migrace re-encryptuje existující řádky; `api_password` se nikdy nevrací do adminu ani neflashuje do session.

### Odloženo
- AK §16.5 „další dopravce bez zásahu do checkoutu" **není splněné** — dvě místa v `PickupPointController` a `PickupPointCatalog::search()` drží Zásilkovnu natvrdo. Adresné doručení kurýrem, polling stavu zásilky, zpáteční zásilky, e-mail „odesláno" — vše v `docs/future/zasilkovna-dalsi-dopravci.md`.

## [0.22.0] – 2026-07-26

**Fáze 2 / vlna 2.4 — varianty produktu (víceosá matice).** Nájemce prodává jeden produkt ve více variantách (Velikost × Barva) s vlastní cenou, skladem a SKU per kombinace; zákazník variantu vybere a koupí i s vypnutým JavaScriptem. 1252 testů (4094 assertions).

### Datový model + kernel kontrakty
- 4 relační tabulky v modulu `products`: `product_options` (osy), `product_option_values` (hodnoty), `product_variants` (kombinace — cena nullable = zdědit `products.price`, sklad, SKU/EAN, `active`), `product_variant_values` (pivot). `products` + `tenant_theme` dostaly `variant_display` (radio/select, dědičnost produkt → tenant default).
- `App\Core\Catalog\Contracts\CatalogVariant` (nový tvar) + rozšíření `ProductCatalog`/`CatalogProduct` o volitelný `?int $variantId` jako poslední parametr — žádný existující callsite se nemění. Server-authoritative resoluce: klient posílá `option_value_id[]`, nikdy `variant_id`; `resolveVariant()` ověří příslušnost k produktu i tenantovi.

### Košík, objednávka, sklad
- `cart_items.variant_id` (NOT NULL, sentinel `0`), přepsaný `cart_item_unique`; `order_items.variant_id`/`variant_label` (nullable snapshot, bez FK). Sklad se odepisuje z varianty atomicky ve stejné transakci jako zápis/storno objednávky. Admin editace objednávky zachovává variantu (opraven kritický nález review — editace dřív mazala `variant_id` a přeceňovala na základní cenu).

### Storefront
- `variant-picker.blade.php` — server-rendered osy (radio/`<fieldset>`+`<legend>` nebo `<select>`), formulář funguje čistě POSTem bez JS; JSON-LD `Offer` per aktivní varianta; „od" cena ve výpisu kategorie/homepage pro produkty s variantami.
- Vanilla JS ostrůvek (`resources/js/storefront.js`, bez Alpine — projekt Alpine nemá) živě přepočítává cenu vč. „bez DPH" a dostupnost nad embedded maticí; bundle 1248 B / 606 B gzip, žádný framework.

### Admin
- Nový tab „Varianty" na detailu produktu (`Show.vue`): osy a hodnoty s řazením tlačítky, „Generovat varianty" (idempotentní kartézský součin), mřížka cena/SKU/EAN/sklad/aktivní s per-řádkovým dirty-flag trackingem. Globální default zobrazení na `/admin/nastaveni/vzhled`.
- 10 nových admin endpointů, všech gated `products.edit` (opraven kritický nález review — šest endpointů zprvu kontrolu postrádalo).

### Odloženo
- Obrázky per varianta, URL per varianta, hmotnost per varianta, fasetový filtr podle osy, hromadný import variant — `docs/future/varianty-obrazky-a-url.md`.
- Hromadné akce v mřížce variant (nastavit cenu/sklad všem najednou); „od" cena zatím počítá nejlevnější aktivní, ne nejlevnější skladem dostupnou variantu; page cache invalidace po změně varianty (page cache samotná zatím neexistuje).

## [0.21.0] – 2026-07-26

**Fáze 2 / vlna 2.3 — bloková homepage (page builder).** Nájemce si homepage e-shopu poskládá z bloků místo fixní šablony. Storefront homepage zůstává Blade SSR bez JS; editor je Inertia SPA v adminu. 1177 testů.

### Datový model + render
- Tabulka `homepage_blocks` (modul `storefront`): `tenant_id` (FK cascade), `position`, `type`, `payload` (JSON), `visible`; model `HomepageBlock` (`BelongsToTenant`), enum `BlockType`.
- 5 typů bloků: `hero`, `product_row` (novinky / ruční výběr), `category_grid`, `text` (sanitizované HTML), `banner` (obrázek + odkaz).
- `HomeController` iteruje viditelné bloky (`orderBy position`), `prepare()` mapuje typ na Blade partial + data (produkty z `ProductCatalog`, kategorie z `Category`); bloky vypnutých modulů se vynechají, prázdná homepage vrací 200. Čistý Blade SSR, žádný nový storefront JS.

### Seed
- `DefaultHomepage` = jediný seed recept (hero + product_row latest 8 + category_grid), idempotentní. Volá `TenantProvisioner` (noví tenanti) i backfill migrace (stávající). Core resolvuje seeder stringem, žádný compile-time modulový import.

### Admin editor
- Inertia `/admin/m/storefront/homepage` (`admin.storefront.homepage.*`, permission `storefront.homepage.manage`, nav „Homepage"): seznam bloků, řazení tlačítky (nahoru/dolů), skrýt/zobrazit, editace per typ, upload obrázků, mazání s potvrzením. Write-freeze pro suspended/past_due.

### Bezpečnost
- Text HTML sanitizován `HtmlSanitizer` při zápisu; obrázky raster-only (`png,jpg,jpeg,webp`, žádné SVG); `BlockUrl` guard odmítá `javascript:`/`data:`/`vbscript:`/protocol-relative `//`/backslash (open-redirect); `image_path` server-authoritative (klient ho nediktuje); PATCH+file přes POST+`_method` spoofing; strop 30 bloků; tenant izolace přes `BelongsToTenant`.

### Přístupnost (WCAG 2.2 AA)
- Audit + opravy: jeden `<h1>` (odmítnutí druhého hero), hero obrázek se renderuje + má `alt`, `id` sekcí z `block->id` (žádná kolize), success flash u řazení/skrytí, banner `alt` required s obrázkem, sanitizer doplní `alt=""`, error summary `role="alert"` + focus na první chybu, format hinty u uploadu, min target size řadících tlačítek.

### Odchylky / follow-up
- Page builder homepage je rozšíření nad rámec produktové spec (§4.1 katalogově orientovaný). „Celá nabídka →" odkaz nahrazen `category_grid` blokem. `category_ids`/`product_ids` v editoru = comma-text (MVP), ne multiselect. Page cache invalidace odložená (page cache zatím není). Live preview + drag&drop, blokové obecné stránky, další typy bloků = budoucí vlny.

## [0.20.0] – 2026-07-25

**Fáze 2 / vlna 2.2 — šablona storefrontu + branding nájemce.** Storefront dostal jednu čistou prodejnou šablonu místo holé a nájemce si e-shop přebranduje (logo, favicon, primární + akcentní barva). Konzistentní vzhled přes všechny veřejné stránky, pořád Blade SSR bez JS. 1140 testů.

### Šablona
- Designový systém: Tailwind brand tokeny (`brand`/`brand-contrast`/`accent`) mapované na CSS proměnné, komponentní vrstva v `resources/css/storefront.css` (`.btn`/`.card`/`.field-*`/`.badge`/`.prose-shop`), čistý/minimalistický styl.
- Přebarveny všechny storefront views: chrome + komponenty, home, detail produktu, kategorie, hledání, chyby, košík, pokladna, děkovná, účet zákazníka, auth, statická stránka. Žádný nový JS na storefrontu, nákup dál projde bez JavaScriptu.

### Branding nájemce
- Tabulka `tenant_theme` (1:1 s tenantem): `logo_path`, `favicon_path`, `primary_color`, `accent_color`. `TenantTheme` model s defaulty.
- `ThemeResolver` → `ThemeData` DTO, injektováno do layoutu přes existující view composer; brand barvy jako inline `<style>` CSS proměnné v `<head>` (per-tenant, cache-safe).
- `Contrast` helper (WCAG relative luminance) dopočítá čitelnou barvu textu na brand pozadí.

### Admin „Vzhled"
- `/admin/nastaveni/vzhled` (core tenant route): color pickery + hex, live varování kontrastu, upload/mazání loga (max 512 kB) a favicon (`png,ico`, max 128 kB). Nav odkaz „Vzhled".

### Bezpečnost
- CSS-injection guard: `ThemeResolver::sanitizeHex` (`/^#[0-9a-fA-F]{6}$/D`) odmítne ne-hex barvu → default, ještě než vstoupí do inline `<style>`; stejný `/D` regex i na admin vstupu.
- Favicon jen `png,ico` (ne SVG) — SVG servírované jako `image/svg+xml` je stored-XSS vektor. Tenant izolace barev, upload safety (extension z MIME, tenant-prefixed path), write-freeze na suspended.

### Odloženo
- Bloková homepage / page builder = vlna 2.3. Další šablony (marketingová, technická) = `docs/future/2026-07-25-dalsi-storefront-sablony.md`. Statické stránky (VOP/GDPR) do shop layoutu = pre-launch/legal vlna.

## [0.19.0] – 2026-07-23

**Fáze 2 / vlna 2.1 — vlastní domény nájemců + automatické TLS (Caddy on-demand).** Nájemce provozuje e-shop na vlastní doméně s automaticky vydaným certifikátem; platforma ověří vlastnictví přes DNS, teprve pak autorizuje emisi a začne doménu servírovat; po vydání certu se custom doména stane kanonickou a subdoména 301 přesměruje na ni. 1096 testů.

### Ověření vlastnictví + resolce

- Kontrakt `DnsChecker` (`SystemDnsChecker`/`dns_get_record`, testovací `FakeDnsChecker`) — DNS za abstrakcí kvůli deterministickým testům.
- `DomainVerifier` — jediná autorita nad `verified_at`: TXT challenge token na `_droidshop-challenge.<doména>` **a** routing (CNAME dot-anchored na `edge_host` NEBO A obsahuje `server_ip`).
- `DomainTenantFinder` gating — neověřená `type=custom` doména se neresolvuje na tenanta; `forget(host)` na každé změně stavu je load-bearing.
- Migrace: `domains` +`challenge_token`/`verification_error`/`last_checked_at`. `config/platform.php` (server_ip, edge_host, challenge_prefix, cert_probe_max_attempts, pending_ttl_hours, dns_backoff_minutes, tls_check_ttl, tls_check_token).

### Emise TLS + kanonizace

- Ask endpoint `GET /internal/tls-check` (Caddy on-demand se ptá před emisí) — 200 jen pro verified+`type=Custom`+`allowsStorefront()`. Autentizace **shared-secret token** (`hash_equals`, fail-closed) + `AllowLocalOnly` jako obrana do hloubky.
- `DomainCertProbe` — HTTPS probe `/up` 200 → `ssl_status=issued` atomicky s `CanonicalDomain::promote` (custom→primární) v jedné transakci; bounded retry přes tenant-aware job, sync-guard.
- `RedirectToCanonicalHost` — 301 subdoména→custom pro storefront GET/HEAD (admin/soubory/onboarding/impersonace vyloučeny, Location z DB, vždy https).

### Lifecycle + admin

- Command `domains:sweep-pending` (hodinově): DNS chyby auto-retry, expirace >`pending_ttl_hours` → error jednou, cert chyby terminální.
- Admin `/admin/nastaveni/domena` — přidat/ověřit/smazat, DNS instrukce s tokenem, stavový badge, audit (`domain.added`/`removed`/`cert_recheck`), limit 1 custom doména/tenant.

### Deploy / follow-up

- Caddy `on_demand_tls { ask http://127.0.0.1:<port>/internal/tls-check?token=<PLATFORM_TLS_CHECK_TOKEN> }`; Caddyfile **zamítni veřejný `/internal/*`**; on-demand jen custom, subdomény wildcard DNS-01.
- `edge.droidshop.cz` A → VPS IP; `.env`: `PLATFORM_SERVER_IP`/`PLATFORM_EDGE_HOST`/`PLATFORM_TLS_CHECK_TOKEN`; cron `schedule:run`. Runbook: `docs/as-is/2026-07-23-custom-domains.md`.

## [0.18.0] – 2026-07-23

**Fáze 1 / vlna 1.9 — deferred billing: roční interval + upgrade/downgrade tarifu.** Nájemce platí předplatné měsíčně nebo ročně a mění tarif base↔premium přes hostovaný Stripe Billing Portal; proraci i roční fakturu Stripe zúčtuje a my na `invoice.paid` vystavíme český daňový doklad. 1016 testů.

### Ceník a interval

- Nová netenantová tabulka `plan_prices` (plan × interval → Stripe price id + částka v haléřích). `plans.stripe_price_id` zrušen, data přesunuta na `interval=month`.
- Enum `BillingInterval` (Month/Year). `SubscriptionGateway::startCheckout(Tenant, Plan, BillingInterval)` resolvne Stripe price z `plan_prices`.
- Obrazovka předplatného má přepínač měsíc/rok (accessible radio), `SubscriptionController::show` posílá obě ceny.
- `tenants.billing_interval` — trackován z aktivní subscription.

### Doklad (idempotence per Stripe invoice id)

- `platform_invoices.stripe_invoice_id` (unique) — idempotence dokladu se přesunula z per-období na per Stripe invoice id, takže proration i roční faktura dostane vlastní doklad.
- `SubscriptionCharge` +`stripeInvoiceId`,+`grossTotal`; `PlatformInvoiceWriter` bere částku z faktury, ne z `plan->price_month`.
- `StripeWebhookHandler::onInvoicePaid` přepsán: částka (`amount_paid`) i tarif/interval (line `price.id` → `plan_prices`) z faktury; guard `amount_paid==0` (downgrade kredit) → žádný doklad; výběr správného řádku proration faktury (`chargeLineFor`).

### Změna tarifu (Portal-driven)

- Nový webhook handler `customer.subscription.updated`: mapuje nové `price.id` → plan+interval → `TenantPlanSwitcher`.
- `TenantPlanSwitcher` — repoint `plan_id`/`billing_interval` + rekonciliace modulů proti živě zapnuté sadě (order-independent vůči pořadí webhooků, idempotentní). Deaktivuje jen tarifní moduly (core nikdy), aktivuje jen dostupné (globálně kill-switchnutý přeskočí).

### Deploy / follow-up

- Stripe Billing Portal nakonfigurovat (switch plans, proration), 4 Price objekty a jejich id do `plan_prices`, povolit event `customer.subscription.updated` (viz „Před spuštěním" v CLAUDE.md).
- Trade-off: rekonciliace běží při každém `subscription.updated`, ručně vypnutý tarifní modul se obnoví (per-tenant ruční vypnutí není MVP workflow).

## [0.17.0] – 2026-07-22

**Fáze 1 / vlna 1.8 — Stripe subscription billing.** Nájemci teď reálně platí platformě za předplatné: Stripe Billing řídí opakovaný fakturační cyklus a dunning, my reagujeme webhooky. Uzavírá háček z vlny 1.7 (synchronní charge-success-then-issue-fail). 993 testů (+27 od 966 na konci vlny 1.7).

### Seam `SubscriptionGateway` (redesign)

- Nový tvar: `startCheckout(Tenant, Plan): string` (Stripe Checkout, subscription mode) + `billingPortalUrl(Tenant): string` (Stripe Billing Portal). Žádné karetní údaje u nás — PCI SAQ-A.
- `StripeSubscriptionGateway` — reálný driver přes `\Stripe\StripeClient`, zakládá/reuse Stripe Customer, metadata `tenant_id` na checkout i subscription.
- `NullSubscriptionGateway` — dev auto-success (lokální dev route simuluje aktivaci), default v testech.
- Retirováno: synchronní `charge()`, `SubscriptionActivator`, `ChargeResult`, `ChargeFailed`, superadmin manuální aktivace. `SubscriptionCharge`/`MissingBillingProfile`/`PlatformInvoiceWriter` zůstávají.

### Webhook

- `StripeWebhookHandler` (netenantový) mapuje `checkout.session.completed` → propojení Stripe id, `invoice.paid` → vystavení platformní faktury (idempotentně per období) + `Active` + paid-through, `invoice.payment_failed` → `past_due`, `customer.subscription.deleted` → `suspended`.
- Idempotence přes `stripe_events` (unique `event_id`) — claim + zpracování atomicky v jedné transakci, aby mid-processing selhání nezahodilo Stripe retry.
- `POST /superadmin/stripe/webhook` — bez CSRF/session, autenticita jen podpisem (`Stripe-Signature`), 2xx po zpracování, 4xx jen na neplatný podpis.

### Admin UX + lifecycle

- Nájemce: `/admin/predplatne` (stav, Checkout, Billing Portal), trial banner (sdílené propy `trialDaysLeft`/`subscriptionActive`), guard na kompletní fakturační profil před checkoutem.
- Superadmin: read-only stav předplatného v detailu tenanta (bez manuální aktivace).
- Lifecycle sweeper (`billing:sweep-lifecycle`) přeskakuje tenanty s `stripe_subscription_id` — jejich životní cyklus řídí Stripe.
- `CheckTenantStatus` rozlišuje admin vs. storefront (suspendovaný nájemce dál čte admin read-only).

### Data

- `tenants.stripe_customer_id`/`stripe_subscription_id`, `plans.stripe_price_id`, netenantová `stripe_events` (allowlist).
- `config/billing.php` — sekce `stripe`; odstraněn mrtvý `monthly_charge_enabled`.

### Mimo vlnu

Roční interval, upgrade/downgrade tarifu s proraci, kupóny, víc měn — pozdější vlna. Skutečný Stripe test-mode běh (Checkout/Portal/webhook proti živému API) neověřen v tomto vývojovém prostředí — deploy smoke test před produkcí.

## [0.16.0] – 2026-07-22

**Fáze 1 / vlna 1.7 — self-service onboarding + platformní billing.** Registrovaný uživatel si průvodcem založí e-shop na subdoméně s 14denním trialem, platforma řídí lifecycle nájemce a umí mu vystavit daňový doklad za předplatné. Reálné inkaso (Stripe) je připraveno kontraktem, implementuje se vlna 1.8. 966 testů (+3 od 963 na konci implementace, +8 od vlny 1.6).

### Onboarding

- `TenantProvisioner` — jeden transakční recept na založení tenanta (tenant + primární subdoména + owner + moduly tarifu + audit); `DemoShopSeeder` ho volá.
- Inertia wizard (registrace → `/onboarding` → e-shop): název + subdoména s živou kontrolou dostupnosti (`GET /onboarding/subdomena/check`, `no-store`), výběr tarifu, přistání v adminu.
- Cross-host signed auto-login (`onboarding.enter`) — kvůli host-only cookies (`SESSION_DOMAIN=null`) přechod z platform hostu do admin subdomény přes krátkodobou podepsanou URL s membership kontrolou.
- Dashboard „Moje e-shopy" (seznam e-shopů uživatele + „Založit e-shop"). Jádrová admin routa `admin.home` směruje do adminu.

### Trial lifecycle

- Command `billing:sweep-lifecycle` (`NotTenantAware`, denní): `trial`→`past_due` (storefront běží dál), `past_due` po grace → `suspended`, e-mail ownerovi. Config `config/billing.php` (`trial_days=14`, `grace_days=7`).

### Platformní fakturační ledger (netenantový)

- `platform_invoices` + immutable `PlatformInvoice`, `PlatformSequenceService` (gap-free, netenantový), číslo `PF{YYYY}{NNNN}`.
- `PlatformInvoiceWriter` — VAT split dle *našeho* plátcovství, snímek dodavatele (config) + odběratele (nájemce), idempotence per období `(billed_tenant_id, period_from, period_to)`, transakční alokace čísla, PDF přes dompdf na privátní disk.
- `SubscriptionGateway` seam + `NullSubscriptionGateway` (žádné peníze), `SubscriptionActivator` (charge → faktura → `Active`). Superadmin akce „Aktivovat předplatné" se stavovým guardem. Stažení faktury: superadmin libovolnou, nájemce jen vlastní (cizí → 404).

### Fakturační profil nájemce

- Jádrová obrazovka `/admin/nastaveni/fakturace` (nová route skupina `routes/tenant.php`) — dodavatel na fakturách nájemce i odběratel na naší faktuře. Banner „doplňte fakturační údaje" (sdílený prop `billingProfileComplete`).

### Mimo vlnu (design-for / fáze 2)

- Reálné inkaso Stripe = vlna 1.8 (jen driver + webhook, bez zásahu do onboardingu/scheduleru/ledgeru).
- Vlastní doména nájemce = fáze 2 (ověření vlastnictví + TLS emise na VPS).

## [0.15.0] – 2026-07-22

**Fáze 1 / vlna 1.6 — modul `docs`: dobropis, proforma, CSV VAT export, číslování.** Doplňuje zbývající dva typy dokladu z enumu (`credit_note`, `proforma`), účetní CSV export podle DUZP a roční reset číslování odložený z vlny 1.5. Uzavírá spec §16.6 pro `docs` v rozsahu MVP (905 testů, +47 oproti 858 na začátku vlny).

### Architektura — registry + sdílený writer

- `DocumentIssuerRegistry` (kernel binding `DocumentIssuer`) deleguje per typ na `InvoiceIssuer`/`CreditNoteIssuer`/`ProformaIssuer` implementující nové modulové rozhraní `TypedDocumentIssuer` — precedent `PaymentGatewayRegistry`.
- `DocumentWriter` — vytažená sdílená mechanika z 1.5 (číslo, immutable insert, idempotence `(order_id, type)`, PDF dispatch, unique-violation fallback), typ-agnostická.
- Přejmenování beze změny chování: `GenerateInvoicePdf`→`GenerateDocumentPdf`, `InvoiceIssued`→`DocumentIssued`, `InvoiceQr`→`DocumentQr`.

### Číslování — roční reset

- Core `App\Core\Documents\DocumentNumber` skládá `{PREFIX}{YYYY}{NNNN}` se zero-padem; `SequenceService::nextNumber()` — nový syrový gap-free čítač, `next()` beze změny pro `orders`.
- Rok je součástí series klíče (`invoices:2026`) — čítač se resetuje s kalendářním rokem, žádná migrace (`series` je string).

### Dobropis (`credit_note`)

- Plný storno-dobropis, ruční tlačítko v detailu objednávky, gated: faktura existuje **a** objednávka `cancelled` nebo `refunded`, jinak `CreditNoteNotAllowed` (422).
- `CreditNoteSnapshot` — negace peněz z faktury (položky, `vat_summary`, `total`; sazba DPH `rate` beze změny), odkaz na originál (`corrects_document_id`/`corrects_number`).
- Vlastní číselná řada `credit_notes`, PDF bez QR (dobropis nežádá platbu).

### Proforma (`proforma`)

- Nedaňová výzva k platbě, ruční tlačítko, bez gate. `taxable_at` = null (bez DUZP), patička „Toto není daňový doklad", QR pro převod zachováno.
- Vlastní řada `proformas`; koexistuje s fakturou na jedné objednávce (unique je nově per typ).

### CSV VAT export

- Nový kontrakt `App\Core\Documents\Contracts\DocumentLedger` (`taxableBetween()`) + `NullDocumentLedger` (kernel, guest-safe).
- `VatCsvWriter` — streamovaný CSV, UTF-8 BOM, oddělovač `;` (české Excel locale); typy `invoice`+`credit_note` (proforma vyloučena), dobropis záporně, rozsah podle DUZP.
- **CSV formula injection (CWE-1236) neutralizována** — volné textové sloupce (jméno, IČO, DIČ) escapovány vedoucí uvozovkou při hodnotě začínající `=`/`+`/`-`/`@`; peněžní sloupce vědomě vyjmuty, aby záporná částka dobropisu nezůstala uřezaná jako text.

### Schéma `documents`

- `total` `UNSIGNED BIGINT`→`BIGINT` (dobropis je záporný); unique `(tenant_id, number)`→`(tenant_id, type, number)` (číselné řady jsou per typ); `taxable_at` NOT NULL→nullable (proforma bez DUZP). Alter migrace na již nasazenou tabulku z 1.5.

- **As-is:** [`docs/as-is/2026-07-22-docs-1-6.md`](docs/as-is/2026-07-22-docs-1-6.md)

## [0.14.0] – 2026-07-22

**Fáze 1 / vlna 1.5 — modul `docs`: faktury k objednávkám.** Objednávka konečně dostane fakturu. Nájemce ji vystaví tlačítkem v detailu objednávky, nebo se vystaví sama při zaplacení či expedici (dle nastavení `auto_issue_on`). PDF (A4, QR u nezaplacených, patička) se vygeneruje na pozadí a uloží na privátní disk; zákazník dostane fakturu e-mailem a stáhne si ji ve svém účtu. Doklad je jednou vystavený neměnný. Uzavírá spec §16.6 (base modul).

### Jádro — `app/Core/Documents/`

- Kontrakty `DocumentIssuer` (write: `issue()`, idempotentní) a `DocumentBook` (read: `forOrder()`) — **oddělený read/write split**, stejný vzor jako `OrderBook`/`OrderPlacement`; cizí modul nikdy nesahá na model `Document`.
- `DocumentView` — úzký snímkový tvar (číslo, typ, PDF cesta, total, currency, issued_at, sent_at); `NullDocumentIssuer`/`NullDocumentBook` guest-safe.

### Modul `docs` (base, nelze vypnout)

- `documents` tabulka přesně dle §16.6; unique `(tenant_id, number)` + unique `(tenant_id, order_id, type)` jako DB-level idempotence.
- `Document` — **immutable model**: update povolen jen na `pdf_path`/`sent_at`, delete vždy vyhodí; oprava jen dobropisem (vlna 1.6).
- `InvoiceIssuer` — gap-free číslo přes `SequenceService` v transakci s insertem, idempotence na `(order_id, type)`.
- `GenerateInvoicePdf` — dompdf (`barryvdh/laravel-dompdf`), A4, SPAYD QR pro nezaplacené, uloženo přes `FileStorage::putPrivate()` (`tenant_private`); e-mail zákazníkovi (`MailKind::Transactional`) obalený guardem, aby chyba pošty nespadla vygenerovaný doklad.
- `IssueInvoiceOnPaid`/`IssueInvoiceOnShipped` — naslouchají doménovým eventům `OrderPaymentSettled`/`OrderShipped`, dispatchovaným z `OrderWorkflow` přes **`DB::afterCommit`** (settlement transakci vnoří, inline dispatch by běžel před commitem). Payments/orders nezná modul `docs`.
- Admin (`docs.manage`): vystavit, výpis, stáhnout, znovu odeslat. Zákazník: stažení vlastní faktury přes gated route (`auth:customer` + `customer.session` + vlastnictví přes `OrderBook::findForCustomer`, cizí = 404).
- Plátce vs neplátce DPH = render distinkce v PDF šabloně, ne nový typ enumu; snapshot dodavatele z `tenants.billing_*` v okamžiku vystavení.

### Testy

Nové: `DocsModuleManifestTest`, `InvoiceIssuerTest`, `DocumentImmutabilityTest`, `GenerateInvoicePdfTest`, `InvoiceEmailTest`, `DocumentAdminTest`, `CustomerInvoiceDownloadTest`, `NullDocumentIssuerTest`. Celá suite **858 passed**.

### Mimo rozsah vlny

Dobropis (`credit_note`), CSV VAT export za období, proforma faktura — vlna 1.6. Enum `type` nese všechny tři hodnoty od začátku, 1.5 vystavuje jen `invoice`.

## [0.13.0] – 2026-07-21

**Fáze 1 / vlna 1.4 — modul `payments`: online platební brána Comgate.** Zákazník zaplatí kartou přes Comgate: po odeslání objednávky redirect na bránu, po ověřeném zaplacení `payment_status = paid` a děkovná stránka s potvrzením. Neúspěch/vypršení vrátí sklad a nechá objednávku pro nový nákup. Vypnutý modul nechá pokladnu na dobírku/převod (spec §16.6).

### Jádro — `app/Core/Payments/`

- Kontrakty `PaymentGateway` (driver) + `PaymentGatewayRegistry` (`for($provider)`/`available()`) — **registry/driver architektura**, víc bran koexistuje per tenant; `NullPaymentGatewayRegistry` guest-safe.
- `PaymentResult` + enum `PaymentStatus`, `PaymentInitiation`, jádrová výjimka `GatewayError`.
- Nový kontrakt `App\Core\Orders\Contracts\OrderSettlement` (`attachReference`/`settlePaid`/`settleFailed`) — seam, přes který `payments` mění stav a vrací sklad bez sahání do `OrderWorkflow`.

### Modul `payments`

- `ComgateGateway` (v1.0 e-commerce HTTP-POST protokol, `Http` fasáda, bez composer balíčku), `EloquentPaymentGatewayRegistry`, `ComgateSignature`.
- `PaymentSettlement` (verify-before-trust), controllery `/platba/navrat` a `/platba/notifikace` (mimo CSRF, podpis brány), job `ExpireUnpaidOrder`.

### Bezpečnost

- `payment_status = paid` jen po server-to-server `verify()` — podvržený návrat ani webhook payload nic nesettluje; kontrola částky; reference vázaná na objednávku serverově.
- Idempotence duplicitní notifikace (webhook + návrat) přes `from==to` no-op + `lockForUpdate`.
- Credentials brány `encrypted:array`, maskované, keep-on-update.

### Testy

Nové: `PaymentGatewayRegistryTest`, `ComgateGatewayTest`, `PaymentCallbackTest`, `ExpireUnpaidOrderTest`; rozšířeny `OrderWorkflowTest`, `PaymentMethodAdminTest`, `CheckoutRedirectTest`. Celá suite **813 passed**.

## [0.12.0] – 2026-07-21

**Fáze 1 / vlna 1.3 — etapy 4+5 (sloučené): moduly `checkout` + `orders`.** Zákazník projde nákup od detailu produktu po děkovnou stránku bez zapnutého JavaScriptu, vznikne reálná objednávka a nájemce ji v adminu vidí, edituje, mění oba stavy a stornuje. Uzavírá MVP cíl vlny 1.3 (spec §3.1, §16.3, §16.4).

### Jádro — tři nové kontrakty po vzoru `ProductCatalog`

- `App\Core\Checkout\Contracts\CartRepository` (+ shape `CartShape`) — guest-safe null binding (`NullCartRepository`/`TransientCart`), přebitý modulem `checkout`
- `App\Core\Orders\Contracts\OrderPlacement` (+ shape `PlacedOrder`) a `OrderBook` (+ shape `OrderView`) — psaní a čtení objednávek jsou dva různé kontrakty (jiné invarianty: odeslání je jeden atomický zápis s idempotencí, čtení je „moje objednávky" vs. „admin výpis")
- Žádný z modulů nedeklaruje `requires` na druhý ani na `shipping` — `checkout` volá `app(OrderPlacement::class)` (null odmítne odeslání) a `ShippingOptions`/`PaymentOptions` (null → nouzovka „osobní odběr zdarma"). Runtime gate přes `ShopModules`, ne manifest, stejný precedent jako `CustomerIdentity` z etapy 2
- `CatalogProduct` rozšířen o `catalogTaxRatePercent()` — sazba DPH ke snímku řádku objednávky se čte z katalogu, ne z ceníku samostatně

### Modul `checkout` — košík a pokladna (Blade SSR, `noindex`)

- `carts`/`cart_items`, košík vázaný na `carts.token` (kryptograficky náhodný cookie), volitelně na přihlášeného zákazníka
- Po přihlášení zákazníka se anonymní košík připojí k účtu (`CartMerger` na `Login` eventu guardu `customer`) — stejný produkt sečte množství, nepřepíše; přihlášení bez anonymního cookie znovu nasměruje cookie na uložený košík zákazníka
- `/kosik`, `/pokladna/doprava`, `/pokladna/udaje`, `/dekujeme/{uuid}` — celý tok funguje bez JS; **veškerá cenová logika na serveru** (`CartPricer`), podvržená cena/doprava v POST se ignoruje
- Změna ceny mezi vložením do košíku a odesláním zobrazí banner a přepočte (`PriceChanged`), nikdy nenaúčtuje starou cenu
- SPAYD QR pro platbu převodem jako inline SVG (`endroid/qr-code ^6.0`, `SvgWriter`, bez GD) — účet se čte živě z platební metody, nikdy ze snímku objednávky (žádný credential ve snímku, spec §16.5)
- Potvrzovací e-mail zákazníkovi i nájemci a stavové e-maily přes kernel `MailService`, vždy `MailKind::Transactional`
- `/kosik`, `/pokladna/*`, `/dekujeme/*` vyřazeny z page cache hlavičkou `Cache-Control: private, no-store` (page cache jako taková ještě neexistuje — provizorní řešení, viz Odchylky)

### Modul `orders` — perzistence a admin (Inertia, `resources/js/Pages/Modules/Orders/`)

- `orders`/`order_items`/`order_events`, číslo objednávky přes gap-free `SequenceService::configure('orders')` (běží v `Lifecycle::onActivate()`, ne v `boot()` — tenant kontext v tu chvíli ještě neexistuje)
- `OrderPlacer::place()` — jedna DB transakce: idempotence podle `(cart_id, checkout_token)` první, přepočet každého řádku z `ProductCatalog::price()` (nikdy z `cart_items.unit_price`, který je jen snímek), odpis skladu (`decrementStock`, atomický `UPDATE`) uvnitř téže transakce jako zápis objednávky — objednávka, která nevezme sklad, nesmí vzniknout, a naopak. Souběh na posledním kusu: prohraný požadavek dostane `UniqueConstraintViolationException`, dohledá už vzniklou objednávku a vrátí ji místo 500
- `OrderWorkflow` vynucuje dvojitý stavový automat (`fulfillment_status` × `payment_status`, nezávislé grafy, nezávislé `order_events` záznamy) — kontrola nelegálního přechodu proběhne čistě v paměti před jakýmkoli dotazem, takže není co vracet
- Admin: výpis s filtrem a hledáním, detail s položkami/adresami/historií, editace existujících řádků (sklad podle delty), ruční založení objednávky, storno s přesným vrácením skladu. Oprávnění `orders.view`/`orders.edit`/`orders.cancel`
- `EloquentOrderBook::forCustomer`/`findForCustomer` — čtení pro účet zákazníka, tenant + vlastnictví scoped

### Účet zákazníka — historie objednávek

- `/ucet/objednavky` (seznam) a `/ucet/objednavky/{uuid}` (detail) v `Modules/Customers`, nahrazují placeholder z etapy 2. Blade SSR, `noindex`, za guardem `customer`
- Detail čte přes `OrderBook::findForCustomer(customerId, uuid)` — vlastnictví, ne jen znalost UUID: cizí objednávka (jiný zákazník i jiný tenant) vrátí `null` → 404, stejně jako cizí `customer_address`

### Testy

Celá sada **775 passed** (bylo 656 na startu etapy 4/5, 762 po Task 8, 770 po historii objednávek). Nově `AccountOrdersTest` (8) a `CartMergeOnLoginTest` (5), oprava `CustomerAccountTest` (placeholder → odkaz na historii objednávek).

### Mimo rozsah etapy

- Online platební brána, webhook, `/platba/navrat` — vlna 1.4, poběží na `payment_snapshot` a stavu `payment`
- Faktury, PDF, číselné řady dokladů — vlna 1.5, poběží na hotových `orders`
- Manuální Lighthouse a11y check na `/kosik` a pokladně — pre-deploy checklist, nebylo možné spustit v implementačním prostředí

## [0.11.0] – 2026-07-21

**Fáze 1 / vlna 1.3 — etapa 3: modul `shipping`.** Nájemce si v adminu definuje, jak jeho e-shop doručuje a přijímá platby — způsoby dopravy (osobní odběr, paušální dopravce), způsoby platby (dobírka, převod s QR) a matici, která platba patří ke které dopravě. Modul je admin-only; volby renderuje až budoucí checkout. Online platební brány jsou vlna 1.4.

### Datový model a jádro

- Tři tenant-scoped tabulky: `shipping_methods`, `payment_methods` a pivot `shipping_method_payment_method`. Ceny jako celé haléře (`MoneyCast` + companion sloupec `currency`), DPH nese `TaxRate`, ne `Money`
- `payment_methods.settings` je **šifrované at rest** (`encrypted:array`) — bankovní účet pro QR je credential podle §16.5. První tenant-scoped použití `encrypted` castu; sloupec je `text`, ne `json`, protože cast píše opaque ciphertext
- Dva jádrové kontrakty `App\Core\Shipping\Contracts\ShippingOptions` a `PaymentOptions` (+ read-only shapes `ShippingOption`/`PaymentOption`, které modely implementují přímo) — jak si checkout vyžádá aktivní, správně filtrované volby, aniž by sahal na tabulky modulu
- Guest-safe null bindingy v jádře; modul je přebíjí a každá implementace se ptá `ShopModules->has('shipping')` za běhu, takže deaktivovaný modul odpoví prázdno bez `requires` v manifestu (precedent `CustomerIdentity` z etapy 2)

### Admin (Inertia, `resources/js/Pages/Modules/Shipping/`)

- CRUD způsobů dopravy i platby s řazením tlačítky ovladatelnými klávesnicí (WCAG 2.1.1, ne drag&drop jako jediná cesta)
- Účet pro QR se adminovi vrací jen **maskovaný** (poslední 4 znaky) + afordance „změnit"; writer přepíše `settings`, jen když admin pošle novou hodnotu — otevření a uložení formuláře beze změny účet nevymaže
- Matice doprava × platba jako checkbox mřížka; **prázdná řada = všechny aktivní platby povoleny** (jinak by nedotčená obrazovka udělala e-shop, který nepřijme objednávku). Uložení nahradí pivot řádky v transakci, tenant-scoped
- Oprávnění `shipping.manage`, položka v adminní navigaci

### Mimo rozsah etapy

- Žádný storefront povrch — volby dopravy a platby vykreslí až modul `checkout` (etapa 4), který tyto kontrakty spotřebuje
- Online platební brány (Comgate/GoPay) — vlna 1.4; proto je šifrování settings připravené už teď
- Váhový strop metody (`max_weight_g`) filtruje v `available()`, ale samotnou váhu košíku dodá až checkout

## [0.10.1] – 2026-07-21

**Bezpečnostní záplata etapy 2 (předmergová).** Finální revize větve našla řetězec vedoucí k převzetí účtu a několik děr kolem GDPR výmazu; opraveno před sloučením do `main`.

- **Resetovací token přežíval výmaz zákazníka.** Výmaz uvolnil e-mailovou adresu z unikátního indexu, novou registrací ji obsadil jiný člověk a starý resetovací odkaz původního zákazníka pak přepsal heslo tomu novému a přihlásil útočníka pod jeho účet. `CustomerEraser` teď v téže transakci maže všechny tokeny původní adresy. Incident: [`2026-07-21-error-01`](docs/superpowers/errors/2026-07-21-error-01-token-prezije-vymaz-zakaznika.md)
- Výmaz redaguje adresu i v `mail_messages.recipients` (řádky zůstávají kvůli počítadlu `emails_month`); `customer_tokens` se čistí při expiraci i denním commandem `customers:prune-tokens`
- `CustomerIdentity` má jádrovou null-implementaci (checkout poběží i na e-shopu bez modulu) a ptá se `ShopModules` za běhu; přibylo `findById()` pro rehydrataci `carts.customer_id`
- Reset hesla vyhazuje ostatní session přes vlastní `AuthenticateCustomerSession` (Laravelí `AuthenticateSession` je natvrdo na guardu `web`)
- Přihlášený zákazník na `/prihlaseni` míří na `/ucet`, ne na staffovský dashboard; hlavička e-shopu konečně odkazuje na účet/přihlášení

## [0.10.0] – 2026-07-21

**Fáze 1 / vlna 1.3 — etapa 2: modul `customers`.** Koncoví zákazníci e-shopu dostávají vlastní identitu — registrace, přihlášení, reset hesla, verifikace e-mailu, účet a admin s GDPR výmazem.

### Guard a datový model

- Čtvrtý guard `customer` nad tabulkou `customers` (tenant-scoped, `BelongsToTenant`); stejná e-mailová adresa u dvou e-shopů jsou dva nesouvisející účty, unikátní index `(tenant_id, email)`
- `customer_addresses` (fakturační/dodací) a `customer_tokens` (reset hesla, verifikace)
- Vlastní `CustomerTokens` — tenant-scoped, hash-only, jednorázové, expirující tokeny nad `(tenant_id, email, purpose)`; Laravelí password broker nejde použít, protože `password_reset_tokens` má primární klíč jen `email`
- `AnonymisedCustomerProvider` (driver `customer-eloquent`) vyřazuje anonymizované účty ze všech cest, kterými guard dohledává uživatele — session, remember-me, přihlášení. Admin dál anonymizované zákazníky vidí, filtr sedí jen na autentizační cestě

### Storefront (Blade SSR, `noindex`)

- `/registrace`, `/prihlaseni`, `/odhlaseni`, `/zapomenute-heslo`, `/obnova-hesla/{token}`, `/overeni-emailu/{token}`, `/overeni-emailu/znovu`
- `/ucet`, `/ucet/udaje`, `/ucet/adresy` + editace a potvrzovací stránka smazání adresy (GET krok, ne JS `confirm()`) — celý tok funguje bez JavaScriptu
- Historie objednávek je vyznačený placeholder — čeká na modul `orders`

### Mail a rate limiting

- Verifikační e-mail a reset hesla jdou přes kernel `MailService` z etapy 1, vždy jako `MailKind::Transactional`
- Přihlášení, reset hesla i verifikace mají explicitní decay okna (ne implicitní minuta Laravelu), klíčované tenantem a adresou/IP — lockout na jednom e-shopu neuzamkne stejnou osobu na jiném

### Admin (Inertia, `resources/js/Pages/Modules/Customers/`)

- Výpis, detail, JSON export (právo na přenositelnost)
- GDPR výmaz — `CustomerEraser` anonymizuje místo mazání (objednávky budou na řádek odkazovat cizím klíčem), transakčně, idempotentně, s auditním záznamem
- Oprávnění `customers.view` a `customers.erase`, položka v adminní navigaci

### Jádro

- Kontrakt `App\Core\Customers\Contracts\CustomerIdentity` (+ `CustomerAccount`) — jak si budoucí modul `checkout` připojí košík k přihlášenému zákazníkovi; `findByEmail()` úmyslně přeskakuje anonymizované účty
- `EnsureTenantMember` napevno na guard `web`; guest redirect (`redirectGuestsTo`) čte middleware namatchované routy, takže zákazník skončí na `/prihlaseni`, ne na staffovském `/login`

### Mimo rozsah etapy

- Verifikace e-mailu se nikde nevynucuje — nic není podmíněno `email_verified_at`; jestli to bude vyžadovat checkout, rozhodne až jeho etapa
- Historie objednávek v účtu — čeká na modul `orders`

## [0.9.2] – 2026-07-20

**Fáze 1 / vlna 1.3 — etapa 1: MailService.** Jádrová služba pro odesílání e-mailu jménem tenanta — první konkrétní volající pro `emails_month` v `LimitsService`.

- Kontrakt `MailService` + implementace `QueuedMailService` — tenant se dořeší (explicitní argument vyhrává nad ambientním kontextem) a celý běh (kvóta, log, identita odesílatele) jede uvnitř `TenantContext::runAs()`
- `SendTenantMail` — fronta doručení; při chybě během opakování se zapisuje jen text chyby, stav `failed` nastaví jedině Laravelí `failed()` hook (na sync driveru `attempts()` vrací natvrdo 1, takže by podmínka na poslední pokus nikdy nesepnula)
- `TenantSender` — obálková adresa vždy platformní (SPF/DKIM), tenant dodává jen display name a reply-to; nové sloupce `tenants.mail_from_name` a `tenants.mail_reply_to`
- `MailKind` — povinný argument kontraktu, `Transactional` nebo `Bulk`. Vyčerpaný limit nikdy nezastaví potvrzení objednávky ani reset hesla; transakční pošta se počítá, ale neblokuje. Druh se ukládá do `mail_messages.kind`, aby log ukázal, proč zpráva odešla přes strop
- `MailLimitCounter` — počítadlo `emails_month` nad `queued` i `sent` v aktuálním kalendářním měsíci (klíčem `queued_at`), zaregistrované v `AppServiceProvider`
- Model `MailMessage` nad tabulkou `mail_messages` (tenant-scoped)
- **Mimo rozsah etapy:** šablony e-mailů (verifikace, reset hesla, potvrzení objednávky) — přijdou s moduly `customers` a `orders`; `EventBus` zůstává odloženo

## [0.9.0] – 2026-07-20

**Fáze 1 / vlna 1.2 — storefront katalogu.** E-shop nájemce je poprvé veřejně dostupný: homepage, kategorie, detail produktu a vyhledávání renderované serverem, se SEO výstupy podle závazného pravidla storefrontu.

### Nový modul `storefront`

- Layout e-shopu (skip link, navigace kořenových kategorií, hledání, patička), homepage, `/hledani`
- Blade komponenty `seo-meta`, `json-ld`, `breadcrumbs`, `product-card`, `product-grid`, `sort-form`
- Chybové stránky v šabloně e-shopu; bez tenanta se degraduje na prostý HTML
- `sitemap.xml` a `robots.txt` per tenant; e-shop, který neobchoduje, dostane `Disallow: /`

### Veřejný katalog

- `/kategorie/{slug}` — výpis celého podstromu, stránkování 24, řazení a filtr „skladem" přes query parametry (funguje bez JS)
- `/produkt/{slug}` — galerie, cena s DPH i bez, dostupnost, popis
- JSON-LD `Product`+`Offer`, `BreadcrumbList`, `ItemList`, `Organization`+`WebSite`; canonical, OG a Twitter meta, `rel=prev/next`
- `noindex` na výsledky hledání a na filtrované kombinace

### SEO a chybové stavy

- **Přejmenovaný slug konečně odpovídá 301.** `redirects` se zapisovaly od vlny 1.1, ale nic je neservírovalo — obsluha visí na handleru 404, takže úspěšná cesta nenese DB dotaz navíc
- Stažený (soft-deleted) produkt vrací **410** se stránkou „produkt už není v nabídce" a odkazem do kategorie
- 404 se renderuje v šabloně e-shopu

### Jádro

- Kontrakt `StorefrontHome` — kořenová routa zůstává v jádře a deleguje ji šabloně
- `ProductQuery` + rozšíření `ProductCatalog` o `latest()` a `paginate()`; `CatalogProduct` o obrázek, krátký popis a URL
- `RedirectResponder` — servírování redirectů včetně dohledání tenanta z hostu

### Modul `products`

- Normalizovaný sloupec `search_text` (lowercase, bez diakritiky) plněný při zápisu + command `products:reindex-search`
- Vyhledávání ho používá, takže „cerna bunda" najde „Černá bunda"

### Assety

- Samostatný storefront bundle (JS 250 B gzip, CSS 9,8 kB gzip), Tailwind vidí Blade v `Modules/`

## [0.8.0] – 2026-07-20

**Fáze 1 / vlna 1.1 — jádro katalogu.** Nájemce spravuje strom kategorií a produkty s cenami, DPH, skladem, obrázky a SEO poli ve vlastním adminu.

### Bezpečnost

- **Opravena díra v admin routách modulů.** Byly montované jen s `web` a modulovým gate, takže kdokoli bez přihlášení mohl číst a zapisovat cizí e-shop. Týkalo se i nasazeného modulu `Pages`. Nový middleware `EnsureTenantMember` ověřuje přihlášení a členství v e-shopu, na jehož hostu request dorazil.
- **Oprávnění z manifestů začala platit.** `TenantPermissions` odvozuje sadu práv e-shopu z manifestů modulů, které běží; `Gate::before` z ní odpovídá na `$user->can()`. Právo vypnutého modulu nedostane nikdo, ani vlastník.
- **Vlastní `HtmlSanitizer`** (whitelist tagů, atributů a URL schémat nad `DOMDocument`). Popisy produktů se čistí při zápisu.
- **Nákupní cena** se zahazuje z validovaných dat a neopouští server bez práva `products.costs`.
- **Obrázky se při nahrání otevírají**, ne jen kontrolují podle přípony — HTML soubor přejmenovaný na `.jpg` by se jinak servíroval z originu e-shopu.

### Jádro

- Číselník sazeb DPH (`tax_rates`, promile jako integer); převody `net`/`gross`/`vat` na `TaxRate`
- Tabulka a služba `redirects` — 301 po přejmenování, řetězce se kolabují při zápisu
- `AdminLayout` — shell adminu nájemce, navigace z manifestů modulů, sdílené Inertia props
- Kontrakt `ProductCatalog` + `CatalogProduct` v jádře, implementace v modulu
- Service providery modulů se načítají z disku (`Modules/*/Providers/ModuleProvider.php`)
- Sdílené UI komponenty přesunuty z `Components/Platform` do `Components/Ui`

### Modul `categories`

- Strom (adjacency list + materializovaná cesta), max 4 úrovně, bez cyklů
- Admin: výpis, inline editace, přesun, řazení tlačítky (ovladatelné klávesnicí), mazání s povinným cílem pro podkategorie

### Modul `products`

- Produkty, výrobci, obrázky, vazba na kategorie s hlavní kategorií
- Cena hrubá + sazba; net a DPH se dopočítávají
- Atomický `decrementStock` jedním podmíněným `UPDATE`
- Soft delete; smazané produkty nepočítají do limitu tarifu
- Admin: seznam s filtry a stránkováním, karta se záložkami Základní / Ceny / Obrázky / Sklad / SEO
- Validace EAN-8/13 včetně kontrolní číslice

### Mimo rozsah vlny

Varianty, CSV import/export, generování řezů obrázků, hromadné operace, storefront rendering.

## [0.7.0] – 2026-07-20

**Fáze 0 / vlna 0.6 — superadmin management UI.** Platformu lze spravovat z prohlížeče: tenanti, stavy, tarify, moduly, kill switch.

- Výpis tenantů s filtry (stav, tarif, hledání dle jména/domény/IČO) a stránkováním; detail adresovaný přes UUID
- Detail tenanta: stav, tarif, domény, uživatelé, moduly, čerpání limitů, posledních 20 záznamů auditu
- Změna stavu podle mapy povolených přechodů v `TenantStatus`; důvod povinný u pozastavení a čekání na smazání; `deleted` nelze nastavit ručně
- Změna tarifu přes `PlanSwitcher` — **downgrade vypne moduly, které nový tarif nekryje**, i jejich závislé; UI ukáže dopad předem
- Aktivace a deaktivace modulů per tenant přes `ModuleRegistry` (plán, závislosti a core status dál hlídá registry)
- **`ModuleKillSwitch`** — jediná zápisová cesta k `modules.enabled_globally`; zahodí cache registru, vynutí důvod, zapíše audit. Přebíjí i core moduly (nouzová brzda)
- **Oprava:** `AuditLog` bral `user_id` z naposledy použitého guardu, takže superadmin akce shodila cizí klíč nebo ukázala na cizí osobu. Nyní guard `web` + identita superadmina v `meta`
- Impersonace vrací `Inertia::location()` — spouští se z Inertia stránky
- Vlastní UI komponenty (`PlatformLayout`, `DataTable`, `Pagination`, `StatusBadge`, `ConfirmDialog`, `FilterBar`) — žádná nová JS závislost
- Nová brána izolace: `PlatformRouteIsolationTest` trvá na `platform.host`, `auth:platform` a `platform.2fa` u každé `platform.*` routy
- **Odloženo:** metriky a MRR (čeká na fakturaci), zakládání a mazání tenantů z UI, editace tarifů, prohlížeč auditu
- **As-is:** [`docs/as-is/2026-07-20-superadmin-ui.md`](docs/as-is/2026-07-20-superadmin-ui.md)

## [0.6.0] – 2026-07-19

**Fáze 0 / vlna 0.5 — superadmin auth jádro.** Správce platformy s odděleným účtem, povinným 2FA a auditovanou impersonací.

- Oddělená tabulka `platform_admins` + guard `platform` — sdílí nic s `users`
- Přihlášení jen na platformním hostu (na doméně tenanta 404), rate limit 5/min + lockout
- Povinné 2FA (TOTP + jednorázové recovery kódy, šifrované/hashované), dvě brány přes middleware
- **Impersonace** přes podepsaný handoff mezi hosty (různé session cookies); 30 min expirace, `impersonated_by` v každém audit zápisu, banner v UI
- `platform:create-admin` — interaktivní zřízení superadmina (žádné údaje v seederu)
- Balíček `pragmarx/google2fa`
- **Odloženo:** management UI (výpis tenantů, metriky), HIBP kontrola hesla, IP allowlist
- **As-is:** [`docs/as-is/2026-07-19-superadmin-auth.md`](docs/as-is/2026-07-19-superadmin-auth.md)

## [0.5.0] – 2026-07-19

**Fáze 0 / vlna 0.4 — FileStorage.** Modul umí uložit a servírovat soubor přes službu jádra, aniž zná disk. Soubory zůstávají na naší VPS (lokální disk, ne S3).

- `FileStorage` — dva disky (`tenant_public` web-served, `tenant_private` jen přes podpis); každá cesta vynuceně pod `tenants/{id}/`
- `PathGuard` — odmítá traversal ve všech podobách (samostatná pojistka)
- Privátní soubory přes `URL::temporarySignedRoute` na doméně tenanta; podpis váže host i tenant param
- `StorageLimitCounter` — první konkrétní počítadlo pro `LimitsService`; upload nad limit tarifu se odmítne
- **Rozhodnutí 2026-07-19:** úložiště lokální, ne S3 (změna „S3 od začátku"); abstrakce drží swap na S3 jako změnu configu
- **As-is:** [`docs/as-is/2026-07-19-filestorage.md`](docs/as-is/2026-07-19-filestorage.md)

## [0.4.0] – 2026-07-19

**Fáze 0 / vlna 0.3 — kernel služby.** Pět služeb jádra a vynucení tarifu při aktivaci modulu.

- `Money` — integer haléře, dělení bez ztráty haléře, zákaz míchání měn
- `SettingsService` — per-tenant nastavení, validace proti schématu z manifestu, cache
- `LimitsService` — allow/warn/block, počítadla přes kontrakt `LimitCounter`, override z `plan_modules`
- `SequenceService` — číselné řady bez děr, dokázáno souběhovým testem 4 procesů; atomický `UPDATE ... LAST_INSERT_ID`
- `FeatureFlags` — global / whitelist / deterministické procento
- **Aktivace modulu respektuje tarif** — zavřená mezera z vlny 0.2; tenant bez tarifu si zapne jen core moduly
- **Odloženo:** `FileStorage`, `MailService`, `EventBus` — čekají na výběr provideru a prvního skutečného volajícího
- **As-is:** [`docs/as-is/2026-07-19-kernel-sluzby.md`](docs/as-is/2026-07-19-kernel-sluzby.md)

## [0.3.0] – 2026-07-19

**Fáze 0 / vlna 0.2 — systém modulů.** Modul jde nasadit, zaregistrovat, per tenanta zapnout a vypnout; když ho tenant nemá, jeho routy pro něj neexistují.

- Manifest (`module.json`) s validací — neplatný manifest shodí `modules:sync` celý, nikdy nezapíše polovičatý záznam
- `DependencyResolver` — topologické, deterministické řazení; cykly a nesplněné semver rozsahy hlásí chybu
- `ModuleRegistry` — aktivace dotáhne závislosti, deaktivace nic nemaže, kill switch přebíjí i core moduly
- Routy z disku, povolení z DB; middleware `module:{key}` vrací **404, ne 403**
- `NavigationBuilder` skládá admin menu z manifestů
- Referenční modul **Pages** — důkaz celého řetězu včetně Blade SSR a serverem renderovaných SEO tagů
- Balíček `composer/semver` přidán
- **Odchylka:** odinstalace modulu (`onUninstall`) odložena — rozhodnutí 2026-07-19
- **As-is:** [`docs/as-is/2026-07-19-system-modulu.md`](docs/as-is/2026-07-19-system-modulu.md)

## [0.2.0] – 2026-07-19

**Fáze 0 / vlna 0.1 — tenancy jádro.** Rozpoznání tenanta z Host hlavičky, datová izolace vynucená na modelech, propagace kontextu do jobů, audit log, CI s izolací jako samostatnou branou.

- Datový model jádra dle spec §15.3 (`tenants`, `domains`, `tenant_users`, `plans`, `audit_log`, `jobs_log`)
- Middleware pipeline `ResolveHost` → `CheckTenantStatus` → `SetTenantContext` (spec §15.2)
- `BelongsToTenant` + `TenantScope`; dotaz bez kontextu hodí `MissingTenantContext` místo tichého vrácení dat všech tenantů
- `SchemaConventionTest` shodí build, když doménová tabulka přijde bez `tenant_id`
- Balíčky: `spatie/laravel-multitenancy ^4.1` přidán, `stripe/stripe-php` odstraněn
- Lokální konfigurace přes `.env.local` (načítá `bootstrap/app.php`)
- **As-is:** [`docs/as-is/2026-07-19-tenancy-jadro.md`](docs/as-is/2026-07-19-tenancy-jadro.md)
- **Plán:** [`docs/superpowers/plans/2026-07-19-faze-0-vlna-01-tenancy-jadro.md`](docs/superpowers/plans/2026-07-19-faze-0-vlna-01-tenancy-jadro.md)

## [0.1.0] – 2026-07-19

**Bootstrap.** Laravel skeleton + napojení na GitHub + AI/docs struktura (`claude-laravel-vue` + WooShop vzor) + produktová specifikace v `docs/specs/`.

- **As-is:** [`docs/as-is/2026-07-19-bootstrap.md`](docs/as-is/2026-07-19-bootstrap.md)
