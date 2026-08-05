@extends('legal.layout')

@section('title', 'Zásady používání cookies')
@section('description', 'Které cookies používá platforma DroidShop.cz a proč u nich nevyžadujeme souhlas.')
@section('version', '')

@section('body')
    <p class="lead">
        Tento dokument popisuje cookies na <strong>webu platformy DroidShop.cz</strong> — tedy na stránkách,
        kde se registrujete, spravujete předplatné a čtete tyto dokumenty.
    </p>
    <div class="callout">
        <p>
            <strong>Cookies na e-shopech našich nájemců tento dokument neupravuje.</strong> Za ně odpovídá
            provozovatel příslušného e-shopu; my mu k tomu poskytujeme nástroje.
        </p>
    </div>

    <h2>Co jsou cookies</h2>
    <p>
        Malé soubory, které web ukládá do prohlížeče, aby si mezi požadavky pamatoval stav — třeba že jste
        přihlášeni.
    </p>

    <h2>Které cookies používáme</h2>
    <p>
        Používáme <strong>výhradně technicky nezbytné cookies</strong>. Analytické, marketingové ani profilovací
        cookies na webu platformy nemáme.
    </p>
    <div class="table-scroll">
        <table>
            <thead>
            <tr><th>Cookie</th><th>K čemu</th><th>Doba</th></tr>
            </thead>
            <tbody>
            <tr><td><code>droidshop_session</code></td><td>udržuje přihlášení a stav formulářů</td><td>do zavření prohlížeče, nejdéle 2 hodiny neaktivity</td></tr>
            <tr><td><code>XSRF-TOKEN</code></td><td>ochrana proti podvržení požadavku (CSRF)</td><td>stejná jako relace</td></tr>
            <tr><td><code>cart</code></td><td>drží obsah košíku na e-shopu nájemce, aby nezmizel při zavření prohlížeče</td><td>30 dnů</td></tr>
            </tbody>
        </table>
    </div>

    <h2>Proč se na ně neptáme na souhlas</h2>
    <p>
        Podle <strong>§ 89 odst. 3 zákona č. 127/2005 Sb.</strong> o elektronických komunikacích se souhlas
        nevyžaduje u cookies, které jsou <strong>nezbytné pro poskytnutí služby, kterou jste sami vyžádali</strong>.
        Všechny cookies v tabulce výše tuto podmínku splňují: bez nich se nelze přihlásit ani dokončit objednávku.
    </p>
    <p>
        Kdybychom nasadili analytiku nebo měřicí kódy, ptali bychom se předem a bez vašeho souhlasu bychom je
        nespustili.
    </p>

    <h2>Jak je odmítnout</h2>
    <p>
        Cookies můžete v prohlížeči zakázat nebo smazat. Protože jsou technicky nezbytné, jejich zákaz znamená,
        že se <strong>nebudete moci přihlásit</strong> a e-shop nedokončí objednávku.
    </p>

    <h2>Pro nájemce: cookies na vašem e-shopu</h2>
    <p>
        Váš e-shop používá cookies uvedené výše. Jsou technicky nezbytné, takže samy o sobě souhlas nevyžadují.
    </p>
    <p>
        <strong>Jakmile na e-shop přidáte měřicí nebo reklamní kód</strong> (Google Analytics, Sklik, Meta Pixel
        a podobně), situace se mění — takové cookies souhlas vyžadují a musíte si ho vyžádat <strong>předem</strong>,
        tedy dřív, než se kód spustí. Za splnění této povinnosti odpovídáte jako provozovatel e-shopu vy.
    </p>
    <p>
        Nástroje pro správu souhlasu připravujeme; do té doby měřicí kódy na platformě nasadit nelze.
    </p>

    <h2>Změny</h2>
    <p>Tyto zásady aktualizujeme, kdykoli se změní seznam používaných cookies.</p>
@endsection
