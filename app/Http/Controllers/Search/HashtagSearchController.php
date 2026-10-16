<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Http\Resources\Search\HashtagSearchResultResource;
use App\Models\Square\Hashtag;
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
}
