<?php

namespace App\Http\Controllers\Square;

use App\Http\Controllers\Controller;
use App\Http\Requests\Square\ReactCommentRequest;
use App\Models\Square\PostComment;
use App\Services\Square\PostCommentReactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostCommentReactionController extends Controller
{
    public function __construct(
        protected PostCommentReactionService $reactionService
    ) {}

    /**
     * PUT /api/square-cr1/comments/{comment}/reaction
     * Ajoute, modifie ou bascule (toggle) une réaction (like | dislike) sur un commentaire.
     */
    public function react(ReactCommentRequest $request, PostComment $comment): JsonResponse
    {
        $result = $this->reactionService->react(
            comment: $comment,
            user: $request->user(),
            type: $request->type()
        );

        return response()->json([
            'success' => true,
            'comment_id' => $comment->id,
            'my_reaction' => $result['my_reaction'],
            'likes_count' => $result['likes_count'],
            'dislikes_count' => $result['dislikes_count'],
        ]);
    }

    /**
     * DELETE /api/square-cr1/comments/{comment}/reaction
     * Supprime toute réaction de l'utilisateur sur le commentaire.
     */
    public function destroy(Request $request, PostComment $comment): JsonResponse
    {
        $result = $this->reactionService->removeReaction(
            comment: $comment,
            user: $request->user()
        );

        return response()->json([
            'success' => true,
            'comment_id' => $comment->id,
            'my_reaction' => $result['my_reaction'],
            'likes_count' => $result['likes_count'],
            'dislikes_count' => $result['dislikes_count'],
        ]);
    }
}
