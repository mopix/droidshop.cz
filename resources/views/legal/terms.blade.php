@extends('legal.layout')

@section('title', 'Všeobecné obchodní podmínky')
@section('description', 'Podmínky pronájmu e-shopové platformy DroidShop.cz pro provozovatele internetových obchodů.')
@section('version', ' · verze '.$termsVersion)

@section('body')
    <h2>1. Kdo službu poskytuje</h2>
    <p>
        Službu DroidShop.cz provozuje <strong>{{ $company['name'] }}</strong>, IČO <strong>{{ $company['ico'] }}</strong>@if ($company['address'] !== ''), se sídlem {{ $company['address'] }}@endif,
        zapsaný v živnostenském rejstříku (dále jen „poskytovatel").
    </p>
    <p>Kontaktní e-mail: <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a></p>
    <p>Poskytovatel {{ $company['vat_payer'] ? 'je' : 'není' }} plátcem DPH.</p>

    <h2>2. Co je předmětem těchto podmínek</h2>
    <p>
        Tyto podmínky upravují vztah mezi poskytovatelem a <strong>nájemcem</strong> — podnikatelem, který si
        pronajímá software pro provoz vlastního internetového obchodu.
    </p>
    <p>
        Předmětem není prodej softwaru ani jeho licence k trvalému užití. Nájemce získává
        <strong>právo užívat službu po dobu trvání předplatného</strong>, provozovanou na infrastruktuře poskytovatele.
    </p>
    <p>
        Služba je určena <strong>výhradně podnikatelům</strong>. Nájemce uzavřením smlouvy potvrzuje, že jedná v rámci
        své podnikatelské činnosti; ustanovení o ochraně spotřebitele se proto na vztah mezi poskytovatelem
        a nájemcem nepoužijí.
    </p>

    <h2>3. Nájemce je provozovatelem svého e-shopu</h2>
    <div class="callout">
        <p>
            Poskytovatel dodává software a infrastrukturu.
            <strong>Prodávajícím vůči koncovým zákazníkům je vždy nájemce</strong>, nikoli poskytovatel.
            Poskytovatel není stranou kupní smlouvy uzavírané mezi nájemcem a jeho zákazníkem, nedostává
            kupní cenu a nemá k prodávanému zboží žádný vztah.
        </p>
    </div>
    <p>Nájemce v plném rozsahu odpovídá zejména za:</p>
    <ul>
        <li>obsah e-shopu — texty, obrázky, popisy zboží a práva k nim,</li>
        <li>ceny, jejich správnost a soulad se zákonem, včetně evidence nejnižší ceny za 30 dnů podle § 12a zákona č. 634/1992 Sb.,</li>
        <li>vlastní obchodní podmínky vůči svým zákazníkům a informační povinnost vůči nim,</li>
        <li>vyřizování objednávek, dodání zboží, reklamace a odstoupení od smlouvy,</li>
        <li>daně, evidenci tržeb a účetnictví,</li>
        <li>zákonnost prodávaného zboží a služeb, včetně případných povolení a licencí,</li>
        <li>ochranu osobních údajů svých zákazníků v roli správce (viz <a href="{{ route('legal.show', 'zpracovani-udaju') }}">zpracovatelská smlouva</a>).</li>
    </ul>
    <p>
        Nájemce se zavazuje odškodnit poskytovatele, pokud by po něm třetí osoba požadovala plnění z důvodu,
        za který podle tohoto článku odpovídá nájemce.
    </p>

    <h2>4. Registrace a vznik smlouvy</h2>
    <p>
        Smlouva vzniká dokončením registrace a založením e-shopu. Nájemce při registraci potvrzuje souhlas
        s těmito podmínkami; poskytovatel zaznamenává datum souhlasu a verzi podmínek.
    </p>
    <p>
        Nájemce je povinen uvádět pravdivé údaje a udržovat je aktuální. Za zneužití přístupových údajů,
        které neohlásil, odpovídá nájemce.
    </p>
    <p>
        Registrace zahrnuje <strong>zkušební období v délce {{ config('billing.trial_days', 14) }} dnů</strong>
        bez nároku na platbu. Po jeho skončení pokračuje služba jen po zaplacení předplatného.
    </p>

    <h2>5. Cena, fakturace a splatnost</h2>
    <p>
        Ceny tarifů jsou uvedeny na webu poskytovatele. Předplatné se hradí předem, v <strong>měsíčním nebo
        ročním intervalu</strong> podle volby nájemce.
    </p>
    <p>
        Platba probíhá kartou přes platební bránu Stripe. Poskytovatel nemá k údajům o platební kartě přístup.
    </p>
    <p>K platbě vystaví poskytovatel daňový doklad, který je nájemci dostupný v administraci.</p>
    <p>
        Nájemce může kdykoli <strong>změnit tarif nebo interval</strong>. Změna se promítne do nejbližšího
        zúčtovacího období; při přechodu na vyšší tarif v průběhu období se doúčtuje poměrná část.
    </p>
    <p>
        Při <strong>prodlení s platbou</strong> přechází e-shop do stavu „po splatnosti". Veřejný e-shop
        v tomto stavu <strong>běží dál</strong> — zákazníci nájemce nemají nést následky nájemcova prodlení.
        Po uplynutí ochranné lhůty {{ config('billing.grace_days', 7) }} dnů poskytovatel e-shop pozastaví.
    </p>

    <h2>6. Trvání a ukončení</h2>
    <p>Smlouva se uzavírá na dobu neurčitou s předplaceným obdobím podle zvoleného intervalu.</p>
    <p>
        <strong>Nájemce</strong> může předplatné zrušit kdykoli ve své administraci. Služba běží do konce
        zaplaceného období; zaplacené předplatné se nevrací.
    </p>
    <p>
        <strong>Poskytovatel</strong> může smlouvu vypovědět s výpovědní dobou jednoho měsíce, nebo okamžitě,
        poruší-li nájemce podstatně tyto podmínky (zejména článek 8).
    </p>
    <p>
        Po ukončení smlouvy zůstávají data nájemce dostupná ke stažení <strong>30 dnů</strong>. Poté je
        poskytovatel nevratně smaže. Daňové doklady poskytovatel uchovává po zákonnou dobu bez ohledu
        na ukončení smlouvy.
    </p>

    <h2>7. Dostupnost služby</h2>
    <p>
        Poskytovatel usiluje o nepřetržitou dostupnost služby, negarantuje ji však. Plánovanou údržbu,
        která službu omezí, oznámí předem, je-li to možné.
    </p>
    <p>
        Poskytovatel neodpovídá za výpadky způsobené třetími stranami (poskytovatel konektivity, platební
        brána, dopravce, poskytovatel domény) ani zásahem vyšší moci.
    </p>

    <h2>8. Zakázané užití</h2>
    <p>Nájemce nesmí zejména:</p>
    <ul>
        <li>nabízet zboží nebo služby, jejichž prodej je zakázán nebo vyžaduje povolení, které nemá,</li>
        <li>porušovat práva třetích osob, včetně práv autorských a ochranných známek,</li>
        <li>rozesílat nevyžádaná obchodní sdělení,</li>
        <li>zatěžovat infrastrukturu způsobem, který ohrožuje provoz ostatních e-shopů,</li>
        <li>pokoušet se o neoprávněný přístup k datům jiných nájemců nebo k systémům poskytovatele,</li>
        <li>službu dále pronajímat či zpřístupňovat třetím osobám nad rámec vlastního e-shopu.</li>
    </ul>

    <h2>9. Pozastavení a zrušení e-shopu</h2>
    <p>
        Poskytovatel může e-shop <strong>pozastavit</strong> při prodlení s platbou (článek 5) nebo při
        porušení článku 8. O pozastavení nájemce informuje e-mailem.
    </p>
    <p>
        Pozastavený e-shop není veřejně dostupný, ale nájemce má <strong>nadále přístup k administraci
        pro čtení a export dat</strong>. Zaplacením dlužné částky se e-shop obnoví.
    </p>
    <p>
        Trvá-li pozastavení bez nápravy, přechází e-shop do stavu „čeká na smazání". I tehdy nájemce dostane
        zprávu a data lze ještě obnovit. Po uplynutí lhůty podle článku 6 poskytovatel data smaže.
    </p>

    <h2>10. Odpovědnost</h2>
    <p>Poskytovatel odpovídá za škodu způsobenou porušením svých povinností.</p>
    <p>
        Poskytovatel neodpovídá za ušlý zisk nájemce ani za škodu vzniklou z obsahu, který nájemce
        na e-shop umístil.
    </p>

    <h2>11. Zálohy a obnova dat</h2>
    <p>
        Poskytovatel provádí pravidelné zálohy. Záloha slouží k obnově provozu po havárii, není službou
        obnovy dat na žádost nájemce.
    </p>
    <p>
        Nájemce si může kdykoli exportovat produkty i doklady a odpovídá za vlastní zálohy dat, která
        považuje za nenahraditelná.
    </p>

    <h2>12. Změny podmínek</h2>
    <p>
        Poskytovatel může tyto podmínky měnit. Věcnou změnu oznámí nájemci e-mailem <strong>nejméně 30 dnů</strong>
        před účinností. Nesouhlasí-li nájemce, může do dne účinnosti smlouvu vypovědět; nevyužije-li této
        možnosti a pokračuje v užívání služby, platí, že změnu přijal.
    </p>
    <p>Změny vynucené právním předpisem nabývají účinnosti dnem, kdy to předpis vyžaduje.</p>

    <h2>13. Rozhodné právo a řešení sporů</h2>
    <p>
        Vztah se řídí právem České republiky. Spory rozhodují české soudy podle sídla poskytovatele.
        Strany se pokusí spor nejprve vyřešit smírně.
    </p>
@endsection
