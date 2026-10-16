<?php

namespace App\Enums\Support;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingForUser = 'waiting_for_user';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ouvert',
            self::InProgress => 'En cours de traitement',
            self::WaitingForUser => 'En attente de réponse',
            self::Resolved => 'Résolu',
            self::Closed => 'Fermé',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }

    public function canBeRepliedToByClient(): bool
    {
        return $this !== self::Closed;
    }
}
