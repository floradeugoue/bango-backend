<?php

namespace App\Http\Controllers\Square;

use App\Http\Controllers\Controller;
use App\Http\Requests\Square\UpdateAudienceRequest;
use App\Http\Resources\Square\PostResource;
use App\Models\Square\Post;
use App\Services\Square\PostManagementService;
use Illuminate\Support\Facades\Gate;

class PostAudienceController extends Controller
{
    /**
     * Modifie l'audience d'une publication (SQ-15).
     */
    public function update(UpdateAudienceRequest $request, Post $post, PostManagementService $service): PostResource
    {
        Gate::authorize('update', $post);

        $updated = $service->updateAudience($post, $request->audience());

        return new PostResource($updated->load(['media.tags.user', 'hashtags', 'user']));
    }
}
