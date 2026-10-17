<?php

namespace App\Models\Listing;

use App\Enums\Delegation\DelegationStatus;
use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Models\Boost\Campaign;
use App\Models\Delegation\Delegation;
use App\Models\Message\Conversation;
use App\Models\Page\Page;
use App\Models\Reel\Reel;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use Database\Factories\Listing\ListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'listings';

    protected $fillable = [
        'id',
        'owner_id',
        'created_by_id',
        'agent_id',
        'page_id',
        'submission_key',
        'title',
        'description',
        'mode',
        'kind',
        'status',
        'price',
        'currency',
        'period',
        'deposit_months',
        'advance_months',
        'visit_fee',
        'charges',
        'negotiable',
        'utilities_included',
        'bedrooms',
        'showers',
        'area',
        'amenities',
        'features',
        'attributes',
        'agent_visited',
        'sponsored',
        'city',
        'neighbourhood',
        'location',
        'address',
        'lat',
        'lng',
        'views_count',
        'saves_count',
        'pending_visits_count',
        'submitted_at',
        'review_due_at',
        'published_at',
        'taken_at',
        'rejection_reason',
        'draft_step',
        'draft_data',
        'video_metrics',
    ];

    protected function casts(): array
    {
        return [
            'mode' => ListingMode::class,
            'kind' => ListingKind::class,
            'status' => ListingStatus::class,
            'price' => 'decimal:2',
            'visit_fee' => 'decimal:2',
            'charges' => 'decimal:2',
            'area' => 'decimal:2',
            'lat' => 'float',
            'lng' => 'float',
            'deposit_months' => 'integer',
            'advance_months' => 'integer',
            'bedrooms' => 'integer',
            'showers' => 'integer',
            'negotiable' => 'boolean',
            'utilities_included' => 'boolean',
            'agent_visited' => 'boolean',
            'sponsored' => 'boolean',
            'views_count' => 'integer',
            'saves_count' => 'integer',
            'pending_visits_count' => 'integer',
            'amenities' => 'array',
            'features' => 'array',
            'attributes' => 'array',
            'draft_data' => 'array',
            'video_metrics' => 'array',
            'submitted_at' => 'datetime',
            'review_due_at' => 'datetime',
            'published_at' => 'datetime',
            'taken_at' => 'datetime',
        ];
    }

    /**
     * Propriétaire légitime de l'annonce ou du bien.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Utilisateur ayant initialisé ou soumis l'annonce.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Agent immobilier professionnel gestionnaire (délégué).
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Page Professionnelle (Agence Immobilière) associée à l'annonce.
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    /**
     * Tous les médias ordonnés de l'annonce.
     */
    public function media(): HasMany
    {
        return $this->hasMany(ListingMedia::class, 'listing_id')->orderBy('order', 'asc');
    }

    /**
     * Photos associées à l'annonce.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ListingMedia::class, 'listing_id')
            ->where('type', 'image')
            ->orderBy('order', 'asc');
    }

    /**
     * Vidéos associées à l'annonce (visite guidée principale et clips).
     */
    public function videos(): HasMany
    {
        return $this->hasMany(ListingMedia::class, 'listing_id')
            ->where('type', 'video')
            ->orderBy('order', 'asc');
    }

    /**
     * Enregistrements / favoris de cette annonce.
     */
    public function saves(): HasMany
    {
        return $this->hasMany(ListingSave::class, 'listing_id');
    }

    /**
     * Utilisateurs ayant sauvegardé cette annonce.
     */
    public function savedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'listing_saves', 'listing_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Reels faisant référence à cette annonce.
     */
    public function reels(): HasMany
    {
        return $this->hasMany(Reel::class, 'listing_id');
    }

    /**
     * Créneaux de visite programmés sur cette annonce.
     */
    public function visitSlots(): HasMany
    {
        return $this->hasMany(VisitSlot::class, 'listing_id');
    }

    /**
     * Réservations de visites associées à cette annonce.
     */
    public function visitReservations(): HasMany
    {
        return $this->hasMany(VisitReservation::class, 'listing_id');
    }

    /**
     * Mandats et délégations attachés à cette annonce.
     */
    public function delegations(): HasMany
    {
        return $this->hasMany(Delegation::class, 'listing_id');
    }

    /**
     * Conversations contextuelles liées à cette annonce.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'listing_id');
    }

    /**
     * Mandat actif actuel de l'annonce.
     */
    public function activeDelegation(): HasOne
    {
        return $this->hasOne(Delegation::class, 'listing_id')
            ->where('status', DelegationStatus::Active->value)
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Détermine si un utilisateur dispose d'une visite formellement confirmée sur ce bien.
     */
    public function hasConfirmedVisitBy(User $user): bool
    {
        return $this->visitReservations()
            ->where('prospect_id', $user->id)
            ->where('status', VisitReservationStatus::Confirmed->value)
            ->exists();
    }

    /**
     * Scope pour les annonces strictement publiées.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ListingStatus::Published->value);
    }

    /**
     * Scope pour les annonces visibles publiquement (publiées ou réservées).
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ListingStatus::Published->value,
            ListingStatus::Reserved->value,
        ]);
    }

    /**
     * Scope pour filtrer les annonces en tenant compte des critères de recherche IM.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        // 1. Mode de transaction (rent, sale, flatshare)
        if (! empty($filters['mode'])) {
            $mode = $filters['mode'] instanceof ListingMode ? $filters['mode']->value : (string) $filters['mode'];
            $query->where('mode', $mode);
        }

        // 2. Ville
        if (! empty($filters['city'])) {
            $query->whereRaw('LOWER(city) = ?', [strtolower(trim((string) $filters['city']))]);
        }

        // 3. Quartier / Place / Recherche textuelle
        if (! empty($filters['neighbourhood'])) {
            $query->whereRaw('LOWER(neighbourhood) = ?', [strtolower(trim((string) $filters['neighbourhood']))]);
        } elseif (! empty($filters['place'])) {
            $place = strtolower(trim((string) $filters['place']));
            $query->where(function (Builder $q) use ($place) {
                $q->whereRaw('LOWER(neighbourhood) LIKE ?', ["%{$place}%"])
                    ->orWhereRaw('LOWER(location) LIKE ?', ["%{$place}%"])
                    ->orWhereRaw('LOWER(title) LIKE ?', ["%{$place}%"]);
            });
        }

        // 4. Recherche textuelle globale (q / search)
        if (! empty($filters['q'])) {
            $term = strtolower(trim((string) $filters['q']));
            $query->where(function (Builder $q) use ($term) {
                $q->whereRaw('LOWER(title) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(description) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(city) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(neighbourhood) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(location) LIKE ?', ["%{$term}%"]);
            });
        }

        // 5. Budget (min_price, max_price ou budget: [min, max])
        $minPrice = $filters['min_price'] ?? null;
        $maxPrice = $filters['max_price'] ?? null;

        if (isset($filters['budget']) && is_array($filters['budget'])) {
            $minPrice = $filters['budget'][0] ?? $minPrice;
            $maxPrice = $filters['budget'][1] ?? $maxPrice;
        }

        $budgetIncludesCharges = (bool) ($filters['budget_includes_charges'] ?? $filters['budgetIncludesCharges'] ?? false);

        if ($minPrice !== null && is_numeric($minPrice) && (float) $minPrice > 0) {
            if ($budgetIncludesCharges) {
                $query->whereRaw('(price + charges) >= ?', [(float) $minPrice]);
            } else {
                $query->where('price', '>=', (float) $minPrice);
            }
        }

        if ($maxPrice !== null && is_numeric($maxPrice) && (float) $maxPrice > 0) {
            if ($budgetIncludesCharges) {
                $query->whereRaw('(price + charges) <= ?', [(float) $maxPrice]);
            } else {
                $query->where('price', '<=', (float) $maxPrice);
            }
        }

        // 6. Chambres (bedrooms) : supporte un entier, ou un tableau ex: [0, 1, 2, "4+"]
        if (isset($filters['bedrooms'])) {
            $bedrooms = is_array($filters['bedrooms']) ? $filters['bedrooms'] : [$filters['bedrooms']];
            if (! empty($bedrooms)) {
                $hasPlus = false;
                $plusThreshold = 4;
                $exacts = [];

                foreach ($bedrooms as $b) {
                    $bStr = (string) $b;
                    if (str_ends_with($bStr, '+')) {
                        $hasPlus = true;
                        $plusThreshold = (int) rtrim($bStr, '+');
                    } elseif (is_numeric($bStr)) {
                        $exacts[] = (int) $bStr;
                    }
                }

                $query->where(function (Builder $q) use ($exacts, $hasPlus, $plusThreshold) {
                    if (! empty($exacts)) {
                        $q->whereIn('bedrooms', $exacts);
                    }
                    if ($hasPlus) {
                        $method = ! empty($exacts) ? 'orWhere' : 'where';
                        $q->$method('bedrooms', '>=', $plusThreshold ?? 4);
                    }
                });
            }
        }

        // 7. Bailleurs vérifiés uniquement (verifiedOnly / verified_only)
        $verifiedOnly = (bool) ($filters['verified_only'] ?? $filters['verifiedOnly'] ?? false);
        if ($verifiedOnly) {
            $query->whereHas('owner', function (Builder $q) {
                $q->where('is_verified', true)
                    ->whereIn('badge', ['landlord', 'agent', 'double']);
            });
        }

        // 8. Visité par un agent BANGO uniquement (agentVisitedOnly / agent_visited_only)
        $agentVisitedOnly = (bool) ($filters['agent_visited_only'] ?? $filters['agentVisitedOnly'] ?? false);
        if ($agentVisitedOnly) {
            $query->where('agent_visited', true);
        }

        // 9. Équipements (amenities)
        if (! empty($filters['amenities']) && is_array($filters['amenities'])) {
            foreach ($filters['amenities'] as $amenity) {
                $query->whereJsonContains('amenities', (string) $amenity);
            }
        }

        // 10. Emprise géographique (bounding box)
        if (isset($filters['north'], $filters['south'], $filters['east'], $filters['west'])) {
            $north = (float) $filters['north'];
            $south = (float) $filters['south'];
            $east = (float) $filters['east'];
            $west = (float) $filters['west'];

            $query->whereBetween('lat', [min($south, $north), max($south, $north)])
                ->whereBetween('lng', [min($west, $east), max($west, $east)]);
        }

        return $query;
    }

    /**
     * Vérifie si l'annonce est sauvegardée par l'utilisateur donné.
     */
    public function isSavedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->relationLoaded('saves')) {
            return $this->saves->contains('user_id', $user->id);
        }

        return $this->saves()->where('user_id', $user->id)->exists();
    }

    /**
     * Première photo (couverture).
     */
    public function mainPhoto(): ?ListingMedia
    {
        if ($this->relationLoaded('photos')) {
            return $this->photos->first();
        }

        return $this->photos()->first();
    }

    /**
     * Vidéo guidée principale.
     */
    public function mainVideo(): ?ListingMedia
    {
        if ($this->relationLoaded('videos')) {
            return $this->videos->first();
        }

        return $this->videos()->first();
    }

    /**
     * Campagnes publicitaires associées à cette annonce.
     */
    public function campaigns(): MorphMany
    {
        return $this->morphMany(Campaign::class, 'boostable');
    }

    /**
     * Historique des fusions de doublons de l'annonce.
     */
    public function merges(): HasMany
    {
        return $this->hasMany(ListingMerge::class, 'primary_listing_id');
    }

    /**
     * Certifications de visites physiques enregistrées sur l'annonce.
     */
    public function agentCertifications(): HasMany
    {
        return $this->hasMany(ListingAgentCertification::class, 'listing_id');
    }

    /**
     * Journal d'audit des contrôles et revues d'annonce.
     */
    public function reviewLogs(): HasMany
    {
        return $this->hasMany(ListingReviewLog::class, 'listing_id');
    }
}
