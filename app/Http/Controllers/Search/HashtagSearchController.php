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
     * Recherche et liste des hashtags tendances (CR-07).
     */
    public function trending(Request $request): AnonymousResourceCollection
    {
        $query = trim((string) $request->query('q', ''));
        $normalized = Hashtag::normalize($query);

        $hashtags = Hashtag::query()
            ->when($normalized !== '', function ($q) use ($normalized) {
                $q->where('normalized_name', 'like', "%{$normalized}%");
            })
            ->orderByDesc('posts_count')
            ->orderBy('name', 'asc')
            ->limit(20)
            ->get();

        return HashtagSearchResultResource::collection($hashtags);
    }
}
