<?php

namespace App\Http\Controllers\Square;

use App\Http\Controllers\Controller;
use App\Http\Requests\Square\UpdateCommentsStatusRequest;
use App\Http\Resources\Square\PostResource;
use App\Models\Square\Post;
use App\Services\Square\PostManagementService;
use Illuminate\Support\Facades\Gate;

class PostCommentsStatusController extends Controller
{
    /**
     * Modifie les autorisations de commentaires d'une publication (SQ-15).
     */
    public function update(UpdateCommentsStatusRequest $request, Post $post, PostManagementService $service): PostResource
    {
        Gate::authorize('update', $post);

        $updated = $service->updateCommentsStatus(
            $post,
            $request->commentsClosed(),
            $request->commentsBy()
        );

        return new PostResource($updated->load(['media.tags.user', 'hashtags', 'user']));
    }
}
