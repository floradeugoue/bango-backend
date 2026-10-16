<?php

namespace App\Enums\Boost;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Suspended = 'suspended';

    /**
     * Matrice des transitions d'états autorisées pour le cycle de vie d'une campagne.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Pending, self::Cancelled], true),
            self::Pending => in_array($target, [self::Active, self::Cancelled], true),
            self::Active => in_array($target, [self::Paused, self::Completed, self::Cancelled, self::Suspended], true),
            self::Paused => in_array($target, [self::Active, self::Cancelled, self::Suspended], true),
            self::Suspended => in_array($target, [self::Active, self::Cancelled], true),
            self::Completed, self::Cancelled => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Pending => 'En attente de validation/paiement',
            self::Active => 'En cours de diffusion',
            self::Paused => 'En pause',
            self::Completed => 'Terminée',
            self::Cancelled => 'Annulée / Interrompue',
            self::Suspended => 'Suspendue par la modération / inéligibilité',
        };
    }
}
