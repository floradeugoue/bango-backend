<?php

namespace App\Http\Controllers\Square;

use App\Http\Controllers\Controller;
use App\Http\Requests\Square\CreatePostRequest;
use App\Http\Resources\Square\PostResource;
use App\Models\Square\Post;
use App\Services\Square\PostCreationService;
use App\Services\Square\PostManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PostController extends Controller
{
    /**
     * Crée une nouvelle publication Square (CR-01 à CR-12).
     */
    public function store(CreatePostRequest $request, PostCreationService $service): JsonResponse
    {
        $post = $service->create(
            $request->user(),
            $request->normalizedData()
        );

        $status = $post->wasRecentlyCreated ? 201 : 200;

        return (new PostResource($post))
            ->response()
            ->setStatusCode($status);
    }

    /**
     * Affiche une publication spécifique sous réserve des autorisations d'audience.
     */
    public function show(Request $request, Post $post): PostResource
    {
        Gate::authorize('view', $post);

        $post->load(['media.tags.user', 'hashtags', 'user']);

        return new PostResource($post);
    }

    /**
     * Supprime une publication (SQ-15).
     */
    public function destroy(Request $request, Post $post, PostManagementService $service): JsonResponse
    {
        Gate::authorize('delete', $post);

        $service->delete($post);

        return response()->json([
            'success' => true,
            'message' => 'Publication supprimée avec succès.',
        ]);
    }
}
