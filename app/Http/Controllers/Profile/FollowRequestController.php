<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Resources\FollowUserResource;
use App\Models\Profile\FollowRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FollowRequestController extends Controller
{
    /**
     * List received pending follow requests for authenticated user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $blockedIds = $user->blockedUsers()->pluck('users.id')->toArray();

        $requests = FollowRequest::where('to_id', $user->id)
            ->where('status', 'pending')
            ->whereNotIn('from_id', $blockedIds)
            ->latest()
            ->with('sender')
            ->get();

        $senders = $requests->pluck('sender')->filter();

        return FollowUserResource::collection($senders);
    }

    /**
     * List sent pending follow requests.
     */
    public function sent(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $sentIds = FollowRequest::where('from_id', $user->id)
            ->where('status', 'pending')
            ->pluck('to_id');

        return response()->json(['sent' => $sentIds]);
    }

    /**
     * Accept a follow request.
     */
    public function accept(Request $request, string $fromId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $followRequest = FollowRequest::where('from_id', $fromId)
            ->where('to_id', $user->id)
            ->first();

        if (! $followRequest) {
            return response()->json(['message' => 'Demande d\'abonnement introuvable.'], 404);
        }

        /** @var User $sender */
        $sender = User::findOrFail($fromId);

        // Add follow: sender follows authenticated user
        $sender->following()->syncWithoutDetaching([$user->id]);

        $followRequest->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Reject a follow request quietly.
     */
    public function reject(Request $request, string $fromId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        FollowRequest::where('from_id', $fromId)
            ->where('to_id', $user->id)
            ->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Accept all pending follow requests (e.g. when switching to public account).
     */
    public function acceptAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $pending = FollowRequest::where('to_id', $user->id)
            ->where('status', 'pending')
            ->get();

        $count = $pending->count();

        foreach ($pending as $req) {
            /** @var User|null $sender */
            $sender = User::find($req->from_id);
            if ($sender) {
                $sender->following()->syncWithoutDetaching([$user->id]);
            }
            $req->delete();
        }

        return response()->json([
            'success' => true,
            'acceptedCount' => $count,
        ]);
    }
}
