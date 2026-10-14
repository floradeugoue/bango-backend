<?php

namespace App\Http\Controllers\Square;

use App\Http\Controllers\Controller;
use App\Http\Resources\Square\PostResource;
use App\Services\Square\PostFeedService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PostFeedController extends Controller
{
    /**
     * Retourne le fil Square paginé (CR-11 et flux général).
     */
    public function index(Request $request, PostFeedService $service): AnonymousResourceCollection
    {
        $feed = $service->getFeed($request->user(), [
            'tab' => $request->query('tab', 'discover'),
            'author_id' => $request->query('author_id') ? (int) $request->query('author_id') : null,
            'archived' => $request->boolean('archived'),
            'per_page' => $request->query('per_page') ? (int) $request->query('per_page') : 15,
        ]);

        return PostResource::collection($feed);
    }
}
