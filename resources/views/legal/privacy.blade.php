@extends('legal.layout')

@section('title', 'Zásady zpracování osobních údajů')
@section('description', 'Jak DroidShop.cz nakládá s osobními údaji provozovatelů e-shopů, kteří si platformu pronajímají.')
@section('version', '')

@section('body')
    <p class="lead">
        Tento dokument popisuje, jak nakládáme s osobními údaji <strong>nájemců</strong> — tedy lidí, kteří si
        u nás pronajímají e-shop. V tomto vztahu jsme <strong>správce</strong>.
    </p>
    <div class="callout">
        <p>
            <strong>Údaje koncových zákazníků e-shopů našich nájemců tento dokument neupravuje.</strong>
            U nich jsme zpracovatel a správcem je nájemce; podmínky jsou ve
            <a href="{{ route('legal.show', 'zpracovani-udaju') }}">zpracovatelské smlouvě</a>.
        </p>
    </div>

    <h2>1. Správce</h2>
    <p>
        <strong>{{ $company['name'] }}</strong>, IČO <strong>{{ $company['ico'] }}</strong>@if ($company['address'] !== ''), se sídlem {{ $company['address'] }}@endif.
    </p>
    <p>Kontakt: <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a></p>
    <p>
        Pověřence pro ochranu osobních údajů jsme nejmenovali — nesplňujeme podmínky čl. 37 GDPR, které jeho
        jmenování vyžadují.
    </p>

    <h2>2. Jaké údaje zpracováváme</h2>
    <div class="table-scroll">
        <table>
            <thead>
            <tr><th>Kategorie</th><th>Konkrétně</th><th>Odkud</th></tr>
            </thead>
            <tbody>
            <tr><td>Identifikační</td><td>jméno, název firmy, IČO, DIČ</td><td>od vás při registraci a v nastavení fakturace</td></tr>
            <tr><td>Kontaktní</td><td>e-mail, telefon, fakturační adresa</td><td>od vás</td></tr>
            <tr><td>Přístupové</td><td>hash hesla, údaje o přihlášení</td><td>vzniká při registraci</td></tr>
            <tr><td>Fakturační</td><td>vystavené doklady, zaplacené částky, období</td><td>vzniká provozem</td></tr>
            <tr><td>Provozní</td><td>IP adresa, čas požadavku, záznamy v protokolu změn</td><td>vzniká provozem</td></tr>
            </tbody>
        </table>
    </div>
    <p>
        Údaje o platební kartě <strong>nezpracováváme a nemáme k nim přístup</strong> — sbírá je přímo Stripe
        na svém formuláři.
    </p>

    <h2>3. Proč je zpracováváme a na jakém základě</h2>
    <div class="table-scroll">
        <table>
            <thead>
            <tr><th>Účel</th><th>Právní titul</th><th>Doba uchování</th></tr>
            </thead>
            <tbody>
            <tr><td>Poskytování služby, správa účtu</td><td>plnění smlouvy (čl. 6/1/b)</td><td>po dobu smlouvy + 30 dnů</td></tr>
            <tr><td>Fakturace a účetnictví</td><td>plnění právní povinnosti (čl. 6/1/c)</td><td><strong>10 let</strong> od konce zdaňovacího období</td></tr>
            <tr><td>Zabezpečení, protokol změn, řešení incidentů</td><td>oprávněný zájem (čl. 6/1/f)</td><td>12 měsíců</td></tr>
            <tr><td>Vymáhání pohledávek</td><td>oprávněný zájem (čl. 6/1/f)</td><td>po dobu promlčecí lhůty</td></tr>
            <tr><td>Obchodní sdělení stávajícím nájemcům</td><td>oprávněný zájem (čl. 6/1/f)</td><td>do vznesení námitky</td></tr>
            </tbody>
        </table>
    </div>
    <p>
        Zpracování pro účely plnění smlouvy a právní povinnosti je nezbytné — bez něj službu poskytnout nelze.
    </p>

    <h2>4. Komu údaje předáváme</h2>
    <p>Předáváme jen v rozsahu, který daný účel vyžaduje:</p>
    <div class="table-scroll">
        <table>
            <thead>
            <tr><th>Příjemce</th><th>Co</th><th>Proč</th></tr>
            </thead>
            <tbody>
            <tr><td>Stripe Payments Europe, Ltd.</td><td>e-mail, jméno, fakturační údaje, částky</td><td>zpracování plateb za předplatné</td></tr>
            <tr><td>poskytovatel serverové infrastruktury</td><td>vše uložené v databázi a na disku</td><td>provoz služby</td></tr>
            <tr><td>poskytovatel e-mailové brány</td><td>e-mailová adresa, obsah zpráv</td><td>doručení transakčních e-mailů</td></tr>
            <tr><td>účetní</td><td>doklady a fakturační údaje</td><td>vedení účetnictví</td></tr>
            <tr><td>orgány veřejné moci</td><td>dle jejich zákonného požadavku</td><td>plnění právní povinnosti</td></tr>
            </tbody>
        </table>
    </div>
    <p>
        <strong>Do zemí mimo EU</strong> údaje nepředáváme, ledaže to vyplývá z využití některého ze zpracovatelů
        výše. V takovém případě je předání kryto standardními smluvními doložkami Evropské komise.
    </p>
    <p><strong>Údaje neprodáváme</strong> a nepředáváme je pro marketingové účely třetích stran.</p>

    <h2>5. Vaše práva</h2>
    <p>Máte právo:</p>
    <ul>
        <li><strong>na přístup</strong> — vědět, jaké údaje o vás zpracováváme, a získat jejich kopii,</li>
        <li><strong>na opravu</strong> — většinu si můžete opravit sami v administraci,</li>
        <li><strong>na výmaz</strong> — v případech, kdy pro zpracování nemáme jiný důvod; nevztahuje se na údaje, které musíme uchovávat podle zákona (typicky vystavené doklady),</li>
        <li><strong>na omezení zpracování</strong>,</li>
        <li><strong>na přenositelnost</strong> — dostat údaje ve strojově čitelném formátu,</li>
        <li><strong>vznést námitku</strong> proti zpracování na základě oprávněného zájmu,</li>
        <li><strong>odvolat souhlas</strong>, je-li zpracování na souhlasu založeno; odvoláním není dotčena zákonnost zpracování před odvoláním.</li>
    </ul>
    <p>
        Uplatnit je můžete na <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a>. Odpovíme
        <strong>do jednoho měsíce</strong>; ve složitých případech lhůtu prodloužíme a dáme vám vědět.
    </p>
    <p>
        Máte také právo <strong>podat stížnost u Úřadu pro ochranu osobních údajů</strong>
        (Pplk. Sochora 27, 170 00 Praha 7, <a href="https://uoou.gov.cz" rel="noopener">uoou.gov.cz</a>).
    </p>

    <h2>6. Zabezpečení</h2>
    <ul>
        <li>přenos výhradně přes HTTPS,</li>
        <li>hesla ukládáme jen jako hash, nikdy v čitelné podobě,</li>
        <li>přístupové údaje k platebním branám a dopravcům jsou v databázi šifrované,</li>
        <li>data jednotlivých e-shopů jsou od sebe oddělená na úrovni aplikace a každý dotaz je vázán na konkrétní e-shop,</li>
        <li>přístup do administrace platformy je chráněn dvoufaktorovým ověřením,</li>
        <li>pravidelné zálohy.</li>
    </ul>
    <p>
        Žádné opatření nedává absolutní jistotu. Zjistíme-li porušení zabezpečení s rizikem pro vaše práva,
        ohlásíme je Úřadu do 72 hodin a při vysokém riziku vyrozumíme i vás.
    </p>

    <h2>7. Automatizované rozhodování</h2>
    <p>Neprovádíme automatizované rozhodování ani profilování, které by pro vás mělo právní účinky.</p>

    <h2>8. Změny</h2>
    <p>Tyto zásady můžeme aktualizovat. Věcnou změnu oznámíme e-mailem před nabytím účinnosti.</p>
@endsection
