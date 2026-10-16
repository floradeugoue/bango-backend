<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\StoreDeviceTokenRequest;
use App\Models\Notification\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /**
     * Enregistre ou met à jour le token push APNs/FCM de l'appareil.
     *
     * POST /api/notifications/device-token
     */
    public function store(StoreDeviceTokenRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $deviceToken = DeviceToken::updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $user->id,
                'platform' => $validated['platform'],
                'device_id' => $validated['device_id'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'last_used_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Token d\'appareil enregistré avec succès.',
            'device_token' => [
                'id' => $deviceToken->id,
                'platform' => $deviceToken->platform,
                'device_id' => $deviceToken->device_id,
                'is_active' => (bool) $deviceToken->is_active,
            ],
        ], 200);
    }

    /**
     * Révoque ou désactive un token push d'appareil (ex: lors de la déconnexion).
     *
     * DELETE /api/notifications/device-token
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $token = DeviceToken::where('token', $validated['token'])
            ->where('user_id', $request->user()->id)
            ->first();

        if ($token) {
            $token->update(['is_active' => false]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Token d\'appareil désactivé.',
        ], 200);
    }
}
