@extends('legal.layout')

@section('title', 'Smlouva o zpracování osobních údajů')
@section('description', 'Zpracovatelská smlouva podle čl. 28 GDPR mezi provozovatelem e-shopu a platformou DroidShop.cz.')
@section('version', '')

@section('body')
    <p class="lead">
        Tato smlouva je uzavřena podle <strong>čl. 28 nařízení (EU) 2016/679 (GDPR)</strong> mezi:
    </p>
    <ul>
        <li><strong>správcem</strong> — nájemcem, který provozuje e-shop na platformě DroidShop.cz,</li>
        <li><strong>zpracovatelem</strong> — {{ $company['name'] }}, IČO {{ $company['ico'] }}@if ($company['address'] !== ''), se sídlem {{ $company['address'] }}@endif.</li>
    </ul>
    <p>
        Smlouva je nedílnou součástí <a href="{{ route('legal.show', 'obchodni-podminky') }}">Všeobecných obchodních podmínek</a>
        a je uzavřena okamžikem jejich přijetí. Samostatný podpis se nevyžaduje.
    </p>

    <h2>1. Kdo je kdo</h2>
    <div class="callout">
        <p>
            <strong>Správcem je nájemce.</strong> On rozhoduje, jaké údaje svých zákazníků shromažďuje a proč.
            On odpovídá za informační povinnost vůči nim, za právní titul zpracování a za vyřizování jejich žádostí.
        </p>
    </div>
    <p>
        <strong>Zpracovatelem je poskytovatel platformy.</strong> Údaje zákazníků nájemce zpracovává výhradně
        proto, že mu je nájemce svěřil provozem e-shopu, a jen podle jeho pokynů.
    </p>
    <p>
        Zpracovatel s těmito údaji <strong>nenakládá pro vlastní účely</strong> — neanalyzuje je, nepředává je
        pro marketing a nepoužívá je k tvorbě vlastních produktů.
    </p>

    <h2>2. Předmět a doba</h2>
    <p>
        Zpracovatel zpracovává osobní údaje po dobu trvání smlouvy o poskytování služby a po ni v rozsahu článku 9.
    </p>

    <h2>3. Kategorie subjektů údajů</h2>
    <ul>
        <li>zákazníci e-shopu nájemce (registrovaní i nakupující bez registrace),</li>
        <li>osoby, které si u nájemce vyžádaly obnovu hesla nebo ověření e-mailu,</li>
        <li>příjemci zásilek, liší-li se od kupujícího.</li>
    </ul>

    <h2>4. Kategorie zpracovávaných údajů</h2>
    <div class="table-scroll">
        <table>
            <thead>
            <tr><th>Kategorie</th><th>Konkrétně</th></tr>
            </thead>
            <tbody>
            <tr><td>Identifikační</td><td>jméno a příjmení, u podnikatele název, IČO, DIČ</td></tr>
            <tr><td>Kontaktní</td><td>e-mail, telefon, fakturační a dodací adresa</td></tr>
            <tr><td>Transakční</td><td>objednávky, jejich obsah a hodnota, doklady, stav platby</td></tr>
            <tr><td>Doručovací</td><td>výdejní místo, číslo zásilky</td></tr>
            <tr><td>Přístupové</td><td>hash hesla zákaznického účtu, tokeny pro obnovu hesla</td></tr>
            <tr><td>Provozní</td><td>IP adresa, čas požadavku</td></tr>
            </tbody>
        </table>
    </div>
    <p>
        <strong>Zvláštní kategorie údajů podle čl. 9 GDPR</strong> (zdravotní stav, biometrie, náboženské vyznání
        a další) platforma nepodporuje a nájemce je do ní nesmí ukládat. Uloží-li je přesto, činí tak na vlastní
        odpovědnost a mimo rámec této smlouvy.
    </p>

    <h2>5. Pokyny správce</h2>
    <p>
        Zpracovatel zpracovává údaje jen na základě doložených pokynů správce. Za pokyn se považuje i
        <strong>samotné užívání platformy</strong> — nastavení e-shopu, aktivace modulů, konfigurace dopravců a plateb.
    </p>
    <p>
        Zpracovatel správce upozorní, pokud podle jeho názoru pokyn porušuje GDPR nebo jiný předpis
        o ochraně údajů.
    </p>
    <p>
        Zpracovatel může údaje zpracovat i tehdy, ukládá-li mu to právo EU nebo členského státu; v takovém
        případě správce předem informuje, ledaže to dané právo zakazuje.
    </p>

    <h2>6. Mlčenlivost</h2>
    <p>
        Zpracovatel zajistí, že osoby oprávněné zpracovávat údaje jsou vázány mlčenlivostí, a to i po skončení
        své činnosti.
    </p>

    <h2>7. Zabezpečení</h2>
    <p>Zpracovatel přijal zejména tato opatření:</p>
    <ul>
        <li>šifrovaný přenos (HTTPS) na všech veřejných i administračních rozhraních,</li>
        <li><strong>izolace dat jednotlivých e-shopů</strong> na úrovni aplikace — každý databázový dotaz je vázán na konkrétní e-shop a izolace je ověřována automatizovanými testy,</li>
        <li>hesla zákazníků ukládaná jen jako hash,</li>
        <li>šifrování přístupových údajů k platebním branám a dopravcům v databázi,</li>
        <li>omezený přístup k produkčnímu prostředí, dvoufaktorové ověření pro administraci platformy,</li>
        <li>pravidelné zálohy a protokol změn (kdo, kdy, co),</li>
        <li>anonymizace zákazníka na žádost o výmaz, aby zůstala neporušená historie dokladů, kterou nájemce musí uchovávat podle zákona.</li>
    </ul>

    <h2>8. Podzpracovatelé</h2>
    <p>
        Správce uděluje zpracovateli <strong>obecné povolení</strong> zapojit další zpracovatele. Aktuální seznam:
    </p>
    <div class="table-scroll">
        <table>
            <thead>
            <tr><th>Podzpracovatel</th><th>Co zpracovává</th><th>Kde</th></tr>
            </thead>
            <tbody>
            <tr><td>poskytovatel serverové infrastruktury</td><td>veškerá data e-shopu</td><td>EU</td></tr>
            <tr><td>Stripe Payments Europe, Ltd.</td><td>fakturační údaje nájemce (nikoli údaje jeho zákazníků)</td><td>EU / USA (SCC)</td></tr>
            <tr><td>ComGate Payments, a.s.</td><td>údaje o platbě zákazníka, je-li brána aktivována nájemcem</td><td>ČR</td></tr>
            <tr><td>Zásilkovna s.r.o. (Packeta)</td><td>jméno, kontakt a adresa příjemce, je-li dopravce aktivován nájemcem</td><td>ČR</td></tr>
            <tr><td>poskytovatel e-mailové brány</td><td>e-mailové adresy a obsah transakčních zpráv</td><td>EU</td></tr>
            </tbody>
        </table>
    </div>
    <p>
        Zpracovatel <strong>oznámí zamýšlenou změnu</strong> podzpracovatele nejméně <strong>30 dnů předem</strong>.
        Správce může proti změně vznést námitku; trvá-li zpracovatel na změně, může správce z tohoto důvodu
        smlouvu vypovědět bez sankce.
    </p>
    <p>
        Zpracovatel uloží každému podzpracovateli tytéž povinnosti, jaké má sám, a odpovídá za jeho plnění.
    </p>
    <p>
        Podzpracovatelé vázaní na jednotlivé moduly (platební brána, dopravce) zpracovávají údaje jen tehdy,
        má-li nájemce příslušný modul aktivní a nakonfigurovaný.
    </p>

    <h2>9. Součinnost</h2>
    <p>Zpracovatel je správci nápomocen:</p>
    <ul>
        <li>při vyřizování <strong>žádostí subjektů údajů</strong> — platforma poskytuje nástroje pro přístup, opravu, export a anonymizaci přímo v administraci, takže běžnou žádost vyřídí správce sám a bez prodlení,</li>
        <li>při <strong>ohlašování porušení zabezpečení</strong>: zpracovatel oznámí porušení správci bez zbytečného odkladu poté, co se o něm dozví, spolu s popisem povahy porušení, dotčených kategorií a předpokládaných následků,</li>
        <li>při posouzení vlivu na ochranu údajů a při předchozí konzultaci s dozorovým úřadem, vyžádá-li si to správce.</li>
    </ul>

    <h2>10. Výmaz a vrácení dat</h2>
    <p>
        Po skončení poskytování služby zpracovatel podle volby správce data <strong>vrátí nebo vymaže</strong>,
        včetně existujících kopií, ledaže právo EU nebo členského státu ukládá jejich uložení.
    </p>
    <p>
        Prakticky: data zůstávají ke stažení <strong>30 dnů</strong> po ukončení smlouvy. Neuplatní-li správce
        v této lhůtě volbu, zpracovatel data smaže. Zálohy se přepisují v rámci běžného cyklu.
    </p>

    <h2>11. Audit</h2>
    <p>
        Zpracovatel poskytne správci informace nezbytné k doložení plnění povinností podle čl. 28 GDPR
        a umožní audit provedený správcem nebo jím pověřeným auditorem.
    </p>
    <p>Audit nesmí ohrozit bezpečnost nebo důvěrnost dat ostatních nájemců.</p>

    <h2>12. Odpovědnost</h2>
    <p>
        Odpovědnost stran se řídí čl. 82 GDPR a
        <a href="{{ route('legal.show', 'obchodni-podminky') }}">Všeobecnými obchodními podmínkami</a>.
    </p>
@endsection
