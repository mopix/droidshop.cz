# Error 01 — token pro obnovu hesla přežil GDPR výmaz zákazníka

**Datum:** 2026-07-21
**Závažnost:** critical
**Stav:** resolved
**Související spec/plán:** `docs/specs/2026-07-17-eshop-platforma-specifikace.md` §15.1 (GDPR výmaz); modul `customers`, vlna 2 (etapa checkout) — pre-merge review

## Symptom

Review před mergem `feat/checkout` označil, že `CustomerEraser::erase()` maže adresy zákazníka a přepisuje `customers.email` na náhodný placeholder, ale nemaže odpovídající řádky v `customer_tokens`. Napsaný test reprodukující útok (`CustomerAdminTest::test_a_token_issued_before_erasure_cannot_hijack_the_account_that_registers_the_freed_address`) proti původnímu kódu skutečně spadl:

```
FAILED  Tests\Feature\Modules\Customers\CustomerAdminTest > a token issue…
Session is missing expected key [errors].
Failed asserting that false is true.

at tests/Feature/Modules/Customers/CustomerAdminTest.php:554
  554▕ $response->assertSessionHasErrors('email');
```

Očekávali jsme, že starý token po výmazu zákazníka přestane platit (`assertSessionHasErrors('email')`), ale požadavek na `POST /obnova-hesla` prošel bez chyby — token byl přijat a heslo bylo přepsáno u účtu, který adresu drží nyní, ne u původního zákazníka.

## Příčina

Řetězec, který k tomu vedl:

1. Zákazník **A** požádá o reset hesla na `a@x.cz`. Vznikne řádek v `customer_tokens` klíčovaný `(tenant_id, 'a@x.cz', 'password_reset')`, platný hodinu.
2. Nájemce (admin) zákazníka **A** smaže (GDPR výmaz). `CustomerEraser::erase()` přepíše `customers.email` na `smazano-{id}-{random}@anonymized.invalid`. Tím se z unikátního indexu `(tenant_id, email)` uvolní `a@x.cz` — a **token v `customer_tokens` zůstal beze změny**, protože `erase()` na tuto tabulku vůbec nesahal.
3. Zákazník **B**, kterému `a@x.cz` nic neříká, se pod touto adresou u stejného e-shopu zaregistruje. Registrace (`CustomerRegistrar::register()`) zapisuje token jen pro účel `email_verification` — starý `password_reset` řádek po A tím není dotčen.
4. **A** otevře svůj starý odkaz z e-mailu. `CustomerTokens::consume()` porovnává jen `(tenant_id, email, purpose)` a hash tokenu — nic v tom neříká „a stále patří tomu, kdo si o něj požádal". Token sedí, `consume()` vrátí `true`.
5. `PasswordResetController::update()` po úspěšném `consume()` dohledá `Customer::where('email', $email)->first()` — a najde **B**, protože B teď tu adresu drží. Heslo B se přepíše na to, co zadal A (nebo útočník s A's odkazem), a request se rovnou přihlásí jako B.

Jádro chyby: `erase()` traktoval GDPR výmaz jako operaci nad jedinou tabulkou (`customers` + `customer_addresses`), ale bezpečnostně relevantní stav zákazníka byl rozprostřený i do `customer_tokens` — a `(tenant_id, email)` unikátní index nad `customers.email`, který `erase()` cíleně uvolňuje (aby se adresa dala znovu použít), je přesně to, co dělá starý token nebezpečným: token je klíčovaný stejnou dvojicí `(tenant, email)`, ne cizím klíčem na `customers.id`.

## Proč to prošlo recenzí/testy dřív

- Existující testy `CustomerAdminTest` ověřovaly, že výmaz anonymizuje `customers` a maže `customer_addresses` (`test_erasure_anonymises_the_customer_instead_of_deleting_it`), ale žádný test nekontroloval stav `customer_tokens` po výmazu.
- Existující testy `CustomerPasswordResetTest` pokrývaly platnost/expiraci/cizí tenant/opakované použití tokenu, ale ne scénář „adresa byla mezitím uvolněna výmazem a znovu obsazena".
- Testy na erase() i na password reset žily každý ve svém souboru a nikdo je nespojil do jednoho scénáře napříč moduly (výmaz → registrace → reset) — proto řetězec zůstal neviditelný, i když každý dílčí krok byl sám o sobě otestovaný a fungoval podle očekávání.

## Řešení

`Modules/Customers/Services/CustomerEraser.php`:

- `erase()` si na začátku (před retry smyčkou, viz níže) uloží `$originalEmail = $customer->email` — nutně dřív, než cokoli přepíše `email` v paměti, protože neúspěšný pokus (kolize placeholderu) `forceFill()`uje model ještě před tím, než `save()` vyhodí výjimku.
- Uvnitř transakce `erase()` nově volá `CustomerTokens::deleteAllForAddress($originalEmail)`, která smaže **všechny** řádky `customer_tokens` pro `(tenant_id, originalEmail)` bez ohledu na `purpose` — nejen `password_reset`, i `email_verification`, protože stejně nebezpečný by byl i přeživší verifikační token.

`Modules/Customers/Services/CustomerTokens.php`:

- Nová veřejná metoda `deleteAllForAddress(string $email): void`.
- Jako doprovodné vylepšení (finding 2 stejné revize) `consume()` nově maže i expirovaný řádek, ne jen ho odmítá — expirovaný token, který nikdo neuklidí, je stejný typ rizika o krok dřív.

Test, který chybu reprodukuje jako útok (ne jako izolovanou jednotkovou asserci): `tests/Feature/Modules/Customers/CustomerAdminTest.php::test_a_token_issued_before_erasure_cannot_hijack_the_account_that_registers_the_freed_address`.

## Prevence

- [x] Test reprodukující celý řetězec (reset token → výmaz → registrace cizí adresy → přehrání starého tokenu) v `CustomerAdminTest`, ověřeno red/green proti opravě.
- [x] `CustomerTokens::consume()` maže i expirovaný řádek (nezůstává ležet plaintext-adjacent adresa po vypršení).
- [x] Nový `customers:prune-tokens` (denně naplánovaný) pro tokeny, které nikdy nikdo neuplatnil ani je nenechal vypršet do smazání.
- [ ] Obecné pravidlo do `.claude/rules/`: GDPR výmaz (`*Eraser`/`*erase()`) musí explicitně vyjmenovat *všechny* tabulky, které drží e-mail/PII mazaného subjektu — ne jen tabulku, kterou service primárně vlastní. Zvážit checklist v `docs/as-is/` nebo review šablonu pro každý budoucí modul s vlastním „erase".
- [ ] Až vznikne modul `orders`/`carts`, zkontrolovat, zda GDPR výmaz nemusí sáhnout i tam (stejná třída chyby — cokoliv klíčované e-mailem, ne cizím klíčem na `customers.id`).

## Poznámky

Related fixes ve stejné revizi (viz `docs/superpowers/plans/` respektive commit historie `feat/checkout`):
- redakce adresy v `mail_messages` při výmazu (řádek zůstává kvůli `emails_month`, jen se přepíší `recipients`),
- `CustomerIdentity` kontrakt nyní odpovídá „no customer" i běhově, když tenant modul `customers` vypnul,
- eviction session po změně hesla (`AuthenticateCustomerSession` — Laravel `AuthenticateSession` na non-default guardu mlčky nefunguje).
