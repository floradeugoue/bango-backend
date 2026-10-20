<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Http\Resources\Search\HashtagSearchResultResource;
use App\Http\Resources\Square\PostResource;
use App\Models\Square\Hashtag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HashtagSearchController extends Controller
{
    /**
     * Recherche de hashtags par correspondance partielle et volume (RC-07).
     *
     * GET /api/search/hashtags?q=...
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = trim((string) $request->query('q', ''));
        $normalized = Hashtag::normalize($query);
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $hashtags = Hashtag::query()
            ->when($normalized !== '', function ($q) use ($normalized) {
                $q->where('normalized_name', 'like', "%{$normalized}%");
            })
            ->orderByDesc('posts_count')
            ->orderBy('normalized_name', 'asc')
            ->paginate($perPage);

        return HashtagSearchResultResource::collection($hashtags);
    }

    /**
     * Liste des hashtags tendances pour le carrousel (compatibilité CR-07).
     *
     * GET /api/search/hashtags/trending
     */
    public function trending(Request $request): AnonymousResourceCollection
    {
        $query = trim((string) $request->query('q', ''));
        $normalized = Hashtag::normalize($query);
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $hashtags = Hashtag::query()
            ->when($normalized !== '', function ($q) use ($normalized) {
                $q->where('normalized_name', 'like', "%{$normalized}%");
            })
            ->orderByDesc('posts_count')
            ->orderBy('name', 'asc')
            ->paginate($perPage);

        return HashtagSearchResultResource::collection($hashtags);
    }

    /**
     * Page d'un hashtag (détails et publications associées).
     *
     * GET /api/hashtags/{name} ou GET /api/search/hashtags/{name}
     */
    public function show(Request $request, string $name): JsonResponse
    {
        $normalized = Hashtag::normalize($name);

        $hashtag = Hashtag::where('normalized_name', $normalized)
            ->orWhere('name', $name)
            ->firstOrFail();

        $perPage = min(max((int) $request->input('per_page', 20), 1), 50);

        $posts = $hashtag->posts()
            ->with(['media.tags.user', 'hashtags', 'user'])
            ->latest('posts.id')
            ->paginate($perPage);

        $postData = PostResource::collection($posts)->response()->getData(true);

        return response()->json([
            'success' => true,
            'data' => [
                'hashtag' => new HashtagSearchResultResource($hashtag),
                'posts' => $postData,
            ],
            'hashtag' => new HashtagSearchResultResource($hashtag),
            'posts' => $postData,
        ]);
    }

    /**
     * S'abonner à un hashtag.
     *
     * POST /api/hashtags/{name}/follow
     */
    public function follow(Request $request, string $name): JsonResponse
    {
        $user = $request->user();
        $normalized = Hashtag::normalize($name);

        $hashtag = Hashtag::firstOrCreate(
            ['normalized_name' => $normalized],
            ['name' => ltrim(trim($name), '#'), 'posts_count' => 0, 'followers_count' => 0]
        );

        if (! $hashtag->followers()->where('users.id', $user->id)->exists()) {
            $hashtag->followers()->attach($user->id);
            $hashtag->increment('followers_count');
        }

        $hashtag->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Abonnement au hashtag enregistré.',
            'is_following' => true,
            'followers_count' => (int) $hashtag->followers_count,
            'data' => new HashtagSearchResultResource($hashtag),
        ]);
    }

    /**
     * Se désabonner d'un hashtag.
     *
     * DELETE /api/hashtags/{name}/follow
     */
    public function unfollow(Request $request, string $name): JsonResponse
    {
        $user = $request->user();
        $normalized = Hashtag::normalize($name);

        $hashtag = Hashtag::where('normalized_name', $normalized)
            ->orWhere('name', $name)
            ->firstOrFail();

        if ($hashtag->followers()->where('users.id', $user->id)->exists()) {
            $hashtag->followers()->detach($user->id);
            if ($hashtag->followers_count > 0) {
                $hashtag->decrement('followers_count');
            }
        }

        $hashtag->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Désabonnement du hashtag effectué.',
            'is_following' => false,
            'followers_count' => (int) $hashtag->followers_count,
            'data' => new HashtagSearchResultResource($hashtag),
        ]);
    }
}
