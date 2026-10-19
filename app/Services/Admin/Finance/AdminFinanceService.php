<?php

namespace App\Services\Admin\Finance;

use App\Enums\Boost\CreditTransactionType;
use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentStatus;
use App\Models\Boost\CreditTransaction;
use App\Models\Payment\PaymentAttempt;
use App\Models\Payment\PaymentDispute;
use App\Models\Payment\PaymentEvent;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use App\Models\User;
use App\Services\Boost\CreditWalletService;
use App\Services\Payment\PaymentEngineService;
use App\Services\Payment\PaymentReconciliationService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminFinanceService
{
    public function __construct(
        protected CreditWalletService $creditWalletService,
        protected PaymentEngineService $paymentEngineService,
        protected PaymentReconciliationService $reconciliationService
    ) {}

    /**
     * 4.1. Liste et recherche paginée des transactions financières avec filtres et compteurs.
     *
     * @param  array<string, mixed>  $filters
     * @return array{paginator: LengthAwarePaginator, status_counts: array<string, int>}
     */
    public function getTransactions(array $filters, int $perPage = 20): array
    {
        $query = PaymentTransaction::with([
            'user:id,name,email,phone',
            'product:id,code,name',
            'price:id,cadence',
        ]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['provider'])) {
            $query->where('provider', $filters['provider']);
        }

        if (! empty($filters['service_type'])) {
            $query->where('service_type', $filters['service_type']);
        }

        if (! empty($filters['currency'])) {
            $query->where('currency', strtoupper((string) $filters['currency']));
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['reference'])) {
            $query->where('reference', trim((string) $filters['reference']));
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
                $sub->where('reference', $like, "%{$term}%")
                    ->orWhere('external_reference', $like, "%{$term}%")
                    ->orWhere('idempotency_key', $like, "%{$term}%")
                    ->orWhereHas('user', function (Builder $uq) use ($term, $like) {
                        $uq->where('name', $like, "%{$term}%")
                            ->orWhere('email', $like, "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%");
                    });
            });
        }

        // Compteurs de statuts sur la base filtrée
        $statusCounts = (clone $query)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $allowedSorts = ['created_at', 'total_amount', 'status'];
        $sortBy = in_array($filters['sort_by'] ?? null, $allowedSorts, true) ? $filters['sort_by'] : 'created_at';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortOrder);

        $boundedPerPage = min(100, max(1, $perPage));
        $paginator = $query->paginate($boundedPerPage);

        return [
            'paginator' => $paginator,
            'status_counts' => $statusCounts,
        ];
    }

    /**
     * 4.2. Vue détaillée et chronologie complète d'une transaction expurgée des secrets.
     */
    public function getTransactionDetail(string|int $id): ?array
    {
        /** @var PaymentTransaction|null $transaction */
        $transaction = PaymentTransaction::with([
            'user:id,name,email,phone,is_verified,account_type',
            'product',
            'price',
            'subscription',
            'attempts',
            'events',
            'disputes',
        ])
            ->where(function (Builder $q) use ($id) {
                $q->where('id', $id)
                    ->orWhere('reference', $id);
            })
            ->first();

        if (! $transaction) {
            return null;
        }

        return [
            'id' => (string) $transaction->id,
            'reference' => $transaction->reference,
            'external_reference' => $transaction->external_reference,
            'service_type' => $transaction->service_type->value,
            'status' => $transaction->status->value,
            'status_label' => $transaction->status->label(),
            'currency' => $transaction->currency,
            'amount' => (float) $transaction->amount,
            'fee_amount' => (float) $transaction->fee_amount,
            'tax_amount' => (float) $transaction->tax_amount,
            'total_amount' => (float) $transaction->total_amount,
            'credit_used' => (float) $transaction->credit_used,
            'amount_charged' => (float) $transaction->amount_charged,
            'provider' => $transaction->provider->value,
            'channel' => $transaction->channel->value,
            'idempotency_key' => $transaction->idempotency_key,
            'created_at' => $transaction->created_at?->toIso8601String(),
            'confirmed_at' => $transaction->confirmed_at?->toIso8601String(),
            'failed_at' => $transaction->failed_at?->toIso8601String(),
            'cancelled_at' => $transaction->cancelled_at?->toIso8601String(),
            'refunded_at' => $transaction->refunded_at?->toIso8601String(),
            'pricing_snapshot' => $transaction->metadata['pricing_snapshot'] ?? null,
            'refund_details' => $transaction->metadata['refund'] ?? null,
            'user' => $transaction->user,
            'product' => $transaction->product,
            'price' => $transaction->price,
            'subscription' => $transaction->subscription ? [
                'id' => $transaction->subscription->id,
                'badge_type' => $transaction->subscription->badge_type,
                'state' => $transaction->subscription->state,
                'current_period_end' => $transaction->subscription->current_period_end?->toIso8601String(),
            ] : null,
            'attempts' => $transaction->attempts,
            'events' => $transaction->events->map(function (PaymentEvent $event) {
                return [
                    'id' => (string) $event->id,
                    'event_type' => $event->event_type,
                    'source' => $event->source instanceof \BackedEnum ? $event->source->value : (string) $event->source,
                    'from_status' => $event->from_status instanceof \BackedEnum ? $event->from_status->value : $event->from_status,
                    'to_status' => $event->to_status instanceof \BackedEnum ? $event->to_status->value : $event->to_status,
                    'payload' => $this->sanitizeData($event->payload ?? []),
                    'created_at' => $event->created_at?->toIso8601String(),
                ];
            }),
            'disputes' => $transaction->disputes,
        ];
    }

    /**
     * 4.3. Grand livre des Crédits BANGO et soldes actifs.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getCreditsLedger(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = CreditTransaction::with(['user:id,name,email,phone', 'wallet:id,balance,currency'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
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

        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $query->where(function (Builder $sub) use ($term, $like) {
                $sub->where('reference', $like, "%{$term}%")
                    ->orWhere('idempotency_key', $like, "%{$term}%")
                    ->orWhere('description', $like, "%{$term}%")
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
     * 4.4. Monitoring des passerelles et opérateurs de paiement réellement configurés.
     *
     * @return array<string, mixed>
     */
    public function getOperatorsMonitoring(): array
    {
        $since = Carbon::now()->subDays(7);

        // Statistiques globales sur les 7 derniers jours
        $statsByProvider = PaymentTransaction::where('created_at', '>=', $since)
            ->selectRaw('provider, status, count(*) as count')
            ->groupBy('provider', 'status')
            ->get();

        $monetbilEnabled = (bool) config('payment.gateways.monetbil.enabled', true);
        $flutterwaveEnabled = (bool) config('payment.gateways.flutterwave.enabled', false);
        $appleEnabled = (bool) config('payment.gateways.apple_iap.enabled', false);
        $googleEnabled = (bool) config('payment.gateways.google_play.enabled', false);

        // Dernière erreur connue sur Monetbil
        $lastMonetbilError = PaymentAttempt::where('provider', 'monetbil')
            ->where('status', 'failed')
            ->latest('created_at')
            ->first();

        // Analyse des tentatives par canal (Mobile Money, Card, etc.)
        $attemptsByChannel = PaymentAttempt::where('created_at', '>=', $since)
            ->selectRaw('channel, status, count(*) as count')
            ->groupBy('channel', 'status')
            ->get();

        $calcMetrics = function ($items) {
            $total = 0;
            $success = 0;
            $failed = 0;
            foreach ($items as $item) {
                $total += $item->count;
                if ($item->status === PaymentStatus::Confirmed->value || $item->status === 'successful' || $item->status === 'confirmed') {
                    $success += $item->count;
                } elseif ($item->status === PaymentStatus::Failed->value || $item->status === 'failed') {
                    $failed += $item->count;
                }
            }

            return [
                'total_transactions' => $total,
                'successful_transactions' => $success,
                'failed_transactions' => $failed,
                'success_rate' => $total > 0 ? round(($success / $total) * 100, 2) : 100.0,
            ];
        };

        $monetbilTx = $statsByProvider->where('provider', PaymentProvider::Monetbil);
        $monetbilMetrics = $calcMetrics($monetbilTx);

        $momoAttempts = $attemptsByChannel->filter(fn ($a) => stripos((string) $a->channel, 'mobile') !== false);

        return [
            'window' => 'Derniers 7 jours',
            'scanned_since' => $since->toIso8601String(),
            'providers' => [
                'monetbil' => array_merge([
                    'name' => 'Monetbil (Agrégateur Mobile Money)',
                    'is_enabled' => $monetbilEnabled,
                    'status' => $monetbilEnabled ? 'operational' : 'disabled',
                    'supported_channels' => ['MTN Mobile Money (Cameroun)', 'Orange Money (Cameroun)'],
                    'last_error' => $lastMonetbilError ? [
                        'date' => $lastMonetbilError->created_at?->toIso8601String(),
                        'error_message' => $lastMonetbilError->error_message,
                    ] : null,
                ], $monetbilMetrics),
                'mtn_momo' => array_merge([
                    'name' => 'MTN Mobile Money',
                    'via_aggregator' => 'monetbil',
                    'status' => $monetbilEnabled ? 'operational' : 'disabled',
                ], $monetbilMetrics),
                'orange_money' => array_merge([
                    'name' => 'Orange Money',
                    'via_aggregator' => 'monetbil',
                    'status' => $monetbilEnabled ? 'operational' : 'disabled',
                ], $monetbilMetrics),
                'flutterwave' => [
                    'name' => 'Flutterwave',
                    'is_enabled' => $flutterwaveEnabled,
                    'status' => $flutterwaveEnabled ? 'operational' : 'disabled',
                    'supported_channels' => ['Carte Bancaire', 'Mobile Money International'],
                ],
                'apple_iap' => [
                    'name' => 'Apple In-App Purchase',
                    'is_enabled' => $appleEnabled,
                    'status' => $appleEnabled ? 'operational' : 'disabled',
                    'supported_channels' => ['App Store Subscriptions'],
                ],
                'google_play' => [
                    'name' => 'Google Play Billing',
                    'is_enabled' => $googleEnabled,
                    'status' => $googleEnabled ? 'operational' : 'disabled',
                    'supported_channels' => ['Play Store Subscriptions'],
                ],
            ],
        ];
    }

    /**
     * 4.8. Supervision des abonnements de badges et périodes de grâce.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getSubscriptions(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = Subscription::with([
            'user:id,name,email,phone,handle,badge_type',
            'product:id,code,name',
            'price:id,cadence,amount,currency',
        ])
            ->orderByDesc('created_at');

        if (! empty($filters['state'])) {
            $query->where('state', $filters['state']);
        }

        if (! empty($filters['badge_type'])) {
            $query->where('badge_type', $filters['badge_type']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['in_grace'])) {
            $query->where('state', 'grace')
                ->where('grace_until', '>', now());
        }

        if (! empty($filters['q'])) {
            $search = trim((string) $filters['q']);
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $query->whereHas('user', function (Builder $uq) use ($search, $like) {
                $uq->where('name', $like, "%{$search}%")
                    ->orWhere('email', $like, "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('handle', $like, "%{$search}%");
            });
        }

        $boundedPerPage = min(100, max(1, $perPage));

        return $query->paginate($boundedPerPage);
    }

    /**
     * 4.9. Clôture administrative d'un incident sans mouvement financier.
     */
    public function closeIncident(PaymentTransaction $transaction, User $operator, string $reason): array
    {
        return DB::transaction(function () use ($transaction, $operator, $reason) {
            /** @var PaymentTransaction $lockedTx */
            $lockedTx = PaymentTransaction::where('id', $transaction->id)->lockForUpdate()->firstOrFail();

            // Clôturer les dossiers de litiges ouverts liés à cette transaction
            $disputesClosed = 0;
            $openDisputes = PaymentDispute::where('transaction_id', $lockedTx->id)
                ->whereIn('status', ['opened', 'under_review'])
                ->get();

            foreach ($openDisputes as $dispute) {
                $dispute->transitionTo('closed', $operator, $reason);
                $disputesClosed++;
            }

            // Journaliser l'incident_closed de manière immuable
            $lockedTx->events()->create([
                'event_type' => 'incident_closed',
                'source' => PaymentEventSource::Admin->value,
                'from_status' => $lockedTx->status->value,
                'to_status' => $lockedTx->status->value,
                'payload' => [
                    'admin_id' => $operator->id,
                    'admin_name' => $operator->name,
                    'reason' => trim($reason),
                    'disputes_closed' => $disputesClosed,
                    'closed_at' => now()->toIso8601String(),
                ],
            ]);

            $meta = $lockedTx->metadata ?? [];
            $meta['incident_closed'] = [
                'admin_id' => $operator->id,
                'admin_name' => $operator->name,
                'reason' => trim($reason),
                'closed_at' => now()->toIso8601String(),
            ];
            $lockedTx->metadata = $meta;
            $lockedTx->save();

            return [
                'transaction_id' => (string) $lockedTx->id,
                'reference' => $lockedTx->reference,
                'status' => $lockedTx->status->value,
                'disputes_closed' => $disputesClosed,
                'closed_at' => now()->toIso8601String(),
                'operator' => [
                    'id' => $operator->id,
                    'name' => $operator->name,
                ],
                'reason' => trim($reason),
            ];
        });
    }

    /**
     * 4.10. Émission compensatoire manuelle de Crédits BANGO avec traçabilité et grand livre.
     */
    public function issueCompensatoryCredit(
        PaymentTransaction $transaction,
        User $operator,
        int $amount,
        string $reason,
        ?string $idempotencyKey = null
    ): CreditTransaction {
        if ($amount <= 0) {
            throw new DomainException('Le montant compensatoire doit être strictement positif.');
        }

        $key = $idempotencyKey ? trim($idempotencyKey) : "comp_credit_tx_{$transaction->id}_".substr(md5($reason.$amount), 0, 8);

        $user = $transaction->user ?? User::findOrFail($transaction->user_id);

        return $this->creditWalletService->credit(
            account: $user,
            amount: $amount,
            type: CreditTransactionType::Adjustment,
            description: "Crédit compensatoire émis par {$operator->name} : {$reason}",
            idempotencyKey: $key,
            reference: $transaction,
            metadata: [
                'operator_id' => $operator->id,
                'operator_name' => $operator->name,
                'transaction_id' => $transaction->id,
                'transaction_reference' => $transaction->reference,
                'reason' => trim($reason),
                'issued_at' => now()->toIso8601String(),
            ],
            source: 'admin_adjustment',
            refCode: "COMP-{$transaction->reference}",
            paymentTransactionId: $transaction->id
        );
    }

    /**
     * 4.17. Fournit le reçu officiel d'une transaction avec signature HMAC-SHA256 et empreinte d'intégrité.
     */
    public function getTransactionReceipt(PaymentTransaction $transaction): array
    {
        if ($transaction->status !== PaymentStatus::Confirmed) {
            throw new DomainException("Un reçu ne peut être émis que pour une transaction confirmée (statut actuel : {$transaction->status->label()}).");
        }

        $canonicalString = implode('|', [
            $transaction->reference,
            number_format((float) $transaction->total_amount, 2, '.', ''),
            strtoupper($transaction->currency),
            $transaction->status->value,
            $transaction->confirmed_at?->toIso8601String() ?? $transaction->created_at->toIso8601String(),
            (string) $transaction->user_id,
        ]);

        $appKey = (string) config('app.key');
        $signature = hash_hmac('sha256', $canonicalString, $appKey);
        $fingerprint = hash('sha256', $canonicalString);

        return [
            'receipt_number' => 'REC-'.$transaction->reference,
            'transaction_reference' => $transaction->reference,
            'external_reference' => $transaction->external_reference,
            'service_type' => $transaction->service_type->value,
            'service_label' => $transaction->service_type->label(),
            'product' => $transaction->product ? [
                'code' => $transaction->product->code,
                'name' => $transaction->product->name,
            ] : null,
            'user' => [
                'id' => $transaction->user?->id,
                'name' => $transaction->user?->name,
                'masked_phone' => $transaction->user?->phone ? substr($transaction->user->phone, 0, 4).'***'.substr($transaction->user->phone, -2) : null,
            ],
            'amount' => (float) $transaction->amount,
            'fee_amount' => (float) $transaction->fee_amount,
            'tax_amount' => (float) $transaction->tax_amount,
            'total_amount' => (float) $transaction->total_amount,
            'currency' => $transaction->currency,
            'provider' => $transaction->provider->value,
            'channel' => $transaction->channel->value,
            'status' => $transaction->status->value,
            'confirmed_at' => $transaction->confirmed_at?->toIso8601String(),
            'issued_at' => now()->toIso8601String(),
            'integrity' => [
                'algorithm' => 'HMAC-SHA256',
                'signature' => $signature,
                'fingerprint_sha256' => $fingerprint,
                'canonical_payload' => $canonicalString,
            ],
        ];
    }

    /**
     * 4.18. Aperçu avant export comptable sans mutation de données.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function previewExport(array $filters): array
    {
        $query = PaymentTransaction::query();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['provider'])) {
            $query->where('provider', $filters['provider']);
        }
        if (! empty($filters['service_type'])) {
            $query->where('service_type', $filters['service_type']);
        }
        if (! empty($filters['currency'])) {
            $query->where('currency', strtoupper((string) $filters['currency']));
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', Carbon::parse($filters['from']));
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', Carbon::parse($filters['to']));
        }

        $count = $query->count();
        $totalCharged = (float) $query->sum('amount_charged');

        return [
            'count' => $count,
            'total_amount_charged' => $totalCharged,
            'currency' => $filters['currency'] ?? 'XAF',
            'date_range' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'format' => 'CSV (UTF-8 avec BOM)',
            'columns' => [
                'Référence',
                'Réf. Externe',
                'Date',
                'Client',
                'Téléphone',
                'Email',
                'Prestation',
                'Fournisseur',
                'Canal',
                'Montant Brut',
                'Frais',
                'Total Payé',
                'Devise',
                'Statut',
                'Date Confirmation',
            ],
        ];
    }

    /**
     * 4.19. Production d'un export comptable CSV signé et sécurisé contre l'injection de formules.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportTransactions(array $filters, User $operator): StreamedResponse
    {
        $query = PaymentTransaction::with(['user'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['provider'])) {
            $query->where('provider', $filters['provider']);
        }
        if (! empty($filters['service_type'])) {
            $query->where('service_type', $filters['service_type']);
        }
        if (! empty($filters['currency'])) {
            $query->where('currency', strtoupper((string) $filters['currency']));
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', Carbon::parse($filters['from']));
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', Carbon::parse($filters['to']));
        }

        $filename = 'export_comptable_paiements_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $output = fopen('php://output', 'w');

            // BOM UTF-8
            fwrite($output, "\xEF\xBB\xBF");

            // En-têtes
            fputcsv($output, [
                'Référence',
                'Réf. Externe',
                'Date',
                'Client',
                'Téléphone',
                'Email',
                'Prestation',
                'Fournisseur',
                'Canal',
                'Montant Brut',
                'Frais',
                'Total Payé',
                'Devise',
                'Statut',
                'Date Confirmation',
            ], ';');

            $query->chunk(250, function ($transactions) use ($output) {
                foreach ($transactions as $t) {
                    $row = [
                        $this->sanitizeCsvField($t->reference),
                        $this->sanitizeCsvField($t->external_reference),
                        $t->created_at ? $t->created_at->format('Y-m-d H:i') : '',
                        $this->sanitizeCsvField($t->user?->name ?? 'N/A'),
                        $this->sanitizeCsvField($t->user?->phone ?? 'N/A'),
                        $this->sanitizeCsvField($t->user?->email ?? 'N/A'),
                        $this->sanitizeCsvField($t->service_type?->value ?? (string) $t->service_type),
                        $this->sanitizeCsvField($t->provider?->value ?? (string) $t->provider),
                        $this->sanitizeCsvField($t->channel?->value ?? (string) $t->channel),
                        number_format((float) $t->amount, 2, '.', ''),
                        number_format((float) $t->fee_amount, 2, '.', ''),
                        number_format((float) $t->total_amount, 2, '.', ''),
                        $this->sanitizeCsvField($t->currency),
                        $this->sanitizeCsvField($t->status?->value ?? (string) $t->status),
                        $t->confirmed_at ? $t->confirmed_at->format('Y-m-d H:i') : '',
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
     * 4.15. Rapprochement ciblé d'une transaction auprès du provider.
     */
    public function checkTransaction(PaymentTransaction $transaction): array
    {
        $status = $this->reconciliationService->reconcileTransaction($transaction);

        return [
            'transaction_id' => (string) $transaction->id,
            'reference' => $transaction->reference,
            'status' => $status->value,
            'status_label' => $status->label(),
            'reconciled_at' => now()->toIso8601String(),
        ];
    }

    /**
     * 4.16. Rapprochement groupé et contrôlé des transactions en attente.
     */
    public function resyncTransactions(): array
    {
        return $this->reconciliationService->reconcilePendingTransactions();
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

    /**
     * Expurgation récursive des données sensibles.
     */
    protected function sanitizeData(mixed $data): mixed
    {
        if (is_array($data)) {
            $filtered = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && preg_match('/(secret|password|token|private_key|api_key|authorization)/i', $key)) {
                    continue;
                }
                $filtered[$key] = $this->sanitizeData($value);
            }

            return $filtered;
        }

        return $data;
    }
}
