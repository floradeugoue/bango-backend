<?php

namespace App\Enums\Boost;

enum CampaignObjective: string
{
    case Reach = 'reach';
    case ProfileVisits = 'profile_visits';
    case Messages = 'messages';
    case Engagement = 'engagement';

    public function label(): string
    {
        return match ($this) {
            self::Reach => 'Portée maximale',
            self::ProfileVisits => 'Visites du profil / Page Pro',
            self::Messages => 'Discussions et messages directs',
            self::Engagement => 'Interactions (likes, partages, commentaires)',
        };
    }
}
