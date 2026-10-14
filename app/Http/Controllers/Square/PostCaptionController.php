<?php

namespace App\Http\Controllers\Square;

use App\Http\Controllers\Controller;
use App\Http\Requests\Square\UpdateCaptionRequest;
use App\Http\Resources\Square\PostResource;
use App\Models\Square\Post;
use App\Services\Square\PostManagementService;
use Illuminate\Support\Facades\Gate;

class PostCaptionController extends Controller
{
    /**
     * Modifie la légende d'une publication (SQ-15).
     */
    public function update(UpdateCaptionRequest $request, Post $post, PostManagementService $service): PostResource
    {
        Gate::authorize('update', $post);

        $updated = $service->updateCaption($post, $request->caption());

        return new PostResource($updated);
    }
}
