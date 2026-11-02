<?php

namespace App\Http\Controllers\Square;

use App\Http\Controllers\Controller;
use App\Http\Resources\Square\PostResource;
use App\Models\Square\Post;
use App\Services\Square\PostSaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PostSaveController extends Controller
{
    public function __construct(
        protected PostSaveService $saveService
    ) {}

    /**
     * POST /api/square-cr1/posts/{post}/save
     * Bascule l'enregistrement d'une publication (saved / unsaved).
     */
    public function toggle(Request $request, Post $post): JsonResponse
    {
        $result = $this->saveService->toggleSave($post, $request->user());

        return response()->json([
            'success' => true,
            'saved' => $result['saved'],
            'is_saved' => $result['saved'],
            'saves_count' => $result['saves_count'],
            'post_id' => $post->id,
        ]);
    }

    /**
     * GET /api/square-cr1/posts/saved
     * Liste paginée des publications enregistrées par l'utilisateur connecté.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->input('per_page', 15);

        $posts = $this->saveService->listSavedPosts(
            user: $request->user(),
            perPage: $perPage
        );

        return PostResource::collection($posts);
    }
}
