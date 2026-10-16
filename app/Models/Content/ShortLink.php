<?php

namespace App\Models\Content;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShortLink extends Model
{
    use HasFactory;

    protected $table = 'short_links';

    protected $fillable = [
        'code',
        'target_type',
        'target_id',
        'creator_id',
        'clicks_count',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clicks_count' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Créateur du lien court.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Vérifie si le lien est expiré.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Incrémente atomiquement le compteur de clics.
     */
    public function incrementClicks(): void
    {
        $this->increment('clicks_count');
    }
}
