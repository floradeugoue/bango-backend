<?php

namespace App\Http\Controllers\Square;

use App\Http\Controllers\Controller;
use App\Models\Square\Post;
use App\Services\Square\PostViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PostViewController extends Controller
{
    public function __construct(
        protected PostViewService $viewService
    ) {}

    /**
     * POST /api/posts/{post}/view
     * Enregistre une vue (au plus une par compte et par 24 h).
     */
    public function store(Request $request, Post $post): JsonResponse
    {
        Gate::authorize('view', $post);

        $result = $this->viewService->recordView($post, $request->user());

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
