<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SignedOutSupportTicketRequest;
use App\Services\Auth\SignedOutAccountVerifier;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\JsonResponse;

class SignedOutSupportTicketController extends Controller
{
    public function __construct(
        protected SignedOutAccountVerifier $verifier,
        protected SupportTicketService $supportTicketService
    ) {}

    /**
     * Ticket de support envoyé sans session par un compte suspendu ou supprimé (écrans AU-26 / AU-27).
     *
     * POST /api/auth/support-ticket
     */
    public function __invoke(SignedOutSupportTicketRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $this->verifier->verify($validated['identifier'], $validated['password'], $request->ip());

        $isLockedOut = $user->isSuspended() || $user->trashed() || $user->scheduled_for_deletion_at !== null;

        if (! $isLockedOut) {
            return response()->json([
                'kind' => 'active-account',
                'message' => 'Votre compte est actif : connectez-vous pour contacter le support.',
            ], 403);
        }

        $this->verifier->ensureCanSubmit($user, 'support-ticket');

        $ticket = $this->supportTicketService->createTicket($user, [
            'subject' => $validated['subject'],
            'category' => $validated['topic'],
            'message' => $validated['body'],
        ]);

        return response()->json([
            'id' => (int) $ticket->id,
            'reference' => $ticket->reference,
            'created_at' => $ticket->created_at?->toIso8601String(),
        ], 201);
    }
}
