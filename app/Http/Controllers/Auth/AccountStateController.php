<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\SignedOutAccountVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountStateController extends Controller
{
    public function __construct(
        protected SignedOutAccountVerifier $verifier
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->verifier->verify($request->identifier, $request->password, $request->ip());

        $suspension = null;
        if ($user->status === 'suspended') {
            $sanction = $this->verifier->activeSuspension($user);

            $suspension = [
                'id' => $sanction?->id,
                'reason' => $user->suspension_reason ?? 'Non respect des conditions d\'utilisation.',
                'from' => $user->suspended_from?->toIso8601String() ?? $user->updated_at?->toIso8601String(),
                'until' => $user->suspended_until?->toIso8601String(),
            ];
        }

        $deletion = null;
        if ($user->trashed() || $user->scheduled_for_deletion_at) {
            $requestedAt = $user->deleted_at ?? ($user->scheduled_for_deletion_at ? $user->scheduled_for_deletion_at->copy()->subDays(30) : now());
            $deletion = [
                'requestedAt' => $requestedAt->toIso8601String(),
                'effectiveAt' => $user->scheduled_for_deletion_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'displayName' => $user->display_name ?? $user->name,
            'handle' => $user->handle,
            'suspension' => $suspension,
            'deletion' => $deletion,
        ], 200);
    }
}
