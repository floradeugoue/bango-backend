<?php

namespace App\Http\Controllers\Support;

use App\Enums\Support\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\AssignTicketRequest;
use App\Http\Requests\Support\ReplySupportTicketRequest;
use App\Http\Requests\Support\UpdateTicketStatusRequest;
use App\Http\Resources\Support\SupportTicketMessageResource;
use App\Http\Resources\Support\SupportTicketResource;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AdminSupportTicketController extends Controller
{
    public function __construct(
        protected SupportTicketService $supportTicketService
    ) {}

    /**
     * Liste tous les tickets pour l'équipe support / back-office.
     *
     * GET /api/admin/support/tickets
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        if (! $user?->isSupportAgent()) {
            abort(403, 'Accès réservé aux agents de support.');
        }

        $filters = [
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
            'category' => $request->query('category'),
            'assigned_to' => $request->query('assigned_to'),
        ];
        $perPage = (int) $request->query('per_page', 20);

        $tickets = $this->supportTicketService->listAdminTickets($filters, $perPage);

        return SupportTicketResource::collection($tickets);
    }

    /**
     * Met à jour le statut d'un ticket.
     *
     * PATCH /api/admin/support/tickets/{id}/status
     */
    public function updateStatus(UpdateTicketStatusRequest $request, int|string $id): JsonResponse
    {
        $ticket = SupportTicket::where(function ($query) use ($id) {
            if (is_numeric($id)) {
                $query->where('id', (int) $id)->orWhere('reference', (string) $id);
            } else {
                $query->where('reference', (string) $id);
            }
        })->firstOrFail();

        Gate::authorize('updateStatus', $ticket);

        $status = TicketStatus::from($request->input('status'));
        $ticket = $this->supportTicketService->updateStatus($ticket, $status, $request->user());

        return (new SupportTicketResource($ticket->load(['user', 'assignedAgent'])))
            ->additional(['message' => 'Statut du ticket mis à jour avec succès.'])
            ->response();
    }

    /**
     * Assigne un ticket à un agent support.
     *
     * PATCH /api/admin/support/tickets/{id}/assign
     */
    public function assign(AssignTicketRequest $request, int|string $id): JsonResponse
    {
        $ticket = SupportTicket::where(function ($query) use ($id) {
            if (is_numeric($id)) {
                $query->where('id', (int) $id)->orWhere('reference', (string) $id);
            } else {
                $query->where('reference', (string) $id);
            }
        })->firstOrFail();

        Gate::authorize('assign', $ticket);

        $agentId = $request->input('agent_id');
        $agent = $agentId ? User::find($agentId) : null;

        $ticket = $this->supportTicketService->assignTicket($ticket, $agent);

        return (new SupportTicketResource($ticket->load(['user', 'assignedAgent'])))
            ->additional(['message' => 'Assignation du ticket mise à jour.'])
            ->response();
    }

    /**
     * Répond au ticket en tant qu'agent ou ajoute une note interne.
     *
     * POST /api/admin/support/tickets/{id}/messages
     */
    public function reply(ReplySupportTicketRequest $request, int|string $id): JsonResponse
    {
        $ticket = SupportTicket::where(function ($query) use ($id) {
            if (is_numeric($id)) {
                $query->where('id', (int) $id)->orWhere('reference', (string) $id);
            } else {
                $query->where('reference', (string) $id);
            }
        })->firstOrFail();

        $isInternal = (bool) $request->input('is_internal', false);

        if ($isInternal) {
            Gate::authorize('addInternalNote', $ticket);
        } else {
            Gate::authorize('reply', $ticket);
        }

        $validated = $request->validated();
        $attachments = $request->file('attachments', []);

        $message = $this->supportTicketService->replyTicket(
            $ticket,
            $request->user(),
            $validated['message'],
            $attachments,
            $isInternal
        );

        $actionText = $isInternal ? 'Note interne enregistrée.' : 'Réponse du support envoyée avec succès.';

        return (new SupportTicketMessageResource($message))
            ->additional(['message' => $actionText])
            ->response()
            ->setStatusCode(201);
    }
}
