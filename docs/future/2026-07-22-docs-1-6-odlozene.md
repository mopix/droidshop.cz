# Odložené z vlny 1.6 (docs) — kandidáti na 1.7+

**Datum:** 2026-07-22
**Kontext:** brainstorming vlny 1.6 (`docs/superpowers/specs/2026-07-22-faze-1-vlna-16-docs.md`)

Vlna 1.6 dělá plný storno-dobropis, ruční proformu a CSV VAT export. Následující bylo vědomě odloženo — schéma `documents` ani kontrakty tomu nebrání.

## 1. Částečný dobropis

Nájemce vybere konkrétní položky/množství k vrácení (reklamace, částečné vrácení zboží). Proti MVP (plný storno-dobropis na celou fakturu) přidává:

- UI výběru položek a množství v detailu objednávky.
- Validaci proti již dobropisovanému množství (kumulativní kontrola napříč více dobropisy k jedné faktuře).
- Uvolnění unique `(tenant_id, order_id, credit_note)` — víc dobropisů na objednávku.
- Přepočet `vat_summary` z podmnožiny položek.

Dopad: střední. Datový model unese (odkaz `corrects_document_id` už bude), hlavní práce je UI + validace kumulace.

## 2. Automatické vystavení proformy

Objednávka s platební metodou „převod" vystřelí doménový event a proforma se vystaví hned (zákazník dostane výzvu k platbě e-mailem). Analogie `auto_issue_on` u faktury.

- Nový listener na order placement / volbu platby.
- Settings přepínač (manual / on-order).
- Pozor: proforma před platbou na objednávku, kterou zákazník opustí, vyrobí doklad „na nic" — proto v MVP jen ruční.

## 3. Provazba proforma ↔ faktura

Po zaplacení proformy se ostrá faktura vystaví jako **daňový doklad k přijaté platbě** s odkazem na proformu a odečtem zálohy. V MVP jsou proforma a faktura nezávislé doklady na téže objednávce.

- Odkaz `based_on_document_id` ve snímku faktury.
- Render odečtu zálohy v PDF faktury.
- Právní režim „daňový doklad k přijaté platbě" ověřit s účetní.
