<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Support\AppealStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SignedOutAppealRequest;
use App\Models\Support\ModerationAppeal;
use App\Services\Auth\SignedOutAccountVerifier;
use Illuminate\Http\JsonResponse;

class SignedOutAppealController extends Controller
{
    public function __construct(
        protected SignedOutAccountVerifier $verifier
    ) {}

    /**
     * Recours contre une suspension de compte, déposé sans session (écran AU-26).
     *
     * POST /api/auth/appeal
     */
    public function __invoke(SignedOutAppealRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $this->verifier->verify($validated['identifier'], $validated['password'], $request->ip());

        if (! $user->isSuspended()) {
            return response()->json([
                'kind' => 'not-suspended',
                'message' => 'Votre compte ne fait l\'objet d\'aucune suspension active.',
            ], 422);
        }

        $pendingAppeal = ModerationAppeal::where('user_id', $user->id)
            ->where('sanction_type', 'account_suspension')
            ->where('status', AppealStatus::Pending->value)
            ->first();

        if ($pendingAppeal) {
            return response()->json([
                'kind' => 'already-pending',
                'message' => 'Un recours pour cette suspension est déjà en cours d\'instruction.',
                ...$this->summary($pendingAppeal),
            ], 409);
        }

        $this->verifier->ensureCanSubmit($user, 'appeal');

        $sanction = $this->verifier->activeSuspension($user);

        $appeal = ModerationAppeal::create([
            'user_id' => $user->id,
            'sanction_operator_id' => $sanction?->operator_id,
            'sanction_type' => 'account_suspension',
            'sanction_reference' => $sanction ? (string) $sanction->id : null,
            'reason' => $validated['reason'],
            'explanation' => $validated['explanation'],
            'contact_email' => $user->email,
            'status' => AppealStatus::Pending,
        ]);

        return response()->json($this->summary($appeal), 201);
    }

    /**
     * @return array{id: int, reference: string|null, created_at: string|null}
     */
    private function summary(ModerationAppeal $appeal): array
    {
        return [
            'id' => (int) $appeal->id,
            'reference' => $appeal->reference,
            'created_at' => $appeal->created_at?->toIso8601String(),
        ];
    }
}
