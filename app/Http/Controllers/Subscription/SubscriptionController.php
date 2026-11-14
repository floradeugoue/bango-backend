<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Services\Legal\LegalDocumentService;
use App\Services\Subscription\BadgeResolutionService;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionController extends Controller
{
    /**
     * List user's subscriptions (current and past).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $subscriptions = $user->subscriptions()
            ->with(['product', 'price', 'page'])
            ->get();

        return SubscriptionResource::collection($subscriptions);
    }

    /**
     * Show a single subscription belonging to the authenticated user.
     */
    public function show(Request $request, int $id): JsonResponse|SubscriptionResource
    {
        /** @var User $user */
        $user = $request->user();

        $subscription = $user->subscriptions()
            ->with(['product', 'price', 'events'])
            ->find($id);

        if (! $subscription) {
            return response()->json([
                'kind' => 'not-found',
                'message' => 'Abonnement introuvable.',
            ], 404);
        }

        return new SubscriptionResource($subscription);
    }

    /**
     * Get user's active badges status and avatar ring color.
     */
    public function status(Request $request, BadgeResolutionService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $status = $service->resolveUserBadges($user);

        // Récupérer les abonnements actifs, en période de grâce, ou expirés/résiliés
        // Pour chaque type de badge, prioriser l'abonnement actif s'il existe, sinon le plus récent
        $subscriptions = $user->subscriptions()
            ->with(['price', 'product', 'page'])
            ->whereIn('state', ['active', 'grace', 'expired', 'canceled'])
            ->orderByRaw("CASE WHEN state = 'active' THEN 1 WHEN state = 'grace' THEN 2 WHEN state = 'expired' THEN 3 ELSE 4 END")
            ->orderByDesc('current_period_end')
            ->orderByDesc('created_at')
            ->get()
            ->unique('badge_type')
            ->values();

        return response()->json([
            'status' => $status,
            'subscriptions' => SubscriptionResource::collection($subscriptions),
        ]);
    }

    /**
     * POST /api/subscriptions/quote
     * Calcule le devis d'abonnement ou d'ajout de badge avec prorata d'alignement (décision Marie 26/10).
     */
    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'badge' => ['required', 'string', 'in:landlord,agent'],
            'cadence' => ['required', 'string', 'in:monthly,yearly,quarterly'],
            'align_with' => ['nullable', 'string', 'in:landlord,agent'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // 1. Trouver le produit d'abonnement pour le badge demandé
        $product = SubscriptionProduct::where('badge_type', $validated['badge'])
            ->orWhere('code', $validated['badge'])
            ->where('is_active', true)
            ->first();

        if (! $product) {
            return response()->json([
                'message' => 'Offre d\'abonnement introuvable pour ce badge.',
            ], 404);
        }

        /** @var SubscriptionPrice|null $price */
        $price = $product->activePrices()
            ->where('cadence', $validated['cadence'])
            ->first();

        if (! $price) {
            return response()->json([
                'message' => 'Tarif indisponible pour la cadence demandée.',
            ], 404);
        }

        if ($price->required_terms_version) {
            $legalService = app(LegalDocumentService::class);
            if (! $legalService->hasUserAcceptedVersion($user, $price->required_terms_version)) {
                return response()->json([
                    'message' => "Vous devez accepter la version {$price->required_terms_version} des conditions générales d'utilisation pour souscrire à ce tarif.",
                    'error' => 'TERMS_ACCEPTANCE_REQUIRED',
                    'required_version' => $price->required_terms_version,
                    'accepted_version' => $user->accepted_terms_version,
                    'document_slug' => LegalDocumentService::TERMS_SLUG,
                ], 403);
            }
        }

        $now = now();
        $baseAmount = (float) $price->price_amount;
        $currency = $price->currency;
        $periodEnd = $now->copy()->addDays($price->duration_days ?: 30);
        $amount = $baseAmount;
        $prorated = false;

        // 2. Si align_with est fourni, calculer le prorata jusqu'à la date du badge existant
        if (! empty($validated['align_with'])) {
            $alignBadge = $validated['align_with'];

            $existingSub = $user->subscriptions()
                ->where('badge_type', $alignBadge)
                ->where('state', 'active')
                ->where('current_period_end', '>', $now)
                ->first();

            if (! $existingSub) {
                return response()->json([
                    'message' => sprintf('Aucun abonnement actif trouvé pour le badge "%s" afin d\'aligner la date de renouvellement.', $alignBadge),
                ], 422);
            }

            $periodEnd = $existingSub->current_period_end;
            $remainingDays = max(1, $now->diffInDays($periodEnd, false));
            $baseDurationDays = $price->duration_days ?: ($validated['cadence'] === 'yearly' ? 365 : 30);

            // Facturation au prorata jusqu'à la date du premier abonnement
            $ratio = min(1.0, max(0.0, $remainingDays / $baseDurationDays));
            $amount = round($baseAmount * $ratio, 2);
            $prorated = true;
        }

        return response()->json([
            'data' => [
                'amount' => $amount,
                'currency' => $currency,
                'period_end' => $periodEnd->toISOString(),
                'price_id' => $price->id,
                'badge' => $validated['badge'],
                'cadence' => $validated['cadence'],
                'align_with' => $validated['align_with'] ?? null,
                'prorated' => $prorated,
                'base_amount' => $baseAmount,
            ],
        ]);
    }

    /**
     * Prepare a subscription intent before payment.
     */
    public function subscribe(Request $request, SubscriptionService $service): JsonResponse
    {
        $validated = $request->validate([
            'price_id' => ['nullable', 'exists:subscription_prices,id'],
            'code' => ['nullable', 'string', 'exists:subscription_products,code'],
            'cadence' => ['nullable', 'string', 'in:monthly,yearly,one_off,cpm'],
            'idempotency_key' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Resolve price
        if (! empty($validated['price_id'])) {
            $price = SubscriptionPrice::with('product')->findOrFail($validated['price_id']);
        } elseif (! empty($validated['code']) && ! empty($validated['cadence'])) {
            $product = SubscriptionProduct::where('code', $validated['code'])->firstOrFail();
            $price = $product->activePrices()->where('cadence', $validated['cadence'])->firstOrFail();
        } else {
            return response()->json([
                'message' => 'Veuillez spécifier soit un price_id, soit un code d\'offre et une cadence.',
            ], 422);
        }

        if ($price->required_terms_version) {
            $legalService = app(LegalDocumentService::class);
            if (! $legalService->hasUserAcceptedVersion($user, $price->required_terms_version)) {
                return response()->json([
                    'message' => "Vous devez accepter la version {$price->required_terms_version} des conditions générales d'utilisation pour souscrire à ce tarif.",
                    'error' => 'TERMS_ACCEPTANCE_REQUIRED',
                    'required_version' => $price->required_terms_version,
                    'accepted_version' => $user->accepted_terms_version,
                    'document_slug' => LegalDocumentService::TERMS_SLUG,
                ], 403);
            }
        }

        $prepared = $service->prepareSubscription($user, $price, $request->all());

        return response()->json([
            'success' => true,
            'data' => $prepared,
        ], 200);
    }

    /**
     * Change plan (cadence upgrade/downgrade).
     */
    public function changePlan(Request $request, int $id, SubscriptionService $service): JsonResponse
    {
        $request->validate([
            'new_cadence' => ['required', 'string', 'in:monthly,yearly'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $subscription = $user->subscriptions()->find($id);

        if (! $subscription) {
            return response()->json(['message' => 'Abonnement introuvable.'], 404);
        }

        $product = $subscription->product;
        $newPrice = $product->activePrices()->where('cadence', $request->new_cadence)->first();

        if (! $newPrice) {
            return response()->json(['message' => 'Le tarif demandé n\'est pas disponible pour cette offre.'], 422);
        }

        if ($subscription->price_id === $newPrice->id) {
            return response()->json(['message' => 'Vous êtes déjà sur cette cadence de facturation.'], 422);
        }

        $calculation = $service->calculatePlanChange($subscription, $newPrice);

        return response()->json([
            'success' => true,
            'data' => $calculation,
        ]);
    }

    /**
     * Cancel an active subscription auto-renewal.
     */
    public function cancel(Request $request, int $id, SubscriptionService $service): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'feedback' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $subscription = $user->subscriptions()->find($id);

        if (! $subscription) {
            return response()->json(['message' => 'Abonnement introuvable.'], 404);
        }

        if (! $subscription->auto_renew) {
            return response()->json([
                'message' => 'Cet abonnement a déjà été résilié pour le prochain renouvellement.',
                'auto_renew' => false,
                'expires_at' => $subscription->current_period_end->toIso8601String(),
            ], 200);
        }

        $canceled = $service->cancelSubscription($subscription, $request->reason, $request->feedback);

        return response()->json([
            'message' => 'Abonnement résilié avec succès. Vos avantages restent valables jusqu\'au '.$canceled->current_period_end->format('d/m/Y').'.',
            'auto_renew' => false,
            'expires_at' => $canceled->current_period_end->toIso8601String(),
        ], 200);
    }

    /**
     * Resume an active subscription auto-renewal before expiration.
     */
    public function resume(Request $request, int $id, SubscriptionService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $subscription = $user->subscriptions()->find($id);

        if (! $subscription) {
            return response()->json(['message' => 'Abonnement introuvable.'], 404);
        }

        if (! $subscription->current_period_end || $subscription->current_period_end->isPast()) {
            return response()->json([
                'message' => 'Impossible de réactiver la reconduction automatique : cet abonnement est expiré.',
            ], 422);
        }

        $resumed = $service->resumeSubscription($subscription, $user);

        return response()->json([
            'message' => 'Reconduction automatique réactivée avec succès. Prochain renouvellement le '.$resumed->current_period_end->format('d/m/Y').'.',
            'auto_renew' => true,
            'expires_at' => $resumed->current_period_end->toIso8601String(),
            'subscription' => new SubscriptionResource($resumed->load(['product', 'price', 'page'])),
            'data' => new SubscriptionResource($resumed->load(['product', 'price', 'page'])),
        ], 200);
    }

    /**
     * List user's badge event history.
     */
    public function history(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $history = $user->badgeHistories()
            ->get();

        return response()->json([
            'data' => $history,
        ]);
    }
}
