<?php

namespace App\Models\Reel;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sound extends Model
{
    use HasFactory;

    /**
     * Clé primaire non-incrémentale de type chaîne (ex: 'afro-chill', 'orig:...').
     */
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'title',
        'artist',
        'genre',
        'audio_url',
        'duration_seconds',
        'provenance',
        'license_type',
        'license_name',
        'license_url',
        'license_terms',
        'usage_restrictions',
        'rights_status',
        'is_published',
        'published_at',
        'base_uses',
        'uses_count',
        'is_original',
        'original_reel_id',
        'is_locked',
        'is_removed',
        'territories',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'base_uses' => 'integer',
            'uses_count' => 'integer',
            'is_original' => 'boolean',
            'is_locked' => 'boolean',
            'is_removed' => 'boolean',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'territories' => 'array',
        ];
    }

    /**
     * Auteur du son (dans le cas d'un son original).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Reel d'origine (dans le cas d'un son original).
     */
    public function originalReel(): BelongsTo
    {
        return $this->belongsTo(Reel::class, 'original_reel_id');
    }

    /**
     * Tous les reels utilisant ce son.
     */
    public function reels(): HasMany
    {
        return $this->hasMany(Reel::class, 'sound_id');
    }

    /**
     * Utilisateurs ayant ajouté ce son à leurs favoris.
     */
    public function favouritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_favourite_sounds', 'sound_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Total calculé des utilisations (base_uses + uses_count).
     */
    public function getTotalUsesAttribute(): int
    {
        return $this->base_uses + $this->uses_count;
    }

    /**
     * Scope pour filtrer les sons sélectionnables par les utilisateurs dans Studio Reel.
     */
    public function scopeAvailableForUsers(Builder $query): Builder
    {
        return $query->where('is_removed', false)
            ->where('is_published', true)
            ->where('rights_status', 'verified');
    }

    /**
     * Indique si le son est actuellement sélectionnable pour de nouveaux Reels.
     */
    public function isAvailableForCatalog(): bool
    {
        return ! $this->is_removed && $this->is_published && $this->rights_status === 'verified';
    }
}
