<?php

namespace App\Http\Controllers\Square;

use App\Http\Controllers\Controller;
use App\Http\Requests\Square\StorePostCommentRequest;
use App\Http\Requests\Square\UpdatePostCommentRequest;
use App\Http\Resources\Square\PostCommentResource;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Services\Square\PostCommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PostCommentController extends Controller
{
    public function __construct(
        protected PostCommentService $commentService
    ) {}

    /**
     * GET /api/square-cr1/posts/{post}/comments
     * Liste paginée des commentaires de premier niveau d'une publication.
     */
    public function index(Request $request, Post $post): AnonymousResourceCollection
    {
        $perPage = (int) $request->input('per_page', 20);
        $paginator = $this->commentService->getComments($post, $request->user(), $perPage);

        return PostCommentResource::collection($paginator);
    }

    /**
     * POST /api/square-cr1/posts/{post}/comments
     * Crée un commentaire ou une réponse sur une publication.
     */
    public function store(StorePostCommentRequest $request, Post $post): JsonResponse
    {
        $comment = $this->commentService->createComment(
            post: $post,
            user: $request->user(),
            body: $request->body(),
            parentId: $request->parentId()
        );

        return (new PostCommentResource($comment))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/square-cr1/comments/{comment}/replies
     * Liste paginée des réponses directes à un commentaire.
     */
    public function replies(Request $request, PostComment $comment): AnonymousResourceCollection
    {
        $perPage = (int) $request->input('per_page', 20);
        $paginator = $this->commentService->getReplies($comment, $request->user(), $perPage);

        return PostCommentResource::collection($paginator);
    }

    /**
     * PATCH /api/square-cr1/comments/{comment}
     * Modifie le corps d'un commentaire (réservé à l'auteur).
     */
    public function update(UpdatePostCommentRequest $request, PostComment $comment): JsonResponse
    {
        $updated = $this->commentService->updateComment(
            comment: $comment,
            user: $request->user(),
            newBody: $request->body()
        );

        return (new PostCommentResource($updated))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * DELETE /api/square-cr1/comments/{comment}
     * Supprime un commentaire et ses réponses (auteur du commentaire ou auteur du post).
     */
    public function destroy(Request $request, PostComment $comment): JsonResponse
    {
        $this->commentService->deleteComment($comment, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Commentaire supprimé avec succès.',
            'comment_id' => $comment->id,
        ]);
    }
}
