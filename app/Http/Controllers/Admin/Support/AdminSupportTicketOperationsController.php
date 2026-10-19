<?php

namespace App\Http\Controllers\Admin\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Support\AdminTicketAssignRequest;
use App\Http\Requests\Admin\Support\AdminTicketEscalateRequest;
use App\Http\Requests\Admin\Support\AdminTicketEscalationAnswerRequest;
use App\Http\Requests\Admin\Support\AdminTicketInternalNoteRequest;
use App\Http\Requests\Admin\Support\AdminTicketManualCreateRequest;
use App\Http\Requests\Admin\Support\AdminTicketReopenRequest;
use App\Http\Requests\Admin\Support\AdminTicketReplyRequest;
use App\Http\Requests\Admin\Support\AdminTicketResolveRequest;
use App\Models\Support\SupportTicket;
use App\Services\Admin\Support\AdminSupportOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminSupportTicketOperationsController extends Controller
{
    public function __construct(
        protected AdminSupportOperationsService $supportService
    ) {}

    /**
     * 4.1. Liste paginée des tickets de support.
     *
     * GET /api/admin/support/tickets
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'status',
            'priority',
            'category',
            'assigned_to',
            'search',
            'overdue',
            'sort',
            'order',
        ]);

        $perPage = (int) $request->query('per_page', 20);
        $tickets = $this->supportService->getTickets($filters, $perPage);

        return response()->json($tickets);
    }

    /**
     * 4.1 bis. Fiche détaillée d'un ticket.
     *
     * GET /api/admin/support/tickets/{id}
     */
    public function show(int|string $id): JsonResponse
    {
        $ticket = $this->supportService->getTicketDetails($id);

        return response()->json([
            'success' => true,
            'ticket' => $ticket,
        ]);
    }

    /**
     * 4.2. Réponse publique d'un agent au demandeur.
     *
     * POST /api/admin/support/tickets/{id}/replies
     */
    public function reply(AdminTicketReplyRequest $request, int|string $id): JsonResponse
    {
        $ticket = $this->resolveTicketModel($id);
        $operator = $request->user();

        $message = $this->supportService->replyToTicket(
            ticket: $ticket,
            operator: $operator,
            messageText: $request->input('message'),
            templateId: $request->input('template_id'),
            attachments: $request->file('attachments', [])
        );

        return response()->json([
            'success' => true,
            'message' => 'Réponse envoyée avec succès au demandeur.',
            'data' => $message,
        ], 201);
    }

    /**
     * 4.3. Ajout d'une note interne confidentielle (rétention 5 ans).
     *
     * POST /api/admin/support/tickets/{id}/notes
     */
    public function note(AdminTicketInternalNoteRequest $request, int|string $id): JsonResponse
    {
        $ticket = $this->resolveTicketModel($id);
        $operator = $request->user();

        $note = $this->supportService->addInternalNote(
            ticket: $ticket,
            operator: $operator,
            noteText: $request->input('message'),
            attachments: $request->file('attachments', [])
        );

        return response()->json([
            'success' => true,
            'message' => 'Note interne confidentielle enregistrée.',
            'data' => $note,
        ], 201);
    }

    /**
     * 4.4. Assignation ou réassignation unitaire et groupée.
     *
     * POST /api/admin/support/tickets/assign
     */
    public function assign(AdminTicketAssignRequest $request): JsonResponse
    {
        $ticketIds = (array) ($request->input('ticket_ids') ?? [$request->input('ticket_id')]);
        $ticketIds = array_values(array_filter($ticketIds));

        $agentId = (int) $request->input('agent_id');
        $operator = $request->user();
        $reason = $request->input('reason');

        $result = $this->supportService->assignTickets($ticketIds, $agentId, $operator, $reason);

        return response()->json([
            'success' => true,
            'message' => 'Assignation mise à jour avec succès.',
            'data' => $result,
        ]);
    }

    /**
     * 4.5. Résolution formelle d'un ticket avec motif et catégorie.
     *
     * POST /api/admin/support/tickets/{id}/resolve
     */
    public function resolve(AdminTicketResolveRequest $request, int|string $id): JsonResponse
    {
        $ticket = $this->resolveTicketModel($id);
        $operator = $request->user();

        $resolved = $this->supportService->resolveTicket(
            ticket: $ticket,
            operator: $operator,
            category: $request->input('category'),
            reason: $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Ticket résolu avec succès.',
            'data' => $resolved,
        ]);
    }

    /**
     * 4.5 bis. Réouverture d'un ticket résolu (fenêtre 7 jours).
     *
     * POST /api/admin/support/tickets/{id}/reopen
     */
    public function reopen(AdminTicketReopenRequest $request, int|string $id): JsonResponse
    {
        $ticket = $this->resolveTicketModel($id);
        $operator = $request->user();

        $reopened = $this->supportService->reopenTicket(
            ticket: $ticket,
            operator: $operator,
            reason: $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Ticket réouvert avec succès.',
            'data' => $reopened,
        ]);
    }

    /**
     * 4.6. Escalade pour avis à une autre équipe ou un superviseur.
     *
     * POST /api/admin/support/tickets/{id}/escalate
     */
    public function escalate(AdminTicketEscalateRequest $request, int|string $id): JsonResponse
    {
        $ticket = $this->resolveTicketModel($id);
        $operator = $request->user();

        $escalation = $this->supportService->escalateTicket(
            ticket: $ticket,
            operator: $operator,
            targetTeam: $request->input('target_team'),
            targetUserId: $request->input('target_user_id'),
            reason: $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Demande d\'avis escaladée avec succès.',
            'data' => $escalation,
        ], 201);
    }

    /**
     * 4.6 bis. Réponse à une demande d'escalade d'avis.
     *
     * POST /api/admin/support/escalation/answer
     */
    public function answerEscalation(AdminTicketEscalationAnswerRequest $request): JsonResponse
    {
        $operator = $request->user();
        $escalationId = (int) $request->input('escalation_id');
        $response = $request->input('response');

        $result = $this->supportService->answerEscalation($escalationId, $operator, $response);

        return response()->json([
            'success' => true,
            'message' => 'Réponse à la demande d\'avis enregistrée.',
            'data' => $result,
        ]);
    }

    /**
     * 4.7. Création manuelle d'un ticket par un opérateur (téléphone, email, etc.).
     *
     * POST /api/admin/support/tickets
     */
    public function store(AdminTicketManualCreateRequest $request): JsonResponse
    {
        $operator = $request->user();
        $ticket = $this->supportService->createManualTicket($operator, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Ticket créé manuellement avec succès.',
            'data' => $ticket,
        ], 201);
    }

    /**
     * 4.8. Opérateurs disponibles pour l'assignation.
     *
     * GET /api/admin/support/assignees
     */
    public function assignees(): JsonResponse
    {
        $assignees = $this->supportService->getAssignees();

        return response()->json([
            'success' => true,
            'data' => $assignees,
        ]);
    }

    /**
     * 4.8 bis. Équipes et configuration par défaut pour l'escalade d'avis.
     *
     * GET /api/admin/support/escalation/default
     */
    public function defaultEscalation(): JsonResponse
    {
        $config = $this->supportService->getDefaultEscalation();

        return response()->json([
            'success' => true,
            'data' => $config,
        ]);
    }

    /**
     * 4.8 ter. Statistiques et parts de résolution réelles.
     *
     * GET /api/admin/support/resolutions/shares
     */
    public function resolutionsShares(Request $request): JsonResponse
    {
        $filters = $request->only(['start_date', 'end_date']);
        $stats = $this->supportService->getResolutionShares($filters);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * 4.12. Export CSV des tickets de support.
     *
     * POST /api/admin/support/exports
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $request->only(['status', 'category']);
        $operator = $request->user();

        return $this->supportService->exportTicketsCsv($filters, $operator);
    }

    /**
     * Résout l'instance du modèle SupportTicket par ID ou référence.
     */
    protected function resolveTicketModel(int|string $id): SupportTicket
    {
        return SupportTicket::where(function ($query) use ($id) {
            if (is_numeric($id)) {
                $query->where('id', (int) $id)->orWhere('reference', (string) $id);
            } else {
                $query->where('reference', (string) $id);
            }
        })->firstOrFail();
    }
}
