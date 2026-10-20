<?php

namespace App\Http\Controllers\Page;

use App\Http\Controllers\Controller;
use App\Http\Requests\Page\CreatePageRequest;
use App\Http\Requests\Page\TransferOwnershipRequest;
use App\Http\Requests\Page\UpdatePageRequest;
use App\Http\Resources\Listing\ListingResource;
use App\Http\Resources\Message\ConversationResource;
use App\Http\Resources\Page\PageResource;
use App\Models\Page\Page;
use App\Models\User;
use App\Services\Page\PageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PageController extends Controller
{
    public function __construct(
        protected PageService $pageService
    ) {}

    /**
     * Liste les Pages Professionnelles de l'utilisateur connecté (« Mes Pages »).
     */
    public function myPages(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $pages = Page::whereHas('members', function ($query) use ($user) {
            $query->where('user_id', $user->id)
                ->whereIn('status', ['accepted', 'pending']);
        })
            ->with(['owner', 'activeMembers.user'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return PageResource::collection($pages);
    }

    /**
     * Crée une nouvelle Page Professionnelle.
     */
    public function store(CreatePageRequest $request): JsonResponse
    {
        Gate::authorize('create', Page::class);

        $page = $this->pageService->create(
            owner: $request->user(),
            data: $request->validated(),
            logo: $request->file('logo'),
            cover: $request->file('cover')
        );

        return (new PageResource($page))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Consulte le profil public d'une Page par ID ou par son Handle unique.
     */
    public function show(Request $request, string|int $identifier): PageResource
    {
        $page = is_numeric($identifier)
            ? Page::with(['owner', 'activeMembers.user'])->findOrFail((int) $identifier)
            : Page::with(['owner', 'activeMembers.user'])->where('handle', $identifier)->firstOrFail();

        Gate::authorize('view', $page);

        return new PageResource($page);
    }

    /**
     * Modifie les informations d'une Page.
     */
    public function update(UpdatePageRequest $request, Page $page): PageResource
    {
        Gate::authorize('update', $page);

        $updated = $this->pageService->update(
            page: $page,
            data: $request->validated(),
            logo: $request->file('logo'),
            cover: $request->file('cover')
        );

        return new PageResource($updated);
    }

    /**
     * Supprime une Page (soft-delete).
     */
    public function destroy(Request $request, Page $page): JsonResponse
    {
        Gate::authorize('delete', $page);

        $this->pageService->deletePage($page, $request->user());

        return response()->json([
            'message' => 'La Page Professionnelle a été supprimée avec succès.',
        ]);
    }

    /**
     * Transfère la propriété de la Page à un successeur (EDGE-01).
     */
    public function transferOwnership(TransferOwnershipRequest $request, Page $page): PageResource
    {
        Gate::authorize('transferOwnership', $page);

        /** @var User $newOwner */
        $newOwner = User::findOrFail($request->validated('new_owner_id'));

        $updatedPage = $this->pageService->transferOwnership(
            page: $page,
            currentOwner: $request->user(),
            newOwner: $newOwner
        );

        return new PageResource($updatedPage);
    }

    /**
     * Bascule le contexte actif de l'application vers cette Page (PG-04).
     */
    public function switchContext(Request $request, Page $page): JsonResponse
    {
        $context = $this->pageService->switchContext($request->user(), $page);

        return response()->json([
            'message' => 'Contexte actif basculé sur la Page avec succès.',
            'data' => $context,
        ]);
    }

    /**
     * Boîte de réception cloisonnée de la Page Professionnelle (MS-19).
     */
    public function inbox(Request $request, Page $page): AnonymousResourceCollection
    {
        Gate::authorize('accessInbox', $page);

        $conversations = $page->conversations()
            ->with(['creator', 'listing', 'latestMessage.sender', 'members.user'])
            ->orderByDesc('last_message_at')
            ->paginate($request->integer('per_page', 20));

        return ConversationResource::collection($conversations);
    }

    /**
     * Vitrine immobilière des annonces rattachées à l'agence (IM).
     */
    public function listings(Request $request, Page $page): AnonymousResourceCollection
    {
        $listings = $page->listings()
            ->publiclyVisible()
            ->with(['owner', 'agent', 'media'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return ListingResource::collection($listings);
    }

    /**
     * Masque ou réaffiche une Page Professionnelle.
     * POST /api/pages/{page}/visibility
     */
    public function toggleVisibility(Request $request, Page $page): JsonResponse
    {
        Gate::authorize('update', $page);

        $hidden = $request->has('is_hidden')
            ? $request->boolean('is_hidden')
            : ! $page->is_hidden;

        $page->is_hidden = $hidden;
        $page->save();

        return response()->json([
            'message' => $page->is_hidden ? 'Page masquée avec succès.' : 'Page visible au public.',
            'data' => new PageResource($page->fresh(['owner', 'activeMembers.user'])),
        ]);
    }

    /**
     * Attribue la gestion de la boîte de réception de la page à un membre spécifique.
     * POST /api/pages/{page}/inbox-assignment
     */
    public function assignInbox(Request $request, Page $page): JsonResponse
    {
        Gate::authorize('update', $page);

        $validated = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
        ]);

        $assigneeId = $validated['user_id'] ?? null;
        if ($assigneeId) {
            $isMember = $page->members()->where('user_id', $assigneeId)->where('status', 'accepted')->exists();
            if (! $isMember && (int) $page->owner_id !== (int) $assigneeId) {
                return response()->json([
                    'message' => 'L\'utilisateur assigné doit être un membre accepté ou le propriétaire de la page.',
                ], 422);
            }
        }

        $page->inbox_assigned_to_id = $assigneeId;
        $page->save();

        return response()->json([
            'message' => 'Attribution de la boîte de réception mise à jour.',
            'data' => new PageResource($page->fresh(['owner', 'activeMembers.user', 'inboxAssignee'])),
        ]);
    }

    /**
     * S'abonner à une page professionnelle.
     * POST /api/pages/{page}/follow
     */
    public function follow(Request $request, Page $page): JsonResponse
    {
        $user = $request->user();

        if (! $page->followers()->where('user_id', $user->id)->exists()) {
            $page->followers()->attach($user->id);
            $page->increment('followers_count');
        }

        return response()->json([
            'message' => 'Vous êtes désormais abonné à cette page.',
            'is_following' => true,
            'followers_count' => (int) $page->fresh()->followers_count,
        ]);
    }

    /**
     * Se désabonner d'une page professionnelle.
     * DELETE /api/pages/{page}/follow
     */
    public function unfollow(Request $request, Page $page): JsonResponse
    {
        $user = $request->user();

        if ($page->followers()->where('user_id', $user->id)->exists()) {
            $page->followers()->detach($user->id);
            $page->decrement('followers_count');
        }

        return response()->json([
            'message' => 'Vous n\'êtes plus abonné à cette page.',
            'is_following' => false,
            'followers_count' => (int) $page->fresh()->followers_count,
        ]);
    }
}
