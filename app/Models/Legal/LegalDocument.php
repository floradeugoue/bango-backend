<?php

namespace App\Models\Legal;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegalDocument extends Model
{
    use HasFactory;

    protected $table = 'legal_documents';

    protected $fillable = [
        'slug',
        'version',
        'locale',
        'title',
        'content_markdown',
        'effective_date',
        'is_published',
        'published_at',
        'is_mandatory',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_date' => 'datetime',
            'published_at' => 'datetime',
            'is_published' => 'boolean',
            'is_mandatory' => 'boolean',
        ];
    }

    /**
     * Scope pour filtrer les documents publiés et actifs.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->where('effective_date', '<=', now());
    }

    /**
     * Scope pour filtrer par slug et langue.
     */
    public function scopeForSlugAndLocale(Builder $query, string $slug, string $locale): Builder
    {
        return $query->where('slug', $slug)
            ->where('locale', $locale);
    }

    /**
     * Relation vers l'auteur ou gestionnaire juridique.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Acceptations enregistrées pour ce document.
     */
    public function acceptances(): HasMany
    {
        return $this->hasMany(TermsAcceptance::class, 'legal_document_id');
    }
}
