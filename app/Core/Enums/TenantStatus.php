<?php

namespace App\Core\Enums;

/**
 * Lifecycle of a tenant (spec §6.0).
 *
 * trial ──payment──► active ──miss──► past_due ──► suspended ──► pending_deletion ──► deleted
 */
enum TenantStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case PendingDeletion = 'pending_deletion';
    case Deleted = 'deleted';

    /**
     * Whether the public storefront answers requests.
     *
     * past_due still serves: we chase the invoice, we do not punish the
     * tenant's customers while the grace period runs.
     */
    public function allowsStorefront(): bool
    {
        return match ($this) {
            self::Trial, self::Active, self::PastDue => true,
            self::Suspended, self::PendingDeletion, self::Deleted => false,
        };
    }

    /**
     * Whether the tenant admin accepts writes.
     *
     * Suspended tenants keep read access so they can export their data
     * before deletion; they just cannot change anything.
     */
    public function allowsAdminWrite(): bool
    {
        return match ($this) {
            self::Trial, self::Active, self::PastDue => true,
            self::Suspended, self::PendingDeletion, self::Deleted => false,
        };
    }

    public function allowsAdminRead(): bool
    {
        return $this !== self::Deleted;
    }

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Zkušební období',
            self::Active => 'Aktivní',
            self::PastDue => 'Po splatnosti',
            self::Suspended => 'Pozastaveno',
            self::PendingDeletion => 'Čeká na smazání',
            self::Deleted => 'Smazáno',
        };
    }
}
