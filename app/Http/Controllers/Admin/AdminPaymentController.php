<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Payment\Concerns\FormatsPaymentResponses;
use App\Models\Payment\PaymentDispute;
use App\Models\Payment\PaymentTransaction;
use App\Services\Payment\PaymentEngineService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminPaymentController extends Controller
{
    use FormatsPaymentResponses;

    public function __construct(
        protected PaymentEngineService $paymentEngineService
    ) {}

    /**
     * GET /api/admin/payments/transactions
     * Liste et recherche paginée des transactions financières.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PaymentTransaction::with([
            'user:id,name,display_name,handle,avatar_url,city,badge,email,phone',
            'product:id,code,name',
            'price:id,cadence',
        ]);

        // 1. Filtre par fournisseur / passerelle
        if ($request->filled('provider')) {
            $query->where('provider', $request->input('provider'));
        }

        // 2. Filtre par opérateur mobile money
        if ($request->filled('operator')) {
            $op = strtolower(trim((string) $request->input('operator')));
            $query->where(function ($sub) use ($op) {
                $sub->where('provider', $op)
                    ->orWhere('channel', 'like', "%{$op}%")
                    ->orWhereHas('attempts', function ($aq) use ($op) {
                        $aq->where('error_message', 'like', "%{$op}%")
                            ->orWhere('metadata', 'like', "%{$op}%");
                    });
            });
        }

        // 3. Filtre par utilisateur
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        // 4. Filtre par type de prestation (simple ou multiple)
        if ($request->filled('service_type')) {
            $serviceTypes = is_array($request->input('service_type'))
                ? $request->input('service_type')
                : explode(',', (string) $request->input('service_type'));
            $query->whereIn('service_type', array_map('trim', $serviceTypes));
        }

        // 5. Filtre par canal
        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }

        // 6. Filtre par devise / pays
        if ($request->filled('currency')) {
            $currencies = is_array($request->input('currency'))
                ? $request->input('currency')
                : explode(',', (string) $request->input('currency'));
            $query->whereIn('currency', array_map(fn ($c) => strtoupper(trim((string) $c)), $currencies));
        }
        if ($request->filled('country')) {
            $country = strtoupper(trim((string) $request->input('country')));
            $query->whereHas('user', function ($uq) use ($country) {
                $uq->where('country_code', $country)
                    ->orWhereHas('country', fn ($cq) => $cq->where('code', $country)->orWhere('iso2', $country)->orWhere('iso3', $country));
            });
        }

        // 7. Filtre par motif de rejet
        if ($request->filled('failure_reason')) {
            $reason = trim((string) $request->input('failure_reason'));
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where('status', 'failed')
                ->where(function ($sub) use ($reason, $like) {
                    $sub->whereHas('attempts', function ($aq) use ($reason, $like) {
                        $aq->where('error_message', $like, "%{$reason}%")
                            ->orWhere('error_code', $like, "%{$reason}%");
                    });
                });
        }

        // 8. Filtre par référence exacte
        if ($request->filled('reference')) {
            $query->where('reference', trim($request->input('reference')));
        }

        // 9. Filtre par dates
        $from = $request->input('from') ?? $request->input('date_from') ?? $request->input('created_after');
        if (! empty($from)) {
            $query->where('created_at', '>=', Carbon::parse($from));
        }
        $to = $request->input('to') ?? $request->input('date_to') ?? $request->input('created_before');
        if (! empty($to)) {
            $toCarbon = Carbon::parse($to);
            if ($toCarbon->format('H:i:s') === '00:00:00' && ! str_contains((string) $to, 'T')) {
                $toCarbon = $toCarbon->endOfDay();
            }
            $query->where('created_at', '<=', $toCarbon);
        }

        // 10. Recherche textuelle ciblée multi-champs
        if ($request->filled('q')) {
            $term = trim($request->input('q'));
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $query->where(function ($sub) use ($term, $like) {
                $sub->where('reference', $like, "%{$term}%")
                    ->orWhere('external_reference', $like, "%{$term}%")
                    ->orWhere('idempotency_key', $like, "%{$term}%")
                    ->orWhereHas('user', function ($uq) use ($term, $like) {
                        $uq->where('name', $like, "%{$term}%")
                            ->orWhere('display_name', $like, "%{$term}%")
                            ->orWhere('handle', $like, "%{$term}%")
                            ->orWhere('email', $like, "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%");
                    });

                if (is_numeric($term) && strlen($term) >= 3) {
                    $sub->orWhereHas('user', function ($uq) use ($term) {
                        $uq->where('phone', 'like', "%{$term}");
                    });
                }
            });
        }

        // 11. Compteurs d'onglets calculés avant filtre de statut
        $baseQuery = (clone $query)->reorder();
        $statusCounts = $baseQuery
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
        $totalAll = array_sum($statusCounts);
        $counts = array_merge(['all' => $totalAll], $statusCounts);

        // 12. Filtre par statut (simple ou multiple)
        if ($request->filled('status')) {
            $statuses = is_array($request->input('status'))
                ? $request->input('status')
                : explode(',', (string) $request->input('status'));
            $query->whereIn('status', array_map('trim', $statuses));
        }

        // 13. Tri contrôlé (protection contre injections)
        $allowedSorts = ['created_at', 'total_amount', 'status'];
        $sortBy = in_array($request->input('sort_by'), $allowedSorts, true) ? $request->input('sort_by') : 'created_at';
        $sortOrder = strtolower((string) ($request->input('sort_order') ?? $request->input('dir') ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortOrder);

        // 14. Pagination serveur
        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'status_counts' => $statusCounts,
                'counts' => $counts,
            ],
        ]);
    }

    /**
     * GET /api/admin/payments/transactions/{id}
     * Vue détaillée et chronologie complète d'une transaction.
     */
    public function show(string $id): JsonResponse
    {
        /** @var PaymentTransaction|null $transaction */
        $transaction = PaymentTransaction::with([
            'user:id,name,display_name,handle,avatar_url,city,badge,email,phone,is_verified,account_type',
            'product',
            'price',
            'subscription',
            'attempts',
            'events',
            'disputes',
        ])
            ->where(function ($q) use ($id) {
                $q->where('id', $id)
                    ->orWhere('reference', $id);
            })
            ->first();

        if (! $transaction) {
            return $this->paymentError(
                code: 'TRANSACTION_NOT_FOUND',
                message: 'Transaction introuvable.',
                status: 404
            );
        }

        $detail = [
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
            'events' => $transaction->events,
            'disputes' => $transaction->disputes,
        ];

        return $this->paymentSuccess(
            data: $this->sanitizeData($detail),
            message: 'Détails de la transaction récupérés avec succès.'
        );
    }

    /**
     * GET /api/admin/payments/transactions/{id}/events
     * Historique immuable des événements financiers et techniques.
     */
    public function events(string $id, Request $request): JsonResponse
    {
        /** @var PaymentTransaction|null $transaction */
        $transaction = PaymentTransaction::where('id', $id)
            ->orWhere('reference', $id)
            ->first();

        if (! $transaction) {
            return $this->paymentError(
                code: 'TRANSACTION_NOT_FOUND',
                message: 'Transaction introuvable.',
                status: 404
            );
        }

        $events = $transaction->events()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($ev) {
                $payload = $ev->payload ?? [];
                // Expurgation des clés sensibles
                $sanitized = collect($payload)->except([
                    'service_secret', 'secret', 'password', 'token', 'private_key', 'api_key', 'authorization',
                ])->all();

                return [
                    'id' => (string) $ev->id,
                    'event_type' => $ev->event_type,
                    'source' => $ev->source instanceof \BackedEnum ? $ev->source->value : (string) $ev->source,
                    'from_status' => $ev->from_status instanceof \BackedEnum ? $ev->from_status->value : $ev->from_status,
                    'to_status' => $ev->to_status instanceof \BackedEnum ? $ev->to_status->value : $ev->to_status,
                    'correlation_id' => $payload['correlation_id'] ?? null,
                    'actor_id' => $payload['admin_id'] ?? $payload['user_id'] ?? null,
                    'payload' => $sanitized,
                    'created_at' => $ev->created_at?->toIso8601String(),
                ];
            });

        return $this->paymentSuccess(
            data: $events,
            message: 'Événements financiers récupérés avec succès.'
        );
    }

    /**
     * POST /api/admin/payments/transactions/{id}/refund
     * Traitement encadré d'un remboursement avec motif, preuve externe et traçabilité.
     */
    public function refund(Request $request, string $id): JsonResponse
    {
        $adminUser = $request->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'external_reference' => ['required', 'string', 'min:3', 'max:128'],
            'amount' => ['nullable', 'numeric', 'min:1'],
        ]);

        /** @var PaymentTransaction|null $transaction */
        $transaction = PaymentTransaction::where('id', $id)
            ->orWhere('reference', $id)
            ->first();

        if (! $transaction) {
            return $this->paymentError(
                code: 'TRANSACTION_NOT_FOUND',
                message: 'Transaction introuvable.',
                status: 404
            );
        }

        try {
            $updated = $this->paymentEngineService->refundTransaction(
                transaction: $transaction,
                adminUser: $adminUser,
                reason: $validated['reason'],
                amount: isset($validated['amount']) ? (float) $validated['amount'] : null,
                externalRefundReference: $validated['external_reference']
            );
        } catch (DomainException $e) {
            return $this->paymentError(
                code: 'REFUND_NOT_ALLOWED',
                message: $e->getMessage(),
                status: 422
            );
        }

        return $this->paymentSuccess(
            data: [
                'id' => (string) $updated->id,
                'reference' => $updated->reference,
                'status' => $updated->status->value,
                'status_label' => $updated->status->label(),
                'refunded_at' => $updated->refunded_at?->toIso8601String(),
                'refund_details' => $updated->metadata['refund'] ?? null,
            ],
            message: 'Remboursement enregistré et tracé avec succès.'
        );
    }

    /**
     * GET /api/admin/payments/disputes
     * Recherche et liste des litiges.
     */
    public function disputes(Request $request): JsonResponse
    {
        $query = PaymentDispute::with([
            'user:id,name,display_name,handle,avatar_url,city,badge,email,phone',
            'transaction:id,reference,total_amount,status,currency',
            'assignee:id,name',
        ])->latest('created_at');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('transaction_id')) {
            $query->where('transaction_id', (int) $request->input('transaction_id'));
        }

        if ($request->filled('reference')) {
            $query->where('reference', trim($request->input('reference')));
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $paginator = $query->paginate($perPage);

        $items = $paginator->getCollection();
        $secondIds = $items->map(fn ($d) => $d->metadata['second_transaction_id'] ?? null)->filter()->unique()->values();
        $secondTxs = $secondIds->isNotEmpty()
            ? PaymentTransaction::whereIn('id', $secondIds)->select(['id', 'reference', 'total_amount', 'status', 'currency'])->get()->keyBy('id')
            : collect();

        $enrichedItems = $items->map(function ($d) use ($secondTxs) {
            $arr = $d->toArray();
            $secondId = $d->metadata['second_transaction_id'] ?? null;
            $arr['second_transaction'] = $secondId && isset($secondTxs[$secondId])
                ? $secondTxs[$secondId]
                : null;

            return $arr;
        });

        return response()->json([
            'success' => true,
            'data' => $enrichedItems,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/admin/payments/double-charge-claims
     * Recherche dédiée des réclamations de doubles débits.
     */
    public function doubleChargeClaims(Request $request): JsonResponse
    {
        $request->merge(['type' => 'double_charge']);

        return $this->disputes($request);
    }

    /**
     * POST /api/admin/payments/disputes
     * Ouvre un nouveau dossier de litige ou de contestation.
     */
    public function createDispute(Request $request): JsonResponse
    {
        if ($request->filled('transaction_id') && ! $request->filled('user_id')) {
            $tx = PaymentTransaction::find($request->input('transaction_id'));
            if ($tx) {
                $request->merge(['user_id' => $tx->user_id]);
            }
        }
        if ($request->filled('duplicate_transaction_id') && ! $request->filled('second_transaction_id')) {
            $request->merge(['second_transaction_id' => $request->input('duplicate_transaction_id')]);
        }
        if ($request->filled('amount_contested') && ! $request->filled('disputed_amount')) {
            $request->merge(['disputed_amount' => $request->input('amount_contested')]);
        }

        $validated = $request->validate([
            'transaction_id' => ['nullable', 'integer', 'exists:payment_transactions,id'],
            'second_transaction_id' => ['nullable', 'integer', 'exists:payment_transactions,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['nullable', 'string', 'in:dispute,double_charge'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'disputed_amount' => ['nullable', 'numeric', 'min:0'],
            'claimant_phone' => ['nullable', 'string', 'max:32'],
            'operator_reference' => ['nullable', 'string', 'max:128'],
        ]);

        $metadata = [];
        if (! empty($validated['second_transaction_id'])) {
            $metadata['second_transaction_id'] = (int) $validated['second_transaction_id'];
        }

        $dispute = PaymentDispute::create([
            'transaction_id' => $validated['transaction_id'] ?? null,
            'user_id' => $validated['user_id'],
            'type' => $validated['type'] ?? 'dispute',
            'status' => 'opened',
            'reason' => $validated['reason'],
            'disputed_amount' => $validated['disputed_amount'] ?? 0.00,
            'claimant_phone' => $validated['claimant_phone'] ?? null,
            'operator_reference' => $validated['operator_reference'] ?? null,
            'assigned_to' => $request->user()->id,
            'metadata' => $metadata ?: null,
        ]);

        $dispute->addNote($request->user(), 'Dossier ouvert par l\'administrateur.');

        return $this->paymentSuccess(
            data: $dispute,
            message: 'Dossier de litige ouvert avec succès.',
            status: 201
        );
    }

    /**
     * POST /api/admin/payments/double-charge-claims
     * Enregistre une réclamation spécifique de double débit.
     */
    public function createDoubleChargeClaim(Request $request): JsonResponse
    {
        $request->merge(['type' => 'double_charge']);

        return $this->createDispute($request);
    }

    /**
     * GET /api/admin/payments/disputes/{id}
     * Détail d'un dossier de litige.
     */
    public function showDispute(string $id): JsonResponse
    {
        $dispute = PaymentDispute::with([
            'user:id,name,display_name,handle,avatar_url,city,badge,email,phone',
            'transaction',
            'assignee:id,name,email',
        ])
            ->where('id', $id)
            ->orWhere('reference', $id)
            ->first();

        if (! $dispute) {
            return $this->paymentError(
                code: 'DISPUTE_NOT_FOUND',
                message: 'Dossier de litige introuvable.',
                status: 404
            );
        }

        $data = $dispute->toArray();
        $secondId = $dispute->metadata['second_transaction_id'] ?? null;
        $data['second_transaction'] = $secondId
            ? PaymentTransaction::find($secondId)
            : null;

        return $this->paymentSuccess(
            data: $data,
            message: 'Dossier de litige récupéré avec succès.'
        );
    }

    /**
     * POST /api/admin/payments/disputes/{id}/status
     * Fait évoluer le statut d'un litige avec décision obligatoire.
     */
    public function updateDisputeStatus(Request $request, string $id): JsonResponse
    {
        if ($request->filled('decision_reason') && ! $request->filled('decision')) {
            $request->merge(['decision' => $request->input('decision_reason')]);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:under_review,resolved,rejected,closed'],
            'decision' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        /** @var PaymentDispute|null $dispute */
        $dispute = PaymentDispute::where('id', $id)
            ->orWhere('reference', $id)
            ->first();

        if (! $dispute) {
            return $this->paymentError(
                code: 'DISPUTE_NOT_FOUND',
                message: 'Dossier de litige introuvable.',
                status: 404
            );
        }

        $dispute->transitionTo(
            targetStatus: $validated['status'],
            actor: $request->user(),
            decision: $validated['decision']
        );

        return $this->paymentSuccess(
            data: $dispute,
            message: 'Statut du litige mis à jour avec décision tracée.'
        );
    }

    /**
     * POST /api/admin/payments/disputes/{id}/notes
     * Ajoute une note d'investigation au dossier.
     */
    public function addDisputeNote(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        /** @var PaymentDispute|null $dispute */
        $dispute = PaymentDispute::where('id', $id)
            ->orWhere('reference', $id)
            ->first();

        if (! $dispute) {
            return $this->paymentError(
                code: 'DISPUTE_NOT_FOUND',
                message: 'Dossier de litige introuvable.',
                status: 404
            );
        }

        $dispute->addNote($request->user(), $validated['note']);

        return $this->paymentSuccess(
            data: $dispute,
            message: 'Note d\'investigation ajoutée avec succès.'
        );
    }

    /**
     * Nettoie récursivement les données pour masquer ou expurger les secrets et tokens.
     */
    protected function sanitizeData(mixed $data): mixed
    {
        if (is_array($data)) {
            $filtered = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && preg_match('/(secret|password|token|private_key|api_key|authorization|leak)/i', $key)) {
                    continue;
                }
                $filtered[$key] = $this->sanitizeData($value);
            }

            return $filtered;
        }

        if (is_object($data)) {
            if ($data instanceof Model) {
                return $this->sanitizeData($data->toArray());
            }
            if ($data instanceof Collection) {
                return $data->map(fn ($item) => $this->sanitizeData($item))->all();
            }
        }

        return $data;
    }
}
