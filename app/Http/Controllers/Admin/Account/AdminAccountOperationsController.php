<?php

namespace App\Http\Controllers\Admin\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Account\AdminAccountContactRequest;
use App\Models\User;
use App\Services\Admin\Account\AdminAccountOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAccountOperationsController extends Controller
{
    public function __construct(
        protected AdminAccountOperationsService $accountService
    ) {}

    /**
     * 5.1. Annuaire paginé des comptes utilisateurs.
     *
     * GET /api/admin/accounts
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search',
            'status',
            'is_verified',
            'is_certified',
            'badge',
            'sort',
            'order',
        ]);

        $perPage = (int) $request->query('per_page', 20);
        $accounts = $this->accountService->getAccounts($filters, $perPage);

        return response()->json($accounts);
    }

    /**
     * 5.1 bis. Fiche détaillée d'un compte utilisateur.
     *
     * GET /api/admin/accounts/{id}
     */
    public function show(int|string $id): JsonResponse
    {
        $account = $this->accountService->getAccountDetails($id);

        return response()->json([
            'success' => true,
            'account' => $account,
        ]);
    }

    /**
     * 5.2. Annuaire de l'équipe interne et rôles opérationnels.
     *
     * GET /api/admin/team
     */
    public function team(): JsonResponse
    {
        $team = $this->accountService->getTeamMembers();

        return response()->json([
            'success' => true,
            'data' => $team,
        ]);
    }

    /**
     * 5.3. Sous-onglet : Annonces immobilières de l'utilisateur.
     *
     * GET /api/admin/accounts/{id}/listings
     */
    public function listings(int|string $id, Request $request): JsonResponse
    {
        $user = User::findOrFail($id);
        $perPage = (int) $request->query('per_page', 15);
        $listings = $this->accountService->getUserListings($user, $perPage);

        return response()->json($listings);
    }

    /**
     * 5.3 bis. Sous-onglet : Contenus publiés (Square posts & Reels).
     *
     * GET /api/admin/accounts/{id}/contents
     */
    public function contents(int|string $id, Request $request): JsonResponse
    {
        $user = User::findOrFail($id);
        $perPage = (int) $request->query('per_page', 10);
        $contents = $this->accountService->getUserContents($user, $perPage);

        return response()->json([
            'success' => true,
            'data' => $contents,
        ]);
    }

    /**
     * 5.3 ter. Sous-onglet : Transactions financières du compte.
     *
     * GET /api/admin/accounts/{id}/payments
     */
    public function payments(int|string $id, Request $request): JsonResponse
    {
        $user = User::findOrFail($id);
        $perPage = (int) $request->query('per_page', 15);
        $payments = $this->accountService->getUserPayments($user, $perPage);

        return response()->json($payments);
    }

    /**
     * 5.3 quater. Sous-onglet : Dossiers et signalements de modération.
     *
     * GET /api/admin/accounts/{id}/cases
     */
    public function cases(int|string $id, Request $request): JsonResponse
    {
        $user = User::findOrFail($id);
        $perPage = (int) $request->query('per_page', 15);
        $cases = $this->accountService->getUserCases($user, $perPage);

        return response()->json($cases);
    }

    /**
     * 5.3 quinquies. Sous-onglet : Tickets de support du compte.
     *
     * GET /api/admin/accounts/{id}/tickets
     */
    public function tickets(int|string $id, Request $request): JsonResponse
    {
        $user = User::findOrFail($id);
        $perPage = (int) $request->query('per_page', 15);
        $tickets = $this->accountService->getUserTickets($user, $perPage);

        return response()->json($tickets);
    }

    /**
     * 5.4. Chronologie unifiée des événements du compte.
     *
     * GET /api/admin/accounts/{id}/timeline
     */
    public function timeline(int|string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $timeline = $this->accountService->getUserTimeline($user);

        return response()->json([
            'success' => true,
            'data' => $timeline,
        ]);
    }

    /**
     * 4.9. Synthèse utilisateur 360° pour le panneau d'assistance support.
     *
     * GET /api/admin/accounts/{id}/support-summary
     */
    public function supportSummary(int|string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $summary = $this->accountService->getSupportSummary($user);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * 5.5. Prévisualisation de l'impact d'une sanction avant exécution.
     *
     * GET /api/admin/accounts/{id}/actions/{kind}/preview
     */
    public function previewAction(int|string $id, string $kind): JsonResponse
    {
        $user = User::findOrFail($id);
        $simulation = $this->accountService->previewAction($user, $kind);

        return response()->json([
            'success' => true,
            'data' => $simulation,
        ]);
    }

    /**
     * 5.10. Contact direct de l'utilisateur (In-App, SMS, Ticket).
     *
     * POST /api/admin/accounts/{id}/contact
     */
    public function contact(AdminAccountContactRequest $request, int|string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $operator = $request->user();

        $result = $this->accountService->contactUser(
            user: $user,
            operator: $operator,
            channel: $request->input('channel'),
            subject: $request->input('subject'),
            message: $request->input('message')
        );

        return response()->json([
            'success' => true,
            'message' => 'Message transmis avec succès.',
            'data' => $result,
        ]);
    }
}
