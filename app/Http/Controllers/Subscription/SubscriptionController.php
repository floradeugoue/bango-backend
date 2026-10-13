<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
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
            ->with(['product', 'price'])
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

        // Fetch active subscriptions details
        $activeSubs = $user->activeSubscriptions()
            ->with('price')
            ->get();

        return response()->json([
            'status' => $status,
            'subscriptions' => SubscriptionResource::collection($activeSubs),
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
