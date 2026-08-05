<?php

namespace Modules\Pages\Support;

/**
 * Starting content for the legal pages every Czech e-shop needs.
 *
 * These are the tenant's own documents towards their customers, not the
 * platform's — see /pravni/* for ours. The platform is not a party to the
 * sale, so it cannot write these for the tenant; what it can do is stop them
 * from starting at a blank page, which is how a shop ends up published with
 * no terms at all.
 *
 * Every template carries visible [DOPLŇTE …] markers and opens with the
 * warning that it is a sample rather than legal advice. The warning is
 * itself one of the markers, so it cannot be left in by someone who did read
 * the rest.
 *
 * Markup is restricted to what App\Core\Html\HtmlSanitizer allows — no
 * headings above h2, no divs, no classes. Anything else would be stripped on
 * the tenant's first save and the template would silently lose structure.
 */
class PageTemplates
{
    /**
     * @return array<string, array{title: string, body: string}>
     */
    public static function all(): array
    {
        return [
            'obchodni-podminky' => [
                'title' => 'Obchodní podmínky',
                'body' => self::terms(),
            ],
            'ochrana-osobnich-udaju' => [
                'title' => 'Ochrana osobních údajů',
                'body' => self::privacy(),
            ],
            'kontakt' => [
                'title' => 'Kontakt',
                'body' => self::contact(),
            ],
        ];
    }

    private static function warning(): string
    {
        return <<<'HTML'
        <p><strong>[DOPLŇTE — a pak tento odstavec smažte]</strong> Toto je vzorový text, který za vás
        připravila platforma. <strong>Není právní radou.</strong> Projděte ho, doplňte všechny části
        označené [DOPLŇTE …] a upravte podle toho, jak váš e-shop skutečně funguje. Za obsah těchto
        podmínek odpovídáte vy jako provozovatel e-shopu.</p>
        HTML;
    }

    private static function terms(): string
    {
        return self::warning().<<<'HTML'

        <h2>1. Základní údaje</h2>
        <p>Tyto obchodní podmínky upravují prodej zboží prostřednictvím tohoto internetového obchodu,
        který provozuje [DOPLŇTE jméno nebo název firmy], IČO [DOPLŇTE], se sídlem [DOPLŇTE adresu],
        zapsaný [DOPLŇTE v živnostenském rejstříku / v obchodním rejstříku vedeném …].</p>
        <p>Kontaktní e-mail: [DOPLŇTE] · Telefon: [DOPLŇTE]</p>
        <p>[DOPLŇTE — jsem / nejsem plátcem DPH.]</p>

        <h2>2. Jak vzniká kupní smlouva</h2>
        <p>Nabídka zboží v e-shopu není závazným návrhem na uzavření smlouvy. Kupní smlouva vzniká
        okamžikem, kdy vám potvrdíme přijetí objednávky na e-mail, který jste uvedli.</p>
        <p>Před odesláním objednávky máte možnost zkontrolovat a měnit vložené údaje.</p>

        <h2>3. Cena a platba</h2>
        <p>Ceny jsou uvedeny včetně DPH a všech souvisejících poplatků, s výjimkou nákladů na dopravu,
        které se zobrazí v objednávce před jejím odesláním.</p>
        <p>Zboží lze zaplatit těmito způsoby: [DOPLŇTE — např. dobírka, převodem na účet, platební kartou].</p>

        <h2>4. Dodání zboží</h2>
        <p>Zboží dodáváme způsoby, které si zvolíte v objednávce. Obvyklá dodací lhůta je
        [DOPLŇTE počet] pracovních dnů od [DOPLŇTE — přijetí objednávky / připsání platby].</p>
        <p>Nebezpečí škody na zboží na vás přechází okamžikem převzetí.</p>

        <h2>5. Odstoupení od smlouvy do 14 dnů</h2>
        <p>Jste-li spotřebitel, máte právo odstoupit od smlouvy <strong>do 14 dnů</strong> ode dne převzetí
        zboží, a to bez udání důvodu. Lhůta je zachována, pokud nám odstoupení v jejím průběhu odešlete.</p>
        <p>Odstoupit můžete [DOPLŇTE — e-mailem na …, dopisem na …, formulářem na …].</p>
        <p>Zboží nám zašlete zpět nejpozději do 14 dnů od odstoupení. <strong>Náklady na vrácení zboží
        nesete vy.</strong></p>
        <p>Peníze vám vrátíme do 14 dnů od odstoupení, stejným způsobem, jakým jsme je přijali.
        Nemusíme je vrátit dřív, než nám zboží vrátíte nebo prokážete, že jste je odeslali.</p>
        <p>Právo na odstoupení <strong>nemáte</strong> zejména u zboží upraveného na přání, u zboží
        podléhajícího rychlé zkáze a u zvukových či obrazových nahrávek nebo softwaru v porušeném obalu.
        [DOPLŇTE — nechte jen výjimky, které se vás týkají, ostatní smažte.]</p>

        <h2>6. Práva z vadného plnění a reklamace</h2>
        <p>Práva a povinnosti stran ohledně vadného plnění se řídí občanským zákoníkem. Jste-li
        spotřebitel, můžete vadu vytknout <strong>do dvou let</strong> od převzetí zboží.</p>
        <p>Reklamaci uplatněte [DOPLŇTE — na adrese …, e-mailem na …]. Reklamaci vyřídíme
        <strong>do 30 dnů</strong> a o vyřízení vás vyrozumíme.</p>

        <h2>7. Mimosoudní řešení sporů</h2>
        <p>Jste-li spotřebitel, máte právo na mimosoudní řešení spotřebitelského sporu. Příslušným
        orgánem je <strong>Česká obchodní inspekce</strong>, Štěpánská 44, 110 00 Praha 1,
        <a href="https://adr.coi.cz" target="_blank" rel="noopener">adr.coi.cz</a>.</p>

        <h2>8. Ochrana osobních údajů</h2>
        <p>Jak nakládáme s vašimi údaji, popisují
        <a href="/ochrana-osobnich-udaju">zásady ochrany osobních údajů</a>.</p>

        <h2>9. Účinnost</h2>
        <p>Tyto obchodní podmínky jsou účinné od [DOPLŇTE datum].</p>
        HTML;
    }

    private static function privacy(): string
    {
        return self::warning().<<<'HTML'

        <h2>1. Kdo je správce</h2>
        <p>Správcem vašich osobních údajů je [DOPLŇTE jméno nebo název firmy], IČO [DOPLŇTE],
        se sídlem [DOPLŇTE adresu].</p>
        <p>Kontakt ve věcech ochrany údajů: [DOPLŇTE e-mail]</p>

        <h2>2. Jaké údaje zpracováváme</h2>
        <ul>
        <li>identifikační a kontaktní — jméno, e-mail, telefon, fakturační a dodací adresa,</li>
        <li>údaje o objednávkách — co jste koupili, kdy a za kolik,</li>
        <li>přístupové údaje, pokud si u nás založíte účet,</li>
        <li>[DOPLŇTE další, pokud nějaké sbíráte — např. přihlášení k newsletteru].</li>
        </ul>

        <h2>3. Proč je zpracováváme</h2>
        <ul>
        <li><strong>Vyřízení objednávky</strong> — plnění smlouvy. Bez těchto údajů objednávku nevyřídíme.</li>
        <li><strong>Účetnictví a daně</strong> — plnění právní povinnosti. Doklady uchováváme
        <strong>10 let</strong>.</li>
        <li><strong>Vyřizování reklamací</strong> — plnění smlouvy a právní povinnosti.</li>
        <li>[DOPLŇTE — pokud rozesíláte obchodní sdělení, doplňte účel a právní titul.]</li>
        </ul>

        <h2>4. Komu údaje předáváme</h2>
        <ul>
        <li>dopravci, kterého si zvolíte — jméno, adresa, telefon a e-mail pro doručení,</li>
        <li>poskytovateli platební brány, platíte-li kartou nebo online,</li>
        <li>provozovateli e-shopové platformy jako zpracovateli — [DOPLŇTE název platformy],</li>
        <li>účetní a orgánům veřejné moci, ukládá-li to zákon.</li>
        </ul>

        <h2>5. Jak dlouho údaje uchováváme</h2>
        <p>Údaje z objednávek uchováváme po dobu nezbytnou k vyřízení a dále po dobu záruční lhůty
        a promlčecí doby. Daňové doklady uchováváme 10 let. [DOPLŇTE další lhůty, pokud je máte.]</p>

        <h2>6. Vaše práva</h2>
        <p>Máte právo na přístup ke svým údajům, na jejich opravu a výmaz, na omezení zpracování,
        na přenositelnost a právo vznést námitku. Souhlas, pokud jste jej udělili, můžete kdykoli odvolat.</p>
        <p>Uplatnit je můžete na [DOPLŇTE e-mail]. Odpovíme do jednoho měsíce.</p>
        <p>Máte také právo podat stížnost u <strong>Úřadu pro ochranu osobních údajů</strong>,
        Pplk. Sochora 27, 170 00 Praha 7, <a href="https://uoou.gov.cz" target="_blank" rel="noopener">uoou.gov.cz</a>.</p>

        <h2>7. Cookies</h2>
        <p>Náš e-shop používá technicky nezbytné cookies, bez kterých by nefungoval košík ani přihlášení.
        [DOPLŇTE — používáte-li analytické nebo reklamní kódy, musíte si na ně vyžádat souhlas předem
        a popsat je zde.]</p>

        <h2>8. Hodnocení nákupu</h2>
        <p>[DOPLŇTE — smažte celý tento oddíl, pokud nepoužíváte Heureku Ověřeno zákazníky.]
        Po dokončení objednávky předáváme vaši e-mailovou adresu a seznam zakoupeného zboží službě
        <strong>Heureka Ověřeno zákazníky</strong> (Heureka Group a.s.), aby vám mohla poslat dotazník
        spokojenosti. Právním základem je náš oprávněný zájem zjišťovat spokojenost zákazníků.
        Proti tomuto zpracování můžete kdykoli vznést námitku na [DOPLŇTE e-mail] nebo se odhlásit
        přímo v dotazníku.</p>

        <h2>9. Účinnost</h2>
        <p>Tyto zásady jsou účinné od [DOPLŇTE datum].</p>
        HTML;
    }

    private static function contact(): string
    {
        return self::warning().<<<'HTML'

        <h2>Kdo jsme</h2>
        <p>[DOPLŇTE jméno nebo název firmy]<br>
        IČO: [DOPLŇTE]<br>
        DIČ: [DOPLŇTE, jste-li plátcem DPH]</p>
        <p>Sídlo: [DOPLŇTE ulice a číslo, PSČ, město]</p>
        <p>[DOPLŇTE — zapsán v živnostenském rejstříku / v obchodním rejstříku vedeném …]</p>

        <h2>Jak nás zastihnete</h2>
        <p>E-mail: [DOPLŇTE]<br>
        Telefon: [DOPLŇTE]</p>
        <p>Provozní doba: [DOPLŇTE]</p>

        <h2>Adresa pro vrácení zboží a reklamace</h2>
        <p>[DOPLŇTE — liší-li se od sídla. Pokud ne, uveďte, že je shodná se sídlem.]</p>
        HTML;
    }
}
