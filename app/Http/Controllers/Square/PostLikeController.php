<?php

namespace App\Http\Controllers\Square;

use App\Http\Controllers\Controller;
use App\Http\Resources\Square\PostLikerResource;
use App\Models\Square\Post;
use App\Services\Square\PostLikeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

    /**
     * GET /api/square-cr1/posts/{post}/likers
     * Liste paginée des utilisateurs ayant aimé la publication (SQ-14).
     */
    public function likers(Request $request, Post $post): AnonymousResourceCollection
    {
        $perPage = (int) $request->input('per_page', 20);

        $likers = $this->likeService->getPostLikers(
            post: $post,
            currentUser: $request->user(),
            perPage: $perPage
        );

        return PostLikerResource::collection($likers);
    }
}
