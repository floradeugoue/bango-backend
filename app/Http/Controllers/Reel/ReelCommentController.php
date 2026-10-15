<?php

namespace App\Http\Controllers\Reel;

use App\Enums\Reel\ReelReactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reel\ReelCommentReactionRequest;
use App\Http\Requests\Reel\StoreReelCommentRequest;
use App\Http\Requests\Reel\UpdateReelCommentRequest;
use App\Http\Resources\Reel\ReelCommentResource;
use App\Models\Reel\Reel;
use App\Models\Reel\ReelComment;
use App\Services\Reel\ReelCommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReelCommentController extends Controller
{
    public function __construct(protected ReelCommentService $commentService) {}

    /**
     * Liste des commentaires d'un Reel (RL-03).
     */
    public function index(Reel $reel, Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->query('per_page', 20);
        $comments = $this->commentService->listComments($reel, $request->user(), $perPage);

        return ReelCommentResource::collection($comments);
    }

    /**
     * Ajouter un commentaire ou une réponse sur un Reel.
     */
    public function store(Reel $reel, StoreReelCommentRequest $request): JsonResponse
    {
        $comment = $this->commentService->createComment(
            $reel,
            $request->user(),
            $request->validated('body'),
            $request->validated('parent_id')
        );

        return response()->json([
            'success' => true,
            'message' => 'Commentaire publié avec succès.',
            'data' => new ReelCommentResource($comment),
        ], 201);
    }

    /**
     * Obtenir les réponses à un commentaire.
     */
    public function replies(ReelComment $comment, Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->query('per_page', 20);
        $replies = $this->commentService->getReplies($comment, $request->user(), $perPage);

        return ReelCommentResource::collection($replies);
    }

    /**
     * Modifier son commentaire.
     */
    public function update(ReelComment $comment, UpdateReelCommentRequest $request): JsonResponse
    {
        $updated = $this->commentService->updateComment(
            $comment,
            $request->user(),
            $request->validated('body')
        );

        return response()->json([
            'success' => true,
            'message' => 'Commentaire modifié avec succès.',
            'data' => new ReelCommentResource($updated),
        ]);
    }

    /**
     * Supprimer son commentaire.
     */
    public function destroy(ReelComment $comment, Request $request): JsonResponse
    {
        $this->commentService->deleteComment($comment, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Commentaire supprimé avec succès.',
        ]);
    }

    /**
     * Réagir à un commentaire (Like / Dislike).
     */
    public function react(ReelComment $comment, ReelCommentReactionRequest $request): JsonResponse
    {
        $type = ReelReactionType::from($request->validated('type'));
        $result = $this->commentService->reactToComment($comment, $request->user(), $type);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Retirer sa réaction à un commentaire.
     */
    public function removeReaction(ReelComment $comment, Request $request): JsonResponse
    {
        $result = $this->commentService->removeCommentReaction($comment, $request->user());

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
