<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AccountStateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::withTrashed()
            ->where('email', $request->identifier)
            ->orWhere('phone', $request->identifier)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['kind' => 'invalid-credentials'], 401);
        }

        $suspension = null;
        if ($user->status === 'suspended') {
            $suspension = [
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
