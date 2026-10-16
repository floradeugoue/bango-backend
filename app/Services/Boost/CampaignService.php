<?php

namespace App\Services\Boost;

use App\Enums\Boost\CampaignObjective;
use App\Enums\Boost\CampaignStatus;
use App\Enums\Boost\CreditTransactionType;
use App\Exceptions\Boost\InvalidBoostableContentException;
use App\Exceptions\Boost\InvalidCampaignTransitionException;
use App\Exceptions\Boost\UnauthorizedContentPromotionException;
use App\Models\Boost\Campaign;
use App\Models\Boost\CampaignMetric;
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
use InvalidArgumentException;

class CampaignService
{
    public function __construct(
        protected CreditWalletService $creditWalletService
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
            if (! $isOwner && ! $isAgent && ! $isPageManager) {
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

            // Règle CB immuable : consommation définitive, aucune restitution automatique de crédits lors de l'annulation

            return $lockedCampaign;
        });
    }

    /**
     * Suspend une campagne active en cas de modération, indisponibilité de bien ou inéligibilité.
     */
    public function suspendCampaign(Campaign $campaign, string $reason = 'moderation'): Campaign
    {
        return DB::transaction(function () use ($campaign, $reason) {
            /** @var Campaign $lockedCampaign */
            $lockedCampaign = Campaign::where('id', $campaign->id)->lockForUpdate()->firstOrFail();

            if (! $lockedCampaign->canTransitionTo(CampaignStatus::Suspended)) {
                throw new InvalidCampaignTransitionException($lockedCampaign->status, CampaignStatus::Suspended);
            }

            $lockedCampaign->status = CampaignStatus::Suspended;
            $lockedCampaign->stopped_reason = $reason;
            $lockedCampaign->save();

            $lockedCampaign->user?->notify(new CampaignSuspendedNotification($lockedCampaign, $reason));

            return $lockedCampaign;
        });
    }

    /**
     * Enregistre ou agrège des métriques quotidiennes pour une campagne.
     */
    public function recordDailyMetric(
        Campaign $campaign,
        string $date,
        int $impressions,
        int $clicks,
        int $reach,
        float $spent
    ): CampaignMetric {
        return DB::transaction(function () use ($campaign, $date, $impressions, $clicks, $reach, $spent) {
            /** @var CampaignMetric|null $metric */
            $dateString = Carbon::parse($date)->toDateString();
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

            $metric->impressions += $impressions;
            $metric->clicks += $clicks;
            $metric->reach += $reach;
            $metric->spent = number_format((float) $metric->spent + $spent, 2, '.', '');
            $metric->save();

            // Mise à jour des compteurs globaux de la campagne
            $campaign->increment('impressions_count', $impressions);
            $campaign->increment('clicks_count', $clicks);
            $campaign->increment('reach_count', $reach);
            $campaign->increment('spent_amount', $spent);

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
