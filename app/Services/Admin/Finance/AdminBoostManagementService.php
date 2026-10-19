<?php

namespace App\Services\Admin\Finance;

use App\Enums\Boost\CampaignStatus;
use App\Enums\Boost\CreditTransactionType;
use App\Models\Boost\Campaign;
use App\Models\Boost\CreditTransaction;
use App\Models\User;
use App\Notifications\Boost\CampaignSuspendedNotification;
use App\Services\Boost\CreditWalletService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminBoostManagementService
{
    public function __construct(
        protected CreditWalletService $creditWalletService
    ) {}

    /**
     * 5.1. Liste paginée des campagnes de Boost avec métriques et filtres.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getCampaigns(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = Campaign::with(['user:id,name,email,phone,handle', 'page:id,name,slug'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['boostable_type'])) {
            $resolvedClass = Campaign::resolveBoostableClass($filters['boostable_type']);
            if ($resolvedClass) {
                $query->where('boostable_type', $resolvedClass);
            }
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', Carbon::parse($filters['from']));
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', Carbon::parse($filters['to']));
        }

        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $query->where(function (Builder $sub) use ($term, $like) {
                $sub->where('id', 'like', "%{$term}%")
                    ->orWhere('payment_reference', $like, "%{$term}%")
                    ->orWhereHas('user', function (Builder $uq) use ($term, $like) {
                        $uq->where('name', $like, "%{$term}%")
                            ->orWhere('email', $like, "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%");
                    });
            });
        }

        $boundedPerPage = min(100, max(1, $perPage));

        return $query->paginate($boundedPerPage);
    }

    /**
     * 5.2. Fiche détaillée d'une campagne de Boost et historique des restitutions.
     */
    public function getCampaignDetail(string $id): ?array
    {
        /** @var Campaign|null $campaign */
        $campaign = Campaign::with(['user', 'page', 'metrics'])
            ->where('id', $id)
            ->first();

        if (! $campaign) {
            return null;
        }

        // Restitutions de crédits déjà effectuées pour cette campagne
        $restitutions = CreditTransaction::where('type', CreditTransactionType::CampaignRefund)
            ->where(function (Builder $q) use ($campaign) {
                $q->where('reference_id', (string) $campaign->id)
                    ->orWhere('reference', (string) $campaign->id)
                    ->orWhere('idempotency_key', "boost_suspend_{$campaign->id}");
            })
            ->get();

        $budget = (float) $campaign->budget_amount;
        $spent = (float) $campaign->spent_amount;
        $remaining = max(0.0, $budget - $spent);

        return [
            'id' => (string) $campaign->id,
            'user' => $campaign->user ? [
                'id' => $campaign->user->id,
                'name' => $campaign->user->name,
                'email' => $campaign->user->email,
                'phone' => $campaign->user->phone,
            ] : null,
            'page' => $campaign->page ? [
                'id' => $campaign->page->id,
                'name' => $campaign->page->name,
                'slug' => $campaign->page->slug,
            ] : null,
            'boostable_type' => class_basename($campaign->boostable_type),
            'boostable_id' => (string) $campaign->boostable_id,
            'objective' => $campaign->objective->value,
            'budget_amount' => $budget,
            'spent_amount' => $spent,
            'remaining_budget' => $remaining,
            'currency' => $campaign->budget_currency ?: 'XAF',
            'duration_days' => $campaign->duration_days,
            'status' => $campaign->status->value,
            'status_label' => $campaign->status->label(),
            'stopped_reason' => $campaign->stopped_reason,
            'payment_method' => $campaign->payment_method,
            'payment_reference' => $campaign->payment_reference,
            'targeting' => $campaign->targeting,
            'metrics' => [
                'impressions' => $campaign->impressions_count,
                'clicks' => $campaign->clicks_count,
                'reach' => $campaign->reach_count,
                'daily_metrics' => $campaign->metrics,
            ],
            'restitutions' => $restitutions->map(fn ($r) => [
                'id' => $r->id,
                'amount' => $r->amount,
                'reference' => $r->reference,
                'created_at' => $r->created_at?->toIso8601String(),
                'description' => $r->description,
            ]),
            'start_at' => $campaign->start_at?->toIso8601String(),
            'end_at' => $campaign->end_at?->toIso8601String(),
            'created_at' => $campaign->created_at?->toIso8601String(),
            'updated_at' => $campaign->updated_at?->toIso8601String(),
        ];
    }

    /**
     * 5.3. Liste des restitutions de budget de Boost enregistrées dans le grand livre.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getCampaignRefunds(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = CreditTransaction::with(['user:id,name,email,phone', 'wallet:id,balance,currency'])
            ->where('type', CreditTransactionType::CampaignRefund)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['campaign_id'])) {
            $campId = (string) $filters['campaign_id'];
            $query->where(function (Builder $q) use ($campId) {
                $q->where('reference_id', $campId)
                    ->orWhere('reference', $campId)
                    ->orWhere('idempotency_key', "boost_suspend_{$campId}");
            });
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', Carbon::parse($filters['from']));
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', Carbon::parse($filters['to']));
        }

        $boundedPerPage = min(100, max(1, $perPage));

        return $query->paginate($boundedPerPage);
    }

    /**
     * 5.4. Aperçu du montant théorique restituable avant suspension.
     * Ne modifie ni la campagne ni le portefeuille.
     *
     * @return array<string, mixed>
     */
    public function previewSuspension(Campaign $campaign): array
    {
        $budget = (float) $campaign->budget_amount;
        $spent = (float) $campaign->spent_amount;
        $unspent = max(0.0, $budget - $spent);

        $alreadyRefunded = CreditTransaction::where('type', CreditTransactionType::CampaignRefund)
            ->where(function (Builder $q) use ($campaign) {
                $q->where('reference_id', (string) $campaign->id)
                    ->orWhere('reference', (string) $campaign->id)
                    ->orWhere('idempotency_key', "boost_suspend_{$campaign->id}");
            })
            ->exists();

        $restitutable = $alreadyRefunded ? 0.0 : $unspent;

        return [
            'campaign_id' => (string) $campaign->id,
            'status' => $campaign->status->value,
            'budget_amount' => $budget,
            'spent_amount' => $spent,
            'unspent_amount' => $unspent,
            'currency' => $campaign->budget_currency ?: 'XAF',
            'already_refunded' => $alreadyRefunded,
            'restitutable_amount' => $restitutable,
            'unit' => 'Crédits BANGO (1 FCFA = 1 Crédit)',
            'can_suspend' => in_array($campaign->status, [CampaignStatus::Active, CampaignStatus::Paused], true),
        ];
    }

    /**
     * 5.5. Suspendre une campagne et créditer automatiquement l'annonceur du montant non consommé.
     * Transaction atomique sous verrou pessimiste avec idempotence stricte.
     *
     * @return array<string, mixed>
     */
    public function suspendCampaignWithRestitution(Campaign $campaign, User $operator, string $reason): array
    {
        return DB::transaction(function () use ($campaign, $operator, $reason) {
            /** @var Campaign $lockedCampaign */
            $lockedCampaign = Campaign::where('id', $campaign->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedCampaign->status, [CampaignStatus::Active, CampaignStatus::Paused], true)) {
                throw new DomainException(
                    sprintf('Seule une campagne active ou en pause peut être suspendue (statut actuel : %s).', $lockedCampaign->status->label())
                );
            }

            // Recalcul du montant côté serveur au moment précis de l'exécution
            $budget = (float) $lockedCampaign->budget_amount;
            $spent = (float) $lockedCampaign->spent_amount;
            $unspent = max(0.0, $budget - $spent);

            $idempotencyKey = "boost_suspend_{$lockedCampaign->id}";

            // Vérifier si une restitution a déjà été créditée
            $existingRefundTx = CreditTransaction::where('type', CreditTransactionType::CampaignRefund)
                ->where(function (Builder $q) use ($lockedCampaign, $idempotencyKey) {
                    $q->where('reference_id', (string) $lockedCampaign->id)
                        ->orWhere('reference', (string) $lockedCampaign->id)
                        ->orWhere('idempotency_key', $idempotencyKey);
                })
                ->first();

            $restitutedAmount = 0.0;
            $creditTx = null;

            if ($existingRefundTx === null && $unspent > 0) {
                $restitutedAmount = $unspent;
                $user = $lockedCampaign->user ?? User::findOrFail($lockedCampaign->user_id);
                $creditTx = $this->creditWalletService->credit(
                    account: $user,
                    amount: $unspent,
                    type: CreditTransactionType::CampaignRefund,
                    description: "Restitution budgétaire suite à la suspension de la campagne {$lockedCampaign->id} : {$reason}",
                    idempotencyKey: $idempotencyKey,
                    reference: $lockedCampaign,
                    metadata: [
                        'campaign_id' => (string) $lockedCampaign->id,
                        'operator_id' => $operator->id,
                        'operator_name' => $operator->name,
                        'reason' => trim($reason),
                        'initial_budget' => $budget,
                        'confirmed_spent' => $spent,
                        'restituted_credits' => $unspent,
                    ],
                    source: 'boost_restitution',
                    refCode: "RESTIT-{$lockedCampaign->id}"
                );
            } elseif ($existingRefundTx !== null) {
                $creditTx = $existingRefundTx;
                $restitutedAmount = (float) $existingRefundTx->amount;
            }

            // Transition de statut vers Suspended
            $lockedCampaign->status = CampaignStatus::Suspended;
            $lockedCampaign->stopped_reason = trim($reason);

            $meta = $lockedCampaign->metadata ?? [];
            $meta['suspension'] = [
                'operator_id' => $operator->id,
                'operator_name' => $operator->name,
                'reason' => trim($reason),
                'suspended_at' => now()->toIso8601String(),
                'restituted_credits' => $restitutedAmount,
                'credit_transaction_id' => $creditTx?->id,
            ];
            $lockedCampaign->metadata = $meta;
            $lockedCampaign->save();

            // Notification annonceur
            $lockedCampaign->user?->notify(new CampaignSuspendedNotification($lockedCampaign, $reason));

            return [
                'campaign_id' => (string) $lockedCampaign->id,
                'status' => $lockedCampaign->status->value,
                'status_label' => $lockedCampaign->status->label(),
                'stopped_reason' => $lockedCampaign->stopped_reason,
                'budget_amount' => $budget,
                'spent_amount' => $spent,
                'restituted_amount' => $restitutedAmount,
                'credit_transaction_reference' => $creditTx?->reference,
                'suspended_at' => now()->toIso8601String(),
                'operator' => [
                    'id' => $operator->id,
                    'name' => $operator->name,
                ],
            ];
        });
    }

    /**
     * 5.6. Envoi d'un message explicatif à l'annonceur dans sa langue.
     */
    public function notifyAdvertiser(Campaign $campaign, User $operator, string $message, ?string $language = null): array
    {
        $advertiser = $campaign->user;
        if (! $advertiser) {
            throw new DomainException('Cette campagne ne possède pas d\'annonceur rattaché.');
        }

        $preferredLang = $language ?: ($advertiser->preferred_locale ?? 'fr');

        // Modèle de message ou template dans la langue cible
        $content = trim($message);

        // Journalisation de la notification dans les métadonnées de la campagne
        $meta = $campaign->metadata ?? [];
        $meta['admin_notifications'][] = [
            'operator_id' => $operator->id,
            'operator_name' => $operator->name,
            'language' => $preferredLang,
            'message' => $content,
            'sent_at' => now()->toIso8601String(),
        ];
        $campaign->metadata = $meta;
        $campaign->save();

        return [
            'campaign_id' => (string) $campaign->id,
            'recipient_id' => $advertiser->id,
            'recipient_name' => $advertiser->name,
            'language' => $preferredLang,
            'message' => $content,
            'sent_at' => now()->toIso8601String(),
            'status' => 'delivered',
        ];
    }

    /**
     * 5.7. Export CSV sécurisé des campagnes avec protection contre l'injection de formules.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportCampaigns(array $filters, User $operator): StreamedResponse
    {
        $query = Campaign::with(['user', 'page'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', Carbon::parse($filters['from']));
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', Carbon::parse($filters['to']));
        }

        $filename = 'export_campagnes_boost_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $output = fopen('php://output', 'w');

            // BOM UTF-8
            fwrite($output, "\xEF\xBB\xBF");

            // En-têtes
            fputcsv($output, [
                'ID Campagne',
                'Annonceur',
                'Téléphone',
                'Email',
                'Page Pro',
                'Type Contenu',
                'ID Contenu',
                'Objectif',
                'Budget Initial',
                'Dépenses Réelles',
                'Budget Restant',
                'Devise',
                'Statut',
                'Motif Arrêt',
                'Date Début',
                'Date Fin',
                'Impressions',
                'Clics',
                'Portée',
                'Date Création',
            ], ';');

            $query->chunk(250, function ($campaigns) use ($output) {
                foreach ($campaigns as $c) {
                    $budget = (float) $c->budget_amount;
                    $spent = (float) $c->spent_amount;
                    $remaining = max(0.0, $budget - $spent);

                    $row = [
                        $this->sanitizeCsvField((string) $c->id),
                        $this->sanitizeCsvField($c->user?->name ?? 'N/A'),
                        $this->sanitizeCsvField($c->user?->phone ?? 'N/A'),
                        $this->sanitizeCsvField($c->user?->email ?? 'N/A'),
                        $this->sanitizeCsvField($c->page?->name ?? 'Personnel'),
                        $this->sanitizeCsvField(class_basename($c->boostable_type)),
                        $this->sanitizeCsvField((string) $c->boostable_id),
                        $this->sanitizeCsvField($c->objective?->value ?? (string) $c->objective),
                        number_format($budget, 2, '.', ''),
                        number_format($spent, 2, '.', ''),
                        number_format($remaining, 2, '.', ''),
                        $this->sanitizeCsvField($c->budget_currency ?: 'XAF'),
                        $this->sanitizeCsvField($c->status?->value ?? (string) $c->status),
                        $this->sanitizeCsvField((string) $c->stopped_reason),
                        $c->start_at ? $c->start_at->format('Y-m-d H:i') : '',
                        $c->end_at ? $c->end_at->format('Y-m-d H:i') : '',
                        $c->impressions_count,
                        $c->clicks_count,
                        $c->reach_count,
                        $c->created_at ? $c->created_at->format('Y-m-d H:i') : '',
                    ];

                    fputcsv($output, $row, ';');
                }
            });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * Neutralise les risques d'injection de formules dans les tableurs (=, +, -, @, tab, cr).
     */
    protected function sanitizeCsvField(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $trimmed = trim($value);

        if (in_array(substr($trimmed, 0, 1), ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$trimmed;
        }

        return $trimmed;
    }
}
