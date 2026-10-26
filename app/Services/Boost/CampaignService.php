<?php

namespace App\Services\Boost;

use App\Enums\Boost\CampaignObjective;
use App\Enums\Boost\CampaignStatus;
use App\Enums\Boost\CreditTransactionType;
use App\Exceptions\Boost\InvalidBoostableContentException;
use App\Exceptions\Boost\InvalidCampaignTransitionException;
use App\Exceptions\Boost\UnauthorizedContentPromotionException;
use App\Models\Boost\Campaign;
use App\Models\Boost\CampaignClick;
use App\Models\Boost\CampaignImpression;
use App\Models\Boost\CampaignMetric;
use App\Models\Delegation\Delegation;
use App\Models\Listing\Listing;
use App\Models\Page\Page;
use App\Models\Payment\PaymentTransaction;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\User;
use App\Notifications\Boost\CampaignActivatedNotification;
use App\Notifications\Boost\CampaignSuspendedNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CampaignService
{
    public function __construct(
        protected CreditWalletService $creditWalletService,
        protected CampaignEstimateService $estimateService
    ) {}

    /**
     * Valide l'éligibilité et la propriété du contenu ciblé pour le boost.
     *
     * @throws InvalidBoostableContentException
     * @throws UnauthorizedContentPromotionException
     */
    public function validateContentForPromotion(User $user, string $boostableType, string|int $boostableId): Model
    {
        $resolvedClass = Campaign::resolveBoostableClass($boostableType);
        if (! $resolvedClass) {
            throw new InvalidBoostableContentException("Le type de contenu '{$boostableType}' n'est pas autorisé pour le boost.");
        }

        /** @var Model|null $content */
        $content = $resolvedClass::find($boostableId);
        if (! $content) {
            throw new InvalidBoostableContentException('Le contenu à promouvoir est introuvable ou a été supprimé.');
        }

        // 1. Vérification de la propriété
        if ($content instanceof Post) {
            if ($content->page_id) {
                /** @var Page|null $page */
                $page = Page::find($content->page_id);
                if (! $page || ! $page->isActive()) {
                    throw new UnauthorizedContentPromotionException('Cette Page Professionnelle est suspendue ou inactive.');
                }
                if (! $page->isCertified()) {
                    throw new UnauthorizedContentPromotionException('La Page Professionnelle doit être certifiée pour sponsoriser du contenu.');
                }
                if (! $page->canUserPublish($user)) {
                    throw new UnauthorizedContentPromotionException('Vous n\'êtes pas autorisé à sponsoriser une publication au nom de cette page.');
                }
            } elseif ((int) $content->user_id !== (int) $user->id) {
                throw new UnauthorizedContentPromotionException('Vous ne pouvez promouvoir que vos propres publications Square.');
            }
            if ($content->is_archived) {
                throw new InvalidBoostableContentException('Une publication Square archivée ne peut pas être boostée.');
            }
        } elseif ($content instanceof Reel) {
            if ($content->page_id) {
                /** @var Page|null $page */
                $page = Page::find($content->page_id);
                if (! $page || ! $page->isActive()) {
                    throw new UnauthorizedContentPromotionException('Cette Page Professionnelle est suspendue ou inactive.');
                }
                if (! $page->isCertified()) {
                    throw new UnauthorizedContentPromotionException('La Page Professionnelle doit être certifiée pour sponsoriser du contenu.');
                }
                if (! $page->canUserPublish($user)) {
                    throw new UnauthorizedContentPromotionException('Vous n\'êtes pas autorisé à sponsoriser un Reel au nom de cette page.');
                }
            } elseif ((int) $content->user_id !== (int) $user->id) {
                throw new UnauthorizedContentPromotionException('Vous ne pouvez promouvoir que vos propres Reels.');
            }
            if ($content->status === 'failed') {
                throw new InvalidBoostableContentException('Un Reel dont le traitement a échoué ne peut pas être boosté.');
            }
        } elseif ($content instanceof Listing) {
            $isOwner = (int) $content->owner_id === (int) $user->id;
            $isAgent = (int) $content->agent_id === (int) $user->id;
            $isPageManager = false;
            if ($content->page_id) {
                $page = Page::find($content->page_id);
                if (! $page || ! $page->isActive()) {
                    throw new UnauthorizedContentPromotionException('Cette Page Professionnelle est suspendue ou inactive.');
                }
                if (! $page->isCertified()) {
                    throw new UnauthorizedContentPromotionException('La Page Professionnelle doit être certifiée pour sponsoriser du contenu.');
                }
                $isPageManager = $page->canUserPublish($user);
            }
            $hasActiveDelegation = Delegation::where('listing_id', $content->id)
                ->where('agent_id', $user->id)
                ->active()
                ->exists();
            if (! $isOwner && ! $isAgent && ! $hasActiveDelegation && ! $isPageManager) {
                throw new UnauthorizedContentPromotionException('Vous n\'avez pas les droits pour promouvoir cette annonce immobilière.');
            }
            if ($content->status->value === 'taken') {
                throw new InvalidBoostableContentException('Un bien immobilier déjà loué ou indisponible ne peut pas être boosté.');
            }
        } elseif ($content instanceof Page) {
            if (! $content->isActive()) {
                throw new InvalidBoostableContentException('Une page suspendue ou inactive ne peut pas faire l\'objet de campagnes sponsorisées.');
            }
            if (! $content->isCertified()) {
                throw new InvalidBoostableContentException('La Page Professionnelle doit être certifiée pour lancer une campagne de boost.');
            }
            if (! $content->canUserPublish($user)) {
                throw new UnauthorizedContentPromotionException('Vous n\'êtes pas autorisé à sponsoriser cette Page Professionnelle.');
            }
        }

        return $content;
    }

    /**
     * Crée une nouvelle campagne publicitaire avec validation et rattachement financier.
     *
     * @param  array{
     *     boostable_type: string,
     *     boostable_id: string|int,
     *     objective?: string,
     *     budget: float|int|string,
     *     currency?: string,
     *     duration_days: int,
     *     targeting?: array<string, mixed>|null,
     *     payment_method?: string|null,
     *     payment_reference?: string|null,
     *     idempotency_key?: string|null
     * }  $data
     */
    public function createCampaign(User $user, array $data): Campaign
    {
        $boostableType = (string) $data['boostable_type'];
        $boostableId = (string) $data['boostable_id'];
        $budget = (float) $data['budget'];
        $currency = strtoupper($data['currency'] ?? 'XAF');
        $durationDays = max(1, (int) $data['duration_days']);
        $objective = isset($data['objective'])
            ? CampaignObjective::from($data['objective'])
            : CampaignObjective::Reach;
        $paymentMethod = $data['payment_method'] ?? 'credit';
        $idempotencyKey = $data['idempotency_key'] ?? null;

        // 1. Validation de l'éligibilité du contenu
        $content = $this->validateContentForPromotion($user, $boostableType, $boostableId);

        return DB::transaction(function () use (
            $user,
            $content,
            $objective,
            $budget,
            $currency,
            $durationDays,
            $data,
            $paymentMethod,
            $idempotencyKey
        ) {
            $status = CampaignStatus::Pending;
            $startAt = null;
            $endAt = null;

            // 2. Si le moyen de règlement est le Crédit BANGO, débit atomique immédiat
            if ($paymentMethod === 'credit') {
                $status = CampaignStatus::Active;
                $startAt = now();
                $endAt = now()->addDays($durationDays);
            }

            $pageId = $data['page_id'] ?? ($content instanceof Page
                ? $content->id
                : ($content->page_id ?? null));

            $targetPage = null;
            if ($pageId) {
                /** @var Page|null $targetPage */
                $targetPage = Page::find($pageId);
                if (! $targetPage || ! $targetPage->isActive()) {
                    throw new UnauthorizedContentPromotionException('Cette Page Professionnelle est suspendue ou inactive.');
                }
                if (! $targetPage->isCertified()) {
                    throw new UnauthorizedContentPromotionException('La Page Professionnelle doit être certifiée pour sponsoriser du contenu.');
                }
                if (! $targetPage->canUserPublish($user)) {
                    throw new UnauthorizedContentPromotionException('Vous n\'avez pas les permissions requises sur cette Page Professionnelle.');
                }
                if (! ($content instanceof Page) && (int) ($content->page_id ?? 0) !== (int) $targetPage->id) {
                    throw new UnauthorizedContentPromotionException('Le contenu à sponsoriser n\'appartient pas à la Page Professionnelle sélectionnée.');
                }
            }

            /** @var Campaign $campaign */
            $campaign = Campaign::create([
                'user_id' => $user->id,
                'page_id' => $pageId,
                'boostable_type' => $content->getMorphClass(),
                'boostable_id' => (string) $content->getKey(),
                'objective' => $objective,
                'budget_amount' => number_format($budget, 2, '.', ''),
                'budget_currency' => $currency,
                'duration_days' => $durationDays,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'targeting' => $data['targeting'] ?? [],
                'status' => $status,
                'payment_method' => $paymentMethod,
                'payment_reference' => $data['payment_reference'] ?? null,
                'spent_amount' => '0.00',
                'metadata' => array_filter([
                    'content_label' => $this->resolveContentLabel($content),
                    'sponsored_by' => $targetPage ? $targetPage->name : $user->name,
                    'page_handle' => $targetPage?->handle,
                    'sponsor_type' => $targetPage ? 'page' : 'user',
                    'idempotency_key' => $idempotencyKey,
                ]),
            ]);

            // Exécution du débit après la création de la campagne pour conserver la référence
            if ($paymentMethod === 'credit') {
                $debitKey = $idempotencyKey ? "campaign_debit_{$idempotencyKey}" : "campaign_debit_{$campaign->id}";
                $this->creditWalletService->debit(
                    account: $user,
                    amount: $budget,
                    type: CreditTransactionType::CampaignDebit,
                    description: sprintf('Financement campagne boost #%s (%s)', substr($campaign->id, 0, 8), $campaign->objective->label()),
                    idempotencyKey: $debitKey,
                    reference: $campaign
                );

                $user->notify(new CampaignActivatedNotification($campaign));
            }

            return $campaign;
        });
    }

    /**
     * Finance une campagne en attente avec le Crédit BANGO.
     */
    public function fundCampaignWithCredits(Campaign $campaign, User $user, ?string $idempotencyKey = null): Campaign
    {
        if ((int) $campaign->user_id !== (int) $user->id) {
            throw new UnauthorizedContentPromotionException('Vous ne pouvez pas financer une campagne appartenant à un tiers.');
        }

        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Pending], true)) {
            throw new InvalidCampaignTransitionException($campaign->status, CampaignStatus::Active, 'La campagne a déjà été financée ou traitée.');
        }

        return DB::transaction(function () use ($campaign, $user, $idempotencyKey) {
            $debitKey = $idempotencyKey ?? "campaign_fund_{$campaign->id}";

            $this->creditWalletService->debit(
                account: $user,
                amount: (float) $campaign->budget_amount,
                type: CreditTransactionType::CampaignDebit,
                description: sprintf('Règlement campagne boost #%s', substr($campaign->id, 0, 8)),
                idempotencyKey: $debitKey,
                reference: $campaign
            );

            $campaign->status = CampaignStatus::Active;
            $campaign->payment_method = 'credit';
            $campaign->start_at = now();
            $campaign->end_at = now()->addDays($campaign->duration_days);
            $campaign->save();

            $user->notify(new CampaignActivatedNotification($campaign));

            return $campaign;
        });
    }

    /**
     * Augmente le budget d'une campagne en cours ou active en débitant le Crédit BANGO (BO-07).
     *
     * @param  Campaign  $campaign  Campagne à recharger
     * @param  User  $user  Utilisateur propriétaire
     * @param  float|int  $amount  Montant à ajouter au budget_amount
     * @param  string|null  $idempotencyKey  Clé d'idempotence unique
     *
     * @throws UnauthorizedContentPromotionException
     * @throws InvalidArgumentException
     * @throws InsufficientCreditException
     */
    public function topUpCampaign(Campaign $campaign, User $user, float|int $amount, ?string $idempotencyKey = null): Campaign
    {
        if ((int) $campaign->user_id !== (int) $user->id) {
            throw new UnauthorizedContentPromotionException('Vous ne pouvez pas augmenter le budget d\'une campagne appartenant à un tiers.');
        }

        $floatAmount = (float) $amount;
        if ($floatAmount <= 0) {
            throw new InvalidArgumentException('Le montant à ajouter au budget doit être strictement positif.');
        }

        // Seules les campagnes actives ou en pause peuvent être rechargées en cours de route
        if (! in_array($campaign->status, [CampaignStatus::Active, CampaignStatus::Paused], true)) {
            throw new InvalidCampaignTransitionException($campaign->status, CampaignStatus::Active, 'Seule une campagne active ou en pause peut être rechargée.');
        }

        return DB::transaction(function () use ($campaign, $user, $floatAmount, $idempotencyKey) {
            /** @var Campaign $lockedCampaign */
            $lockedCampaign = Campaign::where('id', $campaign->id)->lockForUpdate()->firstOrFail();

            $debitKey = $idempotencyKey ?? Str::uuid()->toString();
            $campaignShortId = substr((string) $lockedCampaign->id, 0, 8);

            // Débite le crédit BANGO du portefeuille de l'utilisateur (lève InsufficientCreditException si solde insuffisant)
            $this->creditWalletService->debit(
                account: $user,
                amount: $floatAmount,
                source: 'boost',
                reference: $lockedCampaign,
                description: sprintf('Recharge budget campagne boost #%s (+%s FCFA)', $campaignShortId, number_format($floatAmount, 0, ',', ' ')),
                idempotencyKey: $debitKey,
                referenceModel: $lockedCampaign,
                metadata: [
                    'campaign_id' => (string) $lockedCampaign->id,
                    'top_up_amount' => $floatAmount,
                    'budget_before' => (float) $lockedCampaign->budget_amount,
                ],
                type: CreditTransactionType::CampaignDebit
            );

            // Ajoute le montant au budget existant
            $lockedCampaign->budget_amount = (float) $lockedCampaign->budget_amount + $floatAmount;
            $lockedCampaign->save();

            return $lockedCampaign;
        });
    }

    /**
     * Fulfiller appelé lorsque la transaction de paiement externe (PAY) est confirmée.
     */
    public function confirmPaymentFromTransaction(PaymentTransaction $transaction): Campaign
    {
        return DB::transaction(function () use ($transaction) {
            /** @var Campaign|null $campaign */
            $campaign = null;

            if ($transaction->payable_type === Campaign::class && $transaction->payable_id) {
                $campaign = Campaign::where('id', $transaction->payable_id)->lockForUpdate()->first();
            }

            if (! $campaign && $transaction->reference) {
                $campaign = Campaign::where('payment_reference', $transaction->reference)->lockForUpdate()->first();
            }

            if (! $campaign) {
                throw new InvalidArgumentException("Aucune campagne trouvée pour la transaction {$transaction->reference}.");
            }

            if ($campaign->status === CampaignStatus::Pending || $campaign->status === CampaignStatus::Draft) {
                $campaign->status = CampaignStatus::Active;
                $campaign->payment_method = 'external';
                $campaign->payment_reference = $transaction->reference;
                $campaign->start_at = now();
                $campaign->end_at = now()->addDays($campaign->duration_days);
                $campaign->save();

                $campaign->user?->notify(new CampaignActivatedNotification($campaign));
            }

            return $campaign;
        });
    }

    /**
     * Met en pause une campagne active.
     */
    public function pauseCampaign(Campaign $campaign, User $user): Campaign
    {
        if ((int) $campaign->user_id !== (int) $user->id) {
            throw new UnauthorizedContentPromotionException('Action non autorisée sur cette campagne.');
        }

        if (! $campaign->canTransitionTo(CampaignStatus::Paused)) {
            throw new InvalidCampaignTransitionException($campaign->status, CampaignStatus::Paused);
        }

        $campaign->status = CampaignStatus::Paused;
        $campaign->save();

        return $campaign;
    }

    /**
     * Reprend une campagne en pause.
     */
    public function resumeCampaign(Campaign $campaign, User $user): Campaign
    {
        if ((int) $campaign->user_id !== (int) $user->id) {
            throw new UnauthorizedContentPromotionException('Action non autorisée sur cette campagne.');
        }

        if (! $campaign->canTransitionTo(CampaignStatus::Active)) {
            throw new InvalidCampaignTransitionException($campaign->status, CampaignStatus::Active);
        }

        $campaign->status = CampaignStatus::Active;
        $campaign->save();

        return $campaign;
    }

    /**
     * Annule ou interrompt une campagne et rembourse le reliquat budgétaire en Crédit BANGO.
     */
    public function cancelCampaign(Campaign $campaign, User $user, string $reason = 'manual'): Campaign
    {
        if ((int) $campaign->user_id !== (int) $user->id) {
            throw new UnauthorizedContentPromotionException('Action non autorisée sur cette campagne.');
        }

        if (! $campaign->canTransitionTo(CampaignStatus::Cancelled)) {
            throw new InvalidCampaignTransitionException($campaign->status, CampaignStatus::Cancelled);
        }

        return DB::transaction(function () use ($campaign, $reason) {
            /** @var Campaign $lockedCampaign */
            $lockedCampaign = Campaign::where('id', $campaign->id)->lockForUpdate()->firstOrFail();

            $unspent = max(0.0, (float) $lockedCampaign->budget_amount - (float) $lockedCampaign->spent_amount);

            $lockedCampaign->status = CampaignStatus::Cancelled;
            $lockedCampaign->stopped_reason = $reason;
            $lockedCampaign->save();

            // Restitution automatique du reliquat non consommé en Crédit BANGO (BO-08 / EDGE-04)
            if ($unspent > 0) {
                $targetUser = $lockedCampaign->user ?? User::findOrFail($lockedCampaign->user_id);
                $campaignShortId = substr((string) $lockedCampaign->id, 0, 8);

                $this->creditWalletService->credit(
                    account: $targetUser,
                    amount: $unspent,
                    type: CreditTransactionType::CampaignRefund,
                    description: "Remboursement campagne boost #{$campaignShortId}",
                    idempotencyKey: "campaign_cancel_refund_{$lockedCampaign->id}",
                    reference: $lockedCampaign,
                    metadata: [
                        'campaign_id' => (string) $lockedCampaign->id,
                        'reason' => $reason,
                        'budget_amount' => (float) $lockedCampaign->budget_amount,
                        'spent_amount' => (float) $lockedCampaign->spent_amount,
                        'refunded_amount' => $unspent,
                    ],
                    source: 'boost'
                );
            }

            return $lockedCampaign;
        });
    }

    /**
     * Suspend une campagne active en cas de modération, indisponibilité de bien ou inéligibilité.
     * Restitue également le reliquat budgétaire en Crédit BANGO au commanditaire.
     */
    public function suspendCampaign(Campaign $campaign, string $reason = 'moderation'): Campaign
    {
        return DB::transaction(function () use ($campaign, $reason) {
            /** @var Campaign $lockedCampaign */
            $lockedCampaign = Campaign::where('id', $campaign->id)->lockForUpdate()->firstOrFail();

            if (! $lockedCampaign->canTransitionTo(CampaignStatus::Suspended)) {
                throw new InvalidCampaignTransitionException($lockedCampaign->status, CampaignStatus::Suspended);
            }

            $unspent = max(0.0, (float) $lockedCampaign->budget_amount - (float) $lockedCampaign->spent_amount);

            $lockedCampaign->status = CampaignStatus::Suspended;
            $lockedCampaign->stopped_reason = $reason;
            $lockedCampaign->save();

            // Restitution automatique du reliquat non consommé en Crédit BANGO
            if ($unspent > 0) {
                $targetUser = $lockedCampaign->user ?? User::findOrFail($lockedCampaign->user_id);
                $campaignShortId = substr((string) $lockedCampaign->id, 0, 8);

                $this->creditWalletService->credit(
                    account: $targetUser,
                    amount: $unspent,
                    type: CreditTransactionType::CampaignRefund,
                    description: "Remboursement campagne boost #{$campaignShortId}",
                    idempotencyKey: "campaign_suspend_refund_{$lockedCampaign->id}",
                    reference: $lockedCampaign,
                    metadata: [
                        'campaign_id' => (string) $lockedCampaign->id,
                        'reason' => $reason,
                        'budget_amount' => (float) $lockedCampaign->budget_amount,
                        'spent_amount' => (float) $lockedCampaign->spent_amount,
                        'refunded_amount' => $unspent,
                    ],
                    source: 'boost'
                );
            }

            $lockedCampaign->user?->notify(new CampaignSuspendedNotification($lockedCampaign, $reason));

            return $lockedCampaign;
        });
    }

    /**
     * Enregistre ou agrège des métriques quotidiennes pour une campagne, avec traçabilité unitaire.
     */
    public function recordDailyMetric(
        Campaign $campaign,
        string $date,
        int $impressions,
        int $clicks,
        int $reach,
        float $spent,
        ?User $user = null
    ): CampaignMetric {
        return DB::transaction(function () use ($campaign, $date, $impressions, $clicks, $reach, $spent, $user) {
            $dateString = Carbon::parse($date)->toDateString();

            // 1. Détermination si cette impression concerne une NOUVELLE personne distincte
            $isNewPerson = false;
            if ($impressions > 0) {
                if ($user) {
                    $hasSeenBefore = CampaignImpression::where('campaign_id', $campaign->id)
                        ->where('user_id', $user->id)
                        ->exists();
                    $isNewPerson = ! $hasSeenBefore;
                } else {
                    $isNewPerson = true;
                }

                CampaignImpression::create([
                    'campaign_id' => $campaign->id,
                    'user_id' => $user?->id,
                    'shown_at' => now(),
                ]);
            }

            // 2. Enregistrement unitaire de clic
            if ($clicks > 0) {
                CampaignClick::create([
                    'campaign_id' => $campaign->id,
                    'user_id' => $user?->id,
                    'clicked_at' => now(),
                ]);
            }

            // 3. Calcul de la dépense : à chaque impression d'une nouvelle personne, dépenser CPM / 1000
            $calculatedSpent = $spent;
            if ($calculatedSpent <= 0 && $isNewPerson) {
                $cpm = $this->estimateService->resolveCpmPrice();
                $calculatedSpent = round($cpm / 1000.0, 4);
            }

            /** @var CampaignMetric|null $metric */
            $metric = CampaignMetric::where('campaign_id', $campaign->id)
                ->whereDate('metric_date', $dateString)
                ->lockForUpdate()
                ->first();

            if (! $metric) {
                $metric = new CampaignMetric([
                    'campaign_id' => $campaign->id,
                    'metric_date' => $dateString,
                    'impressions' => 0,
                    'clicks' => 0,
                    'reach' => 0,
                    'spent' => '0.00',
                ]);
            }

            // Calcul du reach quotidien distinct
            $distinctUsersToday = CampaignImpression::query()
                ->where('campaign_id', $campaign->id)
                ->whereDate('shown_at', $dateString)
                ->whereNotNull('user_id')
                ->distinct('user_id')
                ->count('user_id');

            // Nombre d'impressions anonymes du jour (sans user_id)
            $anonymousImpressionsToday = CampaignImpression::query()
                ->where('campaign_id', $campaign->id)
                ->whereDate('shown_at', $dateString)
                ->whereNull('user_id')
                ->count();

            $totalReachToday = $distinctUsersToday + $anonymousImpressionsToday;

            $metric->impressions += $impressions;
            $metric->clicks += $clicks;
            $metric->reach = max($metric->reach + $reach, $totalReachToday);
            $metric->spent = number_format((float) $metric->spent + $calculatedSpent, 2, '.', '');
            $metric->save();

            // Mise à jour des totaux de la campagne
            if ($impressions > 0) {
                $campaign->increment('impressions_count', $impressions);
            }
            if ($clicks > 0) {
                $campaign->increment('clicks_count', $clicks);
            }
            if ($calculatedSpent > 0) {
                $campaign->increment('spent_amount', $calculatedSpent);
            }

            // Calcul strict de reach_count : personnes distinctes tracées ou incrément batch fourni
            $distinctUsersAllTime = CampaignImpression::query()
                ->where('campaign_id', $campaign->id)
                ->whereNotNull('user_id')
                ->distinct('user_id')
                ->count('user_id');

            $anonymousAllTime = CampaignImpression::query()
                ->where('campaign_id', $campaign->id)
                ->whereNull('user_id')
                ->count();

            $totalDistinctImpressions = $distinctUsersAllTime + $anonymousAllTime;

            if ($reach > 0 && $impressions > 1) {
                // Appel batch agrégé (ex: simulation de lot ou synchronisation de lot de métriques)
                $campaign->increment('reach_count', $reach);
            } else {
                $campaign->update(['reach_count' => $totalDistinctImpressions]);
            }

            // Vérification et clôture automatique si budget épuisé ou end_at dépassé
            $campaign->refresh();
            $campaign->checkAndMarkCompleted();

            return $metric->fresh();
        });
    }

    /**
     * Résout un libellé textuel court pour le contenu promu.
     */
    protected function resolveContentLabel(Model $content): string
    {
        if ($content instanceof Post) {
            return $content->body ? mb_substr($content->body, 0, 60) : 'Publication Square';
        }

        if ($content instanceof Reel) {
            return $content->caption ? mb_substr($content->caption, 0, 60) : 'Reel vidéo';
        }

        if ($content instanceof Listing) {
            return $content->title ?: 'Annonce immobilière';
        }

        return 'Contenu sponsorisé';
    }
}
