<?php

namespace App\Http\Controllers\Square;

use App\Http\Controllers\Controller;
use App\Http\Requests\Square\SharePostRequest;
use App\Models\Square\Post;
use App\Services\Square\PostShareService;
use Illuminate\Http\JsonResponse;

class PostShareController extends Controller
{
    public function __construct(
        protected PostShareService $shareService
    ) {}

    /**
     * POST /api/square-cr1/posts/{post}/share
     * Enregistre un partage de publication et renvoie les liens stables de partage.
     */
    public function store(SharePostRequest $request, Post $post): JsonResponse
    {
        $result = $this->shareService->share(
            post: $post,
            user: $request->user(),
            channel: $request->channel(),
            idempotencyKey: $request->idempotencyKey()
        );

        return response()->json([
            'success' => true,
            'message' => 'Publication partagée avec succès.',
            'shared' => true,
            'shares_count' => $result['shares_count'],
            'share_url' => $result['share_url'],
            'deep_link' => $result['deep_link'],
            'post_id' => $result['post_id'],
        ]);
    }
}
