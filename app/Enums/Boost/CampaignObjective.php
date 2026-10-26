<?php

namespace App\Enums\Boost;

enum CampaignObjective: string
{
    case Reach = 'reach';
    case ProfileVisits = 'profile_visits';
    case Followers = 'followers';
    case Messages = 'messages';
    case Engagement = 'engagement';

    public function label(): string
    {
        return match ($this) {
            self::Reach => 'Portée maximale',
            self::ProfileVisits => 'Visites du profil / Page Pro',
            self::Followers => 'Nouveaux abonnés',
            self::Messages => 'Discussions et messages directs',
            self::Engagement => 'Interactions (likes, partages, commentaires)',
        };
    }
}
