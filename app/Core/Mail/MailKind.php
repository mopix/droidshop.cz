<?php

namespace App\Core\Mail;

/**
 * What kind of message this is, for the purposes of the `emails_month` cap.
 *
 * Product decision (2026-07-20): transactional mail must never be blocked by
 * an exhausted quota. A shop that has run out of allowance still has to
 * deliver order confirmations and password resets — otherwise the nájemce's
 * unpaid bill is paid for by his customer, who cannot get into their account
 * and never learns they owe money. Transactional messages still *count*
 * toward usage (the superadmin sees the real volume and the nájemce still
 * gets pushed to upgrade); they simply never get *refused*.
 *
 * Bulk mail (newsletters, marketing campaigns) is where the cap actually
 * bites — it is the class of mail the limit exists to constrain.
 *
 * There is no default: every call site must say which one it is sending.
 */
enum MailKind: string
{
    case Transactional = 'transactional';

    case Bulk = 'bulk';
}
