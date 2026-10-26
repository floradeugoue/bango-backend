<?php

namespace App\Models\Boost;

use App\Enums\Boost\CampaignObjective;
use App\Enums\Boost\CampaignStatus;
use App\Models\Listing\Listing;
use App\Models\Page\Page;
use App\Models\Payment\PaymentTransaction;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\User;
use App\Notifications\Boost\CampaignCompletedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'campaigns';

    protected $fillable = [
        'id',
        'user_id',
        'page_id',
        'boostable_type',
        'boostable_id',
        'objective',
        'budget_amount',
        'budget_currency',
        'duration_days',
        'start_at',
        'end_at',
        'targeting',
        'status',
        'stopped_reason',
        'payment_method',
        'payment_reference',
        'spent_amount',
        'impressions_count',
        'clicks_count',
        'reach_count',
        'metadata',
    ];

    /**
     * Liste blanche stricte des modèles autorisés à être boostés.
     * Empêche qu'une campagne puisse référencer arbitrairement un modèle Eloquent.
     */
    public const ALLOWED_BOOSTABLE_MAP = [
        'post' => Post::class,
        'posts' => Post::class,
        Post::class => Post::class,
        'reel' => Reel::class,
        'reels' => Reel::class,
        Reel::class => Reel::class,
        'listing' => Listing::class,
        'listings' => Listing::class,
        Listing::class => Listing::class,
        'page' => Page::class,
        'pages' => Page::class,
        Page::class => Page::class,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'objective' => CampaignObjective::class,
            'budget_amount' => 'decimal:2',
            'spent_amount' => 'decimal:2',
            'duration_days' => 'integer',
            'impressions_count' => 'integer',
            'clicks_count' => 'integer',
            'reach_count' => 'integer',
            'targeting' => 'array',
            'metadata' => 'array',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    /**
     * Utilisateur initiateur et propriétaire de la campagne.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Page Professionnelle au nom de laquelle la campagne est diffusée.
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Contenu sous-jacent promu (Post Square ou Reel vidéo).
     */
    public function boostable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Métriques quotidiennes enregistrées pour cette campagne.
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(CampaignMetric::class, 'campaign_id')->orderBy('metric_date');
    }

    /**
     * Impressions individuelles horodatées avec utilisateur.
     */
    public function impressions(): HasMany
    {
        return $this->hasMany(CampaignImpression::class, 'campaign_id');
    }

    /**
     * Clics individuels horodatés avec utilisateur.
     */
    public function clicks(): HasMany
    {
        return $this->hasMany(CampaignClick::class, 'campaign_id');
    }

    /**
     * Résultats et conversions attribuées.
     */
    public function results(): HasMany
    {
        return $this->hasMany(CampaignResult::class, 'campaign_id');
    }

    /**
     * Transaction de paiement externe éventuelle (PAY).
     */
    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_reference', 'reference');
    }

    /**
     * Vérifie si la transition de statut est valide selon la matrice métier.
     */
    public function canTransitionTo(CampaignStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }

    /**
     * Scope pour les campagnes actives en cours de diffusion.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CampaignStatus::Active->value)
            ->where(function ($q) {
                $q->whereNull('start_at')->orWhere('start_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_at')->orWhere('end_at', '>', now());
            })
            ->whereColumn('spent_amount', '<', 'budget_amount');
    }

    /**
     * Vérifie si la campagne est actuellement diffusable.
     */
    public function canBeServed(): bool
    {
        $this->checkAndMarkCompleted();

        if ($this->status !== CampaignStatus::Active) {
            return false;
        }

        if ($this->start_at && $this->start_at->isFuture()) {
            return false;
        }

        if ($this->end_at && $this->end_at->isPast()) {
            return false;
        }

        if ((float) $this->spent_amount >= (float) $this->budget_amount) {
            return false;
        }

        return true;
    }

    /**
     * Vérifie si la campagne doit passer en statut Completed (budget atteint ou date de fin dépassée).
     */
    public function checkAndMarkCompleted(): bool
    {
        if ($this->status !== CampaignStatus::Active) {
            return false;
        }

        $budgetExhausted = (float) $this->spent_amount >= (float) $this->budget_amount;
        $periodEnded = $this->end_at && $this->end_at->isPast();

        if ($budgetExhausted || $periodEnded) {
            if ($this->canTransitionTo(CampaignStatus::Completed)) {
                $this->status = CampaignStatus::Completed;
                $this->save();

                $this->user?->notify(new CampaignCompletedNotification($this));

                return true;
            }
        }

        return false;
    }

    /**
     * Résout la classe Eloquent sécurisée à partir du type court fourni.
     */
    public static function resolveBoostableClass(string $type): ?string
    {
        $normalized = strtolower(trim($type));

        return self::ALLOWED_BOOSTABLE_MAP[$normalized] ?? self::ALLOWED_BOOSTABLE_MAP[$type] ?? null;
    }
}
