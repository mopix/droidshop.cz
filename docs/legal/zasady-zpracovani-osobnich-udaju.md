# Zásady zpracování osobních údajů

Účinnost od: **{{ effective_from }}**

> Draft bez právní revize.

Tento dokument popisuje, jak nakládáme s osobními údaji **nájemců** — tedy lidí, kteří si u nás pronajímají e-shop. V tomto vztahu jsme **správce**.

**Údaje koncových zákazníků e-shopů našich nájemců tento dokument neupravuje.** U nich jsme zpracovatel a správcem je nájemce; podmínky jsou ve [zpracovatelské smlouvě](zpracovatelska-smlouva.md).

## 1. Správce

**{{ company.name }}**, IČO **{{ company.ico }}**, se sídlem {{ company.address }}.

Kontakt: {{ company.email }}

Pověřence pro ochranu osobních údajů jsme nejmenovali — nesplňujeme podmínky čl. 37 GDPR, které jeho jmenování vyžadují.

## 2. Jaké údaje zpracováváme

| Kategorie | Konkrétně | Odkud |
|---|---|---|
| Identifikační | jméno, název firmy, IČO, DIČ | od vás při registraci a v nastavení fakturace |
| Kontaktní | e-mail, telefon, fakturační adresa | od vás |
| Přístupové | hash hesla, údaje o přihlášení | vzniká při registraci |
| Fakturační | vystavené doklady, zaplacené částky, období | vzniká provozem |
| Provozní | IP adresa, čas požadavku, záznamy v protokolu změn | vzniká provozem |

Údaje o platební kartě **nezpracováváme a nemáme k nim přístup** — sbírá je přímo Stripe na svém formuláři.

## 3. Proč je zpracováváme a na jakém základě

| Účel | Právní titul | Doba uchování |
|---|---|---|
| Poskytování služby, správa účtu | plnění smlouvy (čl. 6/1/b) | po dobu smlouvy + 30 dnů |
| Fakturace a účetnictví | plnění právní povinnosti (čl. 6/1/c) | **10 let** od konce zdaňovacího období (zákon o DPH, zákon o účetnictví) |
| Zabezpečení, protokol změn, řešení incidentů | oprávněný zájem (čl. 6/1/f) | 12 měsíců |
| Vymáhání pohledávek | oprávněný zájem (čl. 6/1/f) | po dobu promlčecí lhůty |
| Obchodní sdělení stávajícím nájemcům | oprávněný zájem (čl. 6/1/f) | do vznesení námitky |

Zpracování pro účely plnění smlouvy a právní povinnosti je nezbytné — bez něj službu poskytnout nelze.

## 4. Komu údaje předáváme

Předáváme jen v rozsahu, který daný účel vyžaduje:

| Příjemce | Co | Proč |
|---|---|---|
| Stripe Payments Europe, Ltd. | e-mail, jméno, fakturační údaje, částky | zpracování plateb za předplatné |
| poskytovatel serverové infrastruktury | vše uložené v databázi a na disku | provoz služby |
| poskytovatel e-mailové brány | e-mailová adresa, obsah zpráv | doručení transakčních e-mailů |
| účetní | doklady a fakturační údaje | vedení účetnictví |
| orgány veřejné moci | dle jejich zákonného požadavku | plnění právní povinnosti |

Aktuální seznam zpracovatelů je uveden ve [zpracovatelské smlouvě](zpracovatelska-smlouva.md), oddíl Podzpracovatelé.

**Do zemí mimo EU** údaje nepředáváme, ledaže to vyplývá z využití některého ze zpracovatelů výše. V takovém případě je předání kryto standardními smluvními doložkami Evropské komise.

**Údaje neprodáváme** a nepředáváme je pro marketingové účely třetích stran.

## 5. Vaše práva

Máte právo:

- **na přístup** — vědět, jaké údaje o vás zpracováváme, a získat jejich kopii,
- **na opravu** — nechat opravit nepřesné údaje (většinu si můžete opravit sami v administraci),
- **na výmaz** — v případech, kdy pro zpracování nemáme jiný důvod; nevztahuje se na údaje, které musíme uchovávat podle zákona (typicky vystavené doklady),
- **na omezení zpracování**,
- **na přenositelnost** — dostat údaje ve strojově čitelném formátu,
- **vznést námitku** proti zpracování na základě oprávněného zájmu,
- **odvolat souhlas**, pokud je zpracování na souhlasu založeno; odvoláním není dotčena zákonnost zpracování před odvoláním.

Uplatnit je můžete na {{ company.email }}. Odpovíme **do jednoho měsíce**; ve složitých případech lhůtu prodloužíme a dáme vám vědět.

Máte také právo **podat stížnost u Úřadu pro ochranu osobních údajů** (Pplk. Sochora 27, 170 00 Praha 7, [uoou.gov.cz](https://uoou.gov.cz)).

## 6. Zabezpečení

- přenos výhradně přes HTTPS,
- hesla ukládáme jen jako hash, nikdy v čitelné podobě,
- přístupové údaje k platebním branám a dopravcům jsou v databázi šifrované,
- data jednotlivých e-shopů jsou od sebe oddělená na úrovni aplikace a každý dotaz je vázán na konkrétní e-shop,
- přístup do administrace platformy je chráněn dvoufaktorovým ověřením,
- pravidelné zálohy.

Žádné opatření nedává absolutní jistotu. Zjistíme-li porušení zabezpečení s rizikem pro vaše práva, ohlásíme je Úřadu do 72 hodin a při vysokém riziku vyrozumíme i vás.

## 7. Automatizované rozhodování

Neprovádíme automatizované rozhodování ani profilování, které by pro vás mělo právní účinky.

## 8. Změny

Tyto zásady můžeme aktualizovat. Věcnou změnu oznámíme e-mailem před nabytím účinnosti.
