<?php

namespace App\Enums\Page;

enum PageRole: string
{
    case Owner = 'owner';
    case Editor = 'editor';
    case Moderator = 'moderator';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Propriétaire',
            self::Editor => 'Éditeur',
            self::Moderator => 'Modérateur',
        };
    }

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function canManageMembers(): bool
    {
        return $this === self::Owner;
    }

    public function canEditDetails(): bool
    {
        return $this === self::Owner;
    }

    public function canManageFinances(): bool
    {
        return $this === self::Owner;
    }

    public function canPublishContent(): bool
    {
        return in_array($this, [self::Owner, self::Editor], true);
    }

    public function canAccessInbox(): bool
    {
        return in_array($this, [self::Owner, self::Editor, self::Moderator], true);
    }
}
