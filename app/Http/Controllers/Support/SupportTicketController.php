<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\ReplySupportTicketRequest;
use App\Http\Requests\Support\StoreSupportTicketRequest;
use App\Http\Resources\Support\SupportTicketMessageResource;
use App\Http\Resources\Support\SupportTicketResource;
use App\Models\Support\SupportTicket;
use App\Models\Support\SupportTicketAttachment;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportTicketController extends Controller
{
    public function __construct(
        protected SupportTicketService $supportTicketService
    ) {}

    /**
     * Crée un nouveau ticket de support avec message initial et pièces jointes.
     *
     * POST /api/support/tickets
     */
    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $attachments = $request->file('attachments', []);

        $ticket = $this->supportTicketService->createTicket($user, $validated, $attachments);

        return (new SupportTicketResource($ticket))
            ->additional(['message' => 'Votre ticket de support a été créé avec succès.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Liste paginée des tickets de l'utilisateur connecté.
     *
     * GET /api/support/tickets/mine
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $filters = [
            'status' => $request->query('status'),
        ];
        $perPage = (int) $request->query('per_page', 15);

        $tickets = $this->supportTicketService->listUserTickets($user, $filters, $perPage);

        return SupportTicketResource::collection($tickets);
    }

    /**
     * Affiche le détail d'un ticket et son fil d'échanges autorisé.
     *
     * GET /api/support/tickets/{id}
     */
    public function show(Request $request, int|string $id): SupportTicketResource
    {
        $ticket = SupportTicket::with(['user', 'assignedAgent'])
            ->where(function ($query) use ($id) {
                if (is_numeric($id)) {
                    $query->where('id', (int) $id)->orWhere('reference', (string) $id);
                } else {
                    $query->where('reference', (string) $id);
                }
            })
            ->firstOrFail();

        Gate::authorize('view', $ticket);

        $messages = $this->supportTicketService->getAuthorizedMessages($ticket, $request->user());
        $ticket->setRelation('messages', $messages);

        return new SupportTicketResource($ticket);
    }

    /**
     * Ajoute une réponse à un ticket existant.
     *
     * POST /api/support/tickets/{id}/messages
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

        Gate::authorize('reply', $ticket);

        $validated = $request->validated();
        $attachments = $request->file('attachments', []);

        try {
            $message = $this->supportTicketService->replyTicket(
                $ticket,
                $request->user(),
                $validated['message'],
                $attachments,
                false // Client regular reply, never internal note
            );
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return (new SupportTicketMessageResource($message))
            ->additional(['message' => 'Votre réponse a été envoyée avec succès.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Télécharge une pièce jointe de façon sécurisée après vérification des droits.
     *
     * GET /api/support/tickets/{ticketId}/attachments/{attachmentId}
     */
    public function downloadAttachment(
        Request $request,
        int|string $ticketId,
        int|string $attachmentId
    ): StreamedResponse|JsonResponse {
        $ticket = SupportTicket::where(function ($query) use ($ticketId) {
            if (is_numeric($ticketId)) {
                $query->where('id', (int) $ticketId)->orWhere('reference', (string) $ticketId);
            } else {
                $query->where('reference', (string) $ticketId);
            }
        })->firstOrFail();

        $attachment = SupportTicketAttachment::with(['message', 'ticket'])
            ->where('id', $attachmentId)
            ->where('support_ticket_id', $ticket->id)
            ->firstOrFail();

        Gate::authorize('viewAttachment', $attachment);

        if (! Storage::disk($attachment->disk)->exists($attachment->file_path)) {
            return response()->json([
                'message' => 'Le fichier demandé est introuvable.',
            ], 404);
        }

        return Storage::disk($attachment->disk)->download(
            $attachment->file_path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type]
        );
    }
}
