<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QrController extends Controller
{
    /**
     * Resolve a QR code payload or profile link into a user profile.
     */
    public function resolve(Request $request): JsonResponse
    {
        $request->validate([
            'data' => ['required', 'string'],
        ]);

        $data = trim($request->input('data'));

        // Match bango://u/<handle> or bango.app/@<handle> or plain handle
        $handle = null;
        if (preg_match('/^bango:\/\/u\/([\w.%-]+)$/i', $data, $matches)) {
            $handle = urldecode($matches[1]);
        } elseif (preg_match('/bango\.app\/@([\w.]+)/i', $data, $matches)) {
            $handle = urldecode($matches[1]);
        } else {
            $handle = ltrim($data, '@');
        }

        $user = User::withTrashed()
            ->where('handle', strtolower($handle))
            ->first();

        if (! $user || $user->trashed()) {
            return response()->json([
                'kind' => 'not-found',
                'message' => 'Ce compte n\'existe plus ou le QR code est invalide.',
            ], 404);
        }

        if ($user->status === 'blocked') {
            return response()->json(['kind' => 'blocked'], 403);
        }

        if ($user->status === 'suspended') {
            return response()->json(['kind' => 'suspended'], 403);
        }

        return response()->json([
            'id' => $user->id,
            'handle' => $user->handle,
            'displayName' => $user->display_name ?? $user->name,
            'avatarUrl' => $user->avatar_url,
            'badge' => $user->badge ?? 'none',
            'verified' => (bool) ($user->badge && $user->badge !== 'none' && ! $user->verification_lapsed),
            'profileLink' => 'bango://u/'.$user->handle,
        ], 200);
    }
}
