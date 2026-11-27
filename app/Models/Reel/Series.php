<?php

namespace App\Models\Reel;

use App\Models\Page\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Series extends Model
{
    use HasFactory;

    protected $table = 'series';

    protected $fillable = [
        'title',
        'slug',
        'description',
        'cover_url',
        'user_id',
        'page_id',
        'episodes_count',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'episodes_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Series $series) {
            if (empty($series->slug)) {
                $series->slug = Str::slug($series->title);
            }
        });
    }

    /**
     * Alias getter pour le nom de la série.
     */
    public function getNameAttribute(): string
    {
        return $this->title;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Épisodes (réels) ordonnés par numéro d'épisode puis date de création.
     */
    public function reels(): HasMany
    {
        return $this->hasMany(Reel::class, 'series_id')
            ->orderBy('episode_number', 'asc')
            ->orderBy('created_at', 'asc');
    }

    /**
     * Alias de la relation reels.
     */
    public function episodes(): HasMany
    {
        return $this->reels();
    }

    /**
     * Recalcule et persiste le nombre d'épisodes de la série.
     */
    public function refreshEpisodesCount(): int
    {
        $count = $this->reels()->count();
        $this->update(['episodes_count' => $count]);

        return $count;
    }
}
