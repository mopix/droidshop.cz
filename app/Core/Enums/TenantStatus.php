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

    /**
     * Where a superadmin may move the tenant from here.
     *
     * Deliberately not a free choice of every case: going straight from deleted
     * back to trial, or marking a shop deleted by hand instead of letting the
     * deletion job do it, would leave the data and the status disagreeing.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Trial => [self::Active, self::Suspended, self::PendingDeletion],
            self::Active => [self::PastDue, self::Suspended, self::PendingDeletion],
            self::PastDue => [self::Active, self::Suspended, self::PendingDeletion],
            self::Suspended => [self::Active, self::PendingDeletion],
            // Reversible while the grace period runs; the deletion job is what
            // sets Deleted, never a person.
            self::PendingDeletion => [self::Suspended],
            self::Deleted => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Statuses whose consequences are severe enough that the reason has to be
     * recorded: they take the shop offline or start the clock on deletion.
     */
    public function requiresReason(): bool
    {
        return match ($this) {
            self::Suspended, self::PendingDeletion => true,
            default => false,
        };
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
