<?php

namespace App\Models\Legal;

use App\Models\Payment\PaymentTransaction;
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

    protected $appends = [
        'acceptances_count',
        'concerned_accounts_count',
        'total_accounts',
        'acceptance',
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

    public static function normalizeSlug(string $slug): string
    {
        return match ($slug) {
            'cgu', 'terms', 'terms-of-service' => 'terms',
            'privacy', 'privacy-policy' => 'privacy',
            'content', 'content-rules' => 'content',
            'boosts', 'boost-rules' => 'boosts',
            'badges', 'badge-rules' => 'badges',
            'imprint', 'legal-notice' => 'imprint',
            default => $slug,
        };
    }

    public static function slugVariants(string $slug): array
    {
        return match (self::normalizeSlug($slug)) {
            'terms' => ['terms', 'terms-of-service', 'cgu'],
            'privacy' => ['privacy', 'privacy-policy'],
            'content' => ['content', 'content-rules'],
            'boosts' => ['boosts', 'boost-rules'],
            'badges' => ['badges', 'badge-rules'],
            'imprint' => ['imprint', 'legal-notice'],
            default => [$slug],
        };
    }

    public function getAcceptancesCountAttribute(): int
    {
        if (array_key_exists('acceptances_count', $this->attributes)) {
            return (int) $this->attributes['acceptances_count'];
        }

        $acceptedUserIds = TermsAcceptance::where(function ($q) {
            $q->where('legal_document_id', $this->id)
                ->orWhere(function ($sub) {
                    $sub->whereIn('document_slug', self::slugVariants($this->slug))
                        ->where('version', $this->version);
                });
        })->pluck('user_id');

        if (in_array(self::normalizeSlug($this->slug), ['terms', 'cgu', 'terms-of-service'], true)) {
            $userAcceptedIds = User::where('accepted_terms_version', $this->version)->pluck('id');

            return $acceptedUserIds->merge($userAcceptedIds)->unique()->count();
        }

        return $acceptedUserIds->unique()->count();
    }

    public function getConcernedAccountsCountAttribute(): int
    {
        if (array_key_exists('concerned_accounts_count', $this->attributes)) {
            return (int) $this->attributes['concerned_accounts_count'];
        }

        $normalized = self::normalizeSlug($this->slug);

        return match ($normalized) {
            'boosts' => PaymentTransaction::where('purpose', 'boost')->distinct('user_id')->count('user_id') ?: User::count(),
            'badges' => User::whereNotNull('badge')->count() ?: User::count(),
            'imprint' => 0,
            default => User::count(),
        };
    }

    public function getTotalAccountsAttribute(): int
    {
        return $this->getConcernedAccountsCountAttribute();
    }

    public function getAcceptanceAttribute(): ?array
    {
        if (array_key_exists('acceptance', $this->attributes) && is_array($this->attributes['acceptance'])) {
            return $this->attributes['acceptance'];
        }

        $normalized = self::normalizeSlug($this->slug);
        $audience = match ($normalized) {
            'boosts' => 'buyers',
            'badges' => 'badged',
            'imprint' => 'none',
            default => 'all',
        };

        if ($audience === 'none') {
            return null;
        }

        return [
            'accepted' => $this->getAcceptancesCountAttribute(),
            'total' => $this->getConcernedAccountsCountAttribute(),
            'audience' => $audience,
        ];
    }
}
