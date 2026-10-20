<?php

namespace App\Http\Controllers\Listing;

use App\Http\Controllers\Controller;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingComment;
use App\Notifications\Social\SocialNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListingCommentController extends Controller
{
    /**
     * Liste des commentaires d'une annonce immobilière.
     */
    public function index(Request $request, string $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);
        $perPage = max(1, min(50, $request->integer('per_page', 20)));

        $comments = ListingComment::where('listing_id', $listing->id)
            ->whereNull('parent_id')
            ->with(['user:id,name,handle,display_name,avatar_url,is_verified', 'replies.user:id,name,handle,display_name,avatar_url,is_verified'])
            ->latest('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $comments->items(),
            'meta' => [
                'current_page' => $comments->currentPage(),
                'last_page' => $comments->lastPage(),
                'total' => $comments->total(),
            ],
        ]);
    }

    /**
     * Publier un commentaire sur une annonce immobilière.
     */
    public function store(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'min:1', 'max:1000'],
            'parent_id' => ['nullable', 'integer', 'exists:listing_comments,id'],
        ]);

        $listing = Listing::findOrFail($id);
        $user = $request->user();

        $comment = ListingComment::create([
            'listing_id' => $listing->id,
            'user_id' => $user->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'content' => $validated['content'],
        ]);

        if ($listing->owner_id !== $user->id) {
            try {
                $listing->owner->notify(new SocialNotification('comment', $user, [
                    'listing_id' => $listing->id,
                    'text' => $validated['content'],
                ]));
            } catch (\Throwable $e) {
                // Ignore notification failure
            }
        }

        $comment->load('user:id,name,handle,display_name,avatar_url,is_verified');

        return response()->json([
            'success' => true,
            'data' => $comment,
        ], 201);
    }

    /**
     * Supprimer un commentaire sur une annonce.
     */
    public function destroy(Request $request, string $id, string $commentId): JsonResponse
    {
        $listing = Listing::findOrFail($id);
        $user = $request->user();

        $comment = ListingComment::where('listing_id', $listing->id)->findOrFail($commentId);

        if ($comment->user_id !== $user->id && $listing->owner_id !== $user->id && ! $user->isAdmin()) {
            abort(403, 'Action non autorisée.');
        }

        $comment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Commentaire supprimé.',
        ]);
    }
}
