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
            ->with([
                'user:id,name,handle,display_name,avatar_url,is_verified',
                'page:id,name,handle,logo_url,certification_status',
                'replies.user:id,name,handle,display_name,avatar_url,is_verified',
                'replies.page:id,name,handle,logo_url,certification_status',
            ])
            ->latest('created_at')
            ->paginate($perPage);

        $items = collect($comments->items())->map(function ($comment) {
            $data = $comment->toArray();
            if ($comment->page_id && $comment->page) {
                $data['author'] = [
                    'id' => (string) $comment->page->id,
                    'name' => $comment->page->name,
                    'handle' => $comment->page->handle,
                    'logo_url' => $comment->page->logo_url,
                    'avatar_url' => $comment->page->logo_url,
                    'is_certified' => $comment->page->isCertified(),
                ];
                $data['user'] = $data['author'];
            }

            return $data;
        })->all();

        return response()->json([
            'success' => true,
            'data' => $items,
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
        $activePage = $request->attributes->get('acting_page') ?? $request->attributes->get('active_page');

        $comment = ListingComment::create([
            'listing_id' => $listing->id,
            'user_id' => $user->id,
            'page_id' => $activePage?->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'content' => $validated['content'],
        ]);

        if ($listing->owner_id !== $user->id) {
            try {
                $listing->owner->notify(new SocialNotification('comment', $user, [
                    'listing_id' => $listing->id,
                    'page_id' => $listing->page_id,
                    'author_page_id' => $activePage?->id,
                    'text' => $validated['content'],
                ]));
            } catch (\Throwable $e) {
                // Ignore notification failure
            }
        }

        $comment->load(['user:id,name,handle,display_name,avatar_url,is_verified', 'page:id,name,handle,logo_url,certification_status']);

        $commentData = $comment->toArray();
        if ($comment->page_id && $comment->page) {
            $commentData['author'] = [
                'id' => (string) $comment->page->id,
                'name' => $comment->page->name,
                'handle' => $comment->page->handle,
                'logo_url' => $comment->page->logo_url,
                'avatar_url' => $comment->page->logo_url,
                'is_certified' => $comment->page->isCertified(),
            ];
            $commentData['user'] = $commentData['author'];
        }

        return response()->json([
            'success' => true,
            'data' => $commentData,
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
