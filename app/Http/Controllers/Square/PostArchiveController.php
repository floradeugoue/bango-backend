<?php

namespace App\Http\Controllers\Square;

use App\Http\Controllers\Controller;
use App\Http\Resources\Square\PostResource;
use App\Models\Square\Post;
use App\Services\Square\PostManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PostArchiveController extends Controller
{
    /**
     * Archive une publication (SQ-15).
     */
    public function archive(Request $request, Post $post, PostManagementService $service): PostResource
    {
        Gate::authorize('archive', $post);

        $updated = $service->archive($post);

        return new PostResource($updated->load(['media.tags.user', 'hashtags', 'user']));
    }

    /**
     * Désarchive une publication (SQ-15).
     */
    public function unarchive(Request $request, Post $post, PostManagementService $service): PostResource
    {
        Gate::authorize('archive', $post);

        $updated = $service->unarchive($post);

        return new PostResource($updated->load(['media.tags.user', 'hashtags', 'user']));
    }
}
