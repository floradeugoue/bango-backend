<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription\BadgeHistory;
use App\Models\Subscription\Subscription;
use App\Models\User;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionManagementController extends Controller
{
    /**
     * List all subscriptions with filters and pagination.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Subscription::with(['user', 'product', 'price'])
            ->orderByDesc('created_at');

        if ($request->filled('badge_type')) {
            $query->where('badge_type', $request->badge_type);
        }

        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }

        if ($request->filled('cadence')) {
            $query->where('cadence', $request->cadence);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('q')) {
            $search = $request->q;
            $query->whereHas('user', function ($uq) use ($search) {
                $uq->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('handle', 'ilike', "%{$search}%");
            });
        }

        $subscriptions = $query->paginate($request->integer('per_page', 20));

        return SubscriptionResource::collection($subscriptions);
    }

    /**
     * Show subscription details and event timeline.
     */
    public function show(int $id): JsonResponse|SubscriptionResource
    {
        $subscription = Subscription::with(['user', 'product', 'price', 'events.actor'])
            ->find($id);

        if (! $subscription) {
            return response()->json(['message' => 'Abonnement introuvable.'], 404);
        }

        return new SubscriptionResource($subscription);
    }

    /**
     * Administrative suspension of a subscription.
     */
    public function suspend(Request $request, int $id, SubscriptionService $service): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $subscription = Subscription::find($id);

        if (! $subscription) {
            return response()->json(['message' => 'Abonnement introuvable.'], 404);
        }

        /** @var User $admin */
        $admin = $request->user();

        $updated = $service->adminSuspend($subscription, $admin, $request->reason);

        return response()->json([
            'success' => true,
            'message' => 'Abonnement suspendu administrativement.',
            'data' => new SubscriptionResource($updated->load(['user', 'product', 'price'])),
        ]);
    }

    /**
     * Administrative reactivation of a subscription.
     */
    public function reactivate(Request $request, int $id, SubscriptionService $service): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $subscription = Subscription::find($id);

        if (! $subscription) {
            return response()->json(['message' => 'Abonnement introuvable.'], 404);
        }

        /** @var User $admin */
        $admin = $request->user();

        $updated = $service->adminReactivate($subscription, $admin, $request->reason);

        return response()->json([
            'success' => true,
            'message' => 'Abonnement réactivé avec succès.',
            'data' => new SubscriptionResource($updated->load(['user', 'product', 'price'])),
        ]);
    }

    /**
     * Administrative extension of a subscription duration (gesture).
     */
    public function extend(Request $request, int $id, SubscriptionService $service): JsonResponse
    {
        $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $subscription = Subscription::find($id);

        if (! $subscription) {
            return response()->json(['message' => 'Abonnement introuvable.'], 404);
        }

        /** @var User $admin */
        $admin = $request->user();

        $updated = $service->adminExtend($subscription, (int) $request->days, $admin, $request->reason);

        return response()->json([
            'success' => true,
            'message' => "Abonnement prolongé de {$request->days} jours.",
            'data' => new SubscriptionResource($updated->load(['user', 'product', 'price'])),
        ]);
    }

    /**
     * Administrative revocation of a subscription and badge.
     */
    public function revoke(Request $request, int $id, SubscriptionService $service): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $subscription = Subscription::find($id);

        if (! $subscription) {
            return response()->json(['message' => 'Abonnement introuvable.'], 404);
        }

        /** @var User $admin */
        $admin = $request->user();

        $updated = $service->expireSubscription($subscription, "Révocation administrative : {$request->reason}");

        return response()->json([
            'success' => true,
            'message' => 'Abonnement et badge révoqués.',
            'data' => new SubscriptionResource($updated->load(['user', 'product', 'price'])),
        ]);
    }

    /**
     * Audit log of all badge histories.
     */
    public function badgeHistories(Request $request): JsonResponse
    {
        $query = BadgeHistory::with(['user', 'actor'])
            ->orderByDesc('created_at');

        if ($request->filled('badge')) {
            $query->where('badge', $request->badge);
        }

        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $histories = $query->paginate($request->integer('per_page', 25));

        return response()->json($histories);
    }
}
