# Smlouva o zpracování osobních údajů

Účinnost od: **{{ effective_from }}**

> Draft bez právní revize. Tento dokument má nejvyšší právní riziko z celé sady — bez platné zpracovatelské smlouvy je zpracování protiprávní pro obě strany.

Tato smlouva je uzavřena podle **čl. 28 nařízení (EU) 2016/679 (GDPR)** mezi:

- **správcem** — nájemcem, který provozuje e-shop na platformě DroidShop.cz,
- **zpracovatelem** — {{ company.name }}, IČO {{ company.ico }}, se sídlem {{ company.address }}.

Smlouva je nedílnou součástí [Všeobecných obchodních podmínek](vseobecne-obchodni-podminky.md) a je uzavřena okamžikem jejich přijetí. Samostatný podpis se nevyžaduje.

## 1. Kdo je kdo

**Správcem je nájemce.** On rozhoduje, jaké údaje svých zákazníků shromažďuje a proč. On odpovídá za informační povinnost vůči nim, za právní titul zpracování a za vyřizování jejich žádostí.

**Zpracovatelem je poskytovatel platformy.** Údaje zákazníků nájemce zpracovává výhradně proto, že mu je nájemce svěřil provozem e-shopu, a jen podle jeho pokynů.

Zpracovatel s těmito údaji **nenakládá pro vlastní účely** — neanalyzuje je, nepředává je pro marketing a nepoužívá je k tvorbě vlastních produktů.

## 2. Předmět a doba

Zpracovatel zpracovává osobní údaje po dobu trvání smlouvy o poskytování služby a po ni v rozsahu článku 9.

## 3. Kategorie subjektů údajů

- zákazníci e-shopu nájemce (registrovaní i nakupující bez registrace),
- osoby, které si u nájemce vyžádaly obnovu hesla nebo ověření e-mailu,
- příjemci zásilek, liší-li se od kupujícího.

## 4. Kategorie zpracovávaných údajů

| Kategorie | Konkrétně |
|---|---|
| Identifikační | jméno a příjmení, u podnikatele název, IČO, DIČ |
| Kontaktní | e-mail, telefon, fakturační a dodací adresa |
| Transakční | objednávky, jejich obsah a hodnota, doklady, stav platby |
| Doručovací | výdejní místo, číslo zásilky |
| Přístupové | hash hesla zákaznického účtu, tokeny pro obnovu hesla |
| Provozní | IP adresa, čas požadavku |

**Zvláštní kategorie údajů podle čl. 9 GDPR** (zdravotní stav, biometrie, náboženské vyznání a další) platforma nepodporuje a nájemce je do ní nesmí ukládat. Uloží-li je přesto, činí tak na vlastní odpovědnost a mimo rámec této smlouvy.

## 5. Pokyny správce

Zpracovatel zpracovává údaje jen na základě doložených pokynů správce. Za pokyn se považuje i **samotné užívání platformy** — nastavení e-shopu, aktivace modulů, konfigurace dopravců a plateb.

Zpracovatel správce upozorní, pokud podle jeho názoru pokyn porušuje GDPR nebo jiný předpis o ochraně údajů.

Zpracovatel může údaje zpracovat i tehdy, ukládá-li mu to právo EU nebo členského státu; v takovém případě správce předem informuje, ledaže to dané právo zakazuje.

## 6. Mlčenlivost

Zpracovatel zajistí, že osoby oprávněné zpracovávat údaje jsou vázány mlčenlivostí, a to i po skončení své činnosti.

## 7. Zabezpečení

Zpracovatel přijal zejména tato opatření:

- šifrovaný přenos (HTTPS) na všech veřejných i administračních rozhraních,
- **izolace dat jednotlivých e-shopů** na úrovni aplikace — každý databázový dotaz je vázán na konkrétní e-shop a izolace je ověřována automatizovanými testy,
- hesla zákazníků ukládaná jen jako hash,
- šifrování přístupových údajů k platebním branám a dopravcům v databázi,
- omezený přístup k produkčnímu prostředí, dvoufaktorové ověření pro administraci platformy,
- pravidelné zálohy a protokol změn (kdo, kdy, co),
- anonymizace zákazníka na žádost o výmaz, aby zůstala neporušená historie dokladů, kterou nájemce musí uchovávat podle zákona.

## 8. Podzpracovatelé

Správce uděluje zpracovateli **obecné povolení** zapojit další zpracovatele. Aktuální seznam:

| Podzpracovatel | Co zpracovává | Kde |
|---|---|---|
| poskytovatel serverové infrastruktury | veškerá data e-shopu | EU |
| Stripe Payments Europe, Ltd. | fakturační údaje nájemce (nikoli údaje jeho zákazníků) | EU / USA (SCC) |
| ComGate Payments, a.s. | údaje o platbě zákazníka, je-li brána aktivována nájemcem | ČR |
| Zásilkovna s.r.o. (Packeta) | jméno, kontakt a adresa příjemce, je-li dopravce aktivován nájemcem | ČR |
| poskytovatel e-mailové brány | e-mailové adresy a obsah transakčních zpráv | EU |

Zpracovatel **oznámí zamýšlenou změnu** podzpracovatele nejméně **30 dnů předem**. Správce může proti změně vznést námitku; trvá-li zpracovatel na změně, může správce z tohoto důvodu smlouvu vypovědět bez sankce.

Zpracovatel uloží každému podzpracovateli tytéž povinnosti, jaké má sám, a odpovídá za jeho plnění.

Podzpracovatelé vázaní na jednotlivé moduly (platební brána, dopravce) zpracovávají údaje jen tehdy, má-li nájemce příslušný modul aktivní a nakonfigurovaný.

## 9. Součinnost

Zpracovatel je správci nápomocen:

- při vyřizování **žádostí subjektů údajů** — platforma poskytuje nástroje pro přístup, opravu, export a anonymizaci přímo v administraci, takže běžnou žádost vyřídí správce sám a bez prodlení,
- při **ohlašování porušení zabezpečení**: zpracovatel oznámí porušení správci **bez zbytečného odkladu poté, co se o něm dozví**, spolu s popisem povahy porušení, dotčených kategorií a předpokládaných následků,
- při posouzení vlivu na ochranu údajů a při předchozí konzultaci s dozorovým úřadem, vyžádá-li si to správce.

> **K PRÁVNÍ REVIZI:** konkrétní lhůta pro oznámení porušení správci (často se sjednává 24 nebo 48 hodin) a rozsah zpoplatnění součinnosti nad rámec běžné.

## 10. Výmaz a vrácení dat

Po skončení poskytování služby zpracovatel podle volby správce data **vrátí nebo vymaže**, včetně existujících kopií, ledaže právo EU nebo členského státu ukládá jejich uložení.

Prakticky: data zůstávají ke stažení **30 dnů** po ukončení smlouvy. Neuplatní-li správce v této lhůtě volbu, zpracovatel data smaže. Zálohy se přepisují v rámci běžného cyklu.

## 11. Audit

Zpracovatel poskytne správci informace nezbytné k doložení plnění povinností podle čl. 28 GDPR a umožní audit provedený správcem nebo jím pověřeným auditorem.

> **K PRÁVNÍ REVIZI:** rozsah auditního práva, lhůta pro ohlášení auditu a kdo nese jeho náklady. Bez omezení může jeden nájemce vyžadovat audit produkčního prostředí sdíleného se všemi ostatními.

Audit nesmí ohrozit bezpečnost nebo důvěrnost dat ostatních nájemců.

## 12. Odpovědnost

Odpovědnost stran se řídí čl. 82 GDPR a Všeobecnými obchodními podmínkami.
