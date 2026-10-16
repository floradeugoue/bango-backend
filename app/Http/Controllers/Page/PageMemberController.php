<?php

namespace App\Http\Controllers\Page;

use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Page\InvitePageMemberRequest;
use App\Http\Requests\Page\UpdatePageMemberRoleRequest;
use App\Http\Resources\Page\PageMemberResource;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\User;
use App\Services\Page\PageMemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PageMemberController extends Controller
{
    public function __construct(
        protected PageMemberService $pageMemberService
    ) {}

    /**
     * Liste des membres et invitations d'une Page Professionnelle.
     */
    public function index(Request $request, Page $page): AnonymousResourceCollection
    {
        if (! $request->user()->isAdmin() && ! $page->hasMember($request->user()) && ! $page->isOwner($request->user())) {
            abort(403, 'Accès non autorisé aux membres de cette page.');
        }

        $members = $page->members()
            ->with(['user', 'inviter'])
            ->orderBy('role')
            ->paginate($request->integer('per_page', 50));

        return PageMemberResource::collection($members);
    }

    /**
     * Invite un utilisateur à rejoindre l'équipe.
     */
    public function invite(InvitePageMemberRequest $request, Page $page): JsonResponse
    {
        Gate::authorize('manageMembers', $page);

        /** @var User $invitee */
        $invitee = User::findOrFail($request->validated('user_id'));
        $role = PageRole::from($request->validated('role'));

        $member = $this->pageMemberService->invite(
            page: $page,
            inviter: $request->user(),
            invitee: $invitee,
            role: $role
        );

        return (new PageMemberResource($member))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Accepte une invitation de membre.
     */
    public function accept(Request $request, PageMember $member): PageMemberResource
    {
        $accepted = $this->pageMemberService->accept($member, $request->user());

        return new PageMemberResource($accepted);
    }

    /**
     * Décline une invitation de membre.
     */
    public function decline(Request $request, PageMember $member): PageMemberResource
    {
        $declined = $this->pageMemberService->decline($member, $request->user());

        return new PageMemberResource($declined);
    }

    /**
     * Modifie le rôle attribué à un membre.
     */
    public function updateRole(UpdatePageMemberRoleRequest $request, Page $page, PageMember $member): PageMemberResource
    {
        Gate::authorize('manageMembers', $page);

        $newRole = PageRole::from($request->validated('role'));

        $updated = $this->pageMemberService->updateRole($member, $newRole, $request->user());

        return new PageMemberResource($updated);
    }

    /**
     * Révoque un membre avec effet immédiat (EDGE-02).
     */
    public function revoke(Request $request, Page $page, PageMember $member): PageMemberResource
    {
        Gate::authorize('manageMembers', $page);

        $revoked = $this->pageMemberService->revoke($member, $request->user());

        return new PageMemberResource($revoked);
    }

    /**
     * Permet au membre connecté de quitter la Page (EDGE-01).
     */
    public function leave(Request $request, Page $page): JsonResponse
    {
        $member = $page->members()
            ->where('user_id', $request->user()->id)
            ->where('status', PageMemberStatus::Accepted->value)
            ->firstOrFail();

        $this->pageMemberService->leave($member, $request->user());

        return response()->json([
            'message' => 'Vous avez quitté l\'équipe de la page avec succès.',
        ]);
    }
}
