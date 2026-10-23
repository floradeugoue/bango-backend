<?php

namespace App\Http\Controllers\Listing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Listing\ListingResource;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingLike;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ListingLikeController extends Controller
{
    /**
     * Liste paginée des annonces aimées par l'utilisateur connecté (onglet Immo du profil).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = max(1, min(50, $request->integer('per_page', 15)));

        $paginator = $request->user()->likedListings()
            ->publiclyVisible()
            ->with(['owner', 'agent', 'photos', 'videos', 'saves', 'likes'])
            ->orderByDesc('listing_likes.created_at')
            ->paginate($perPage);

        return ListingResource::collection($paginator);
    }

    /**
     * Aimer une annonce (idempotent : un seul « j'aime » par compte).
     */
    public function store(Request $request, string $id): JsonResponse
    {
        $listing = Listing::publiclyVisible()->findOrFail($id);

        DB::transaction(function () use ($listing, $request) {
            /** @var User $user */
            $user = $request->user();

            $like = ListingLike::firstOrCreate([
                'user_id' => $user->id,
                'listing_id' => $listing->id,
            ]);

            if ($like->wasRecentlyCreated) {
                $listing->increment('likes_count');
            }
        });

        return response()->json([
            'success' => true,
            'liked' => true,
            'likes_count' => (int) $listing->fresh()->likes_count,
        ]);
    }

    /**
     * Retirer son « j'aime » (idempotent).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);

        DB::transaction(function () use ($listing, $request) {
            $deleted = ListingLike::where('user_id', $request->user()->id)
                ->where('listing_id', $listing->id)
                ->delete();

            if ($deleted > 0) {
                $listing->decrement('likes_count');
            }
        });

        return response()->json([
            'success' => true,
            'liked' => false,
            'likes_count' => (int) $listing->fresh()->likes_count,
        ]);
    }
}
