<?php

namespace App\Http\Controllers\Square;

use App\Http\Controllers\Controller;
use App\Models\Square\Post;
use App\Services\Square\PostLikeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostLikeController extends Controller
{
    public function __construct(
        protected PostLikeService $likeService
    ) {}

    /**
     * POST /api/square-cr1/posts/{post}/like
     * Aime une publication de manière idempotente.
     */
    public function store(Request $request, Post $post): JsonResponse
    {
        $result = $this->likeService->like($post, $request->user());

        return response()->json([
            'success' => true,
            'liked' => $result['liked'],
            'likes_count' => $result['likes_count'],
            'post_id' => $post->id,
        ]);
    }

    /**
     * DELETE /api/square-cr1/posts/{post}/like
     * Retire le like d'une publication de manière idempotente.
     */
    public function destroy(Request $request, Post $post): JsonResponse
    {
        $result = $this->likeService->unlike($post, $request->user());

        return response()->json([
            'success' => true,
            'liked' => $result['liked'],
            'likes_count' => $result['likes_count'],
            'post_id' => $post->id,
        ]);
    }
}
