<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Profile\MutedUserResource;
use App\Models\Profile\UserMute;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MuteController extends Controller
{
    /**
     * Liste des comptes masqués par l'utilisateur connecté (GET /profile/muted).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $perPage = max(1, min(50, $request->integer('per_page', 20)));

        $mutes = $user->mutes()
            ->active()
            ->with('mutedUser')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return MutedUserResource::collection($mutes);
    }

    /**
     * Masquer un compte utilisateur (POST /users/{id}/mute).
     * Body optionnel : {"days": 30}. Si days absent ou null : masqué jusqu'à levée manuelle.
     */
    public function mute(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var User $target */
        $target = User::findOrFail($id);

        if ((int) $user->id === (int) $target->id) {
            return response()->json(['message' => 'Vous ne pouvez pas vous masquer vous-même.'], 422);
        }

        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = $validated['days'] ?? null;
        $expiresAt = $days !== null ? Carbon::now()->addDays((int) $days) : null;

        $mute = UserMute::updateOrCreate(
            [
                'user_id' => $user->id,
                'muted_user_id' => $target->id,
            ],
            [
                'expires_at' => $expiresAt,
            ]
        );

        return response()->json([
            'success' => true,
            'muted' => true,
            'expires_at' => $mute->expires_at?->toISOString(),
            'days' => $days,
        ]);
    }

    /**
     * Retirer le masquage d'un compte utilisateur (DELETE /users/{id}/mute).
     */
    public function unmute(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        UserMute::where('user_id', $user->id)
            ->where('muted_user_id', $id)
            ->delete();

        return response()->json([
            'success' => true,
            'muted' => false,
        ]);
    }
}
