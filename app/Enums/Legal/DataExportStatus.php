<?php

namespace App\Enums\Legal;

enum DataExportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Demande en attente',
            self::Processing => 'Génération en cours',
            self::Ready => 'Archive prête au téléchargement',
            self::Failed => 'Échec de génération',
            self::Expired => 'Archive expirée',
        };
    }
}
