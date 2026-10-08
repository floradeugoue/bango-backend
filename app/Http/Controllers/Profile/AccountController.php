<?php
namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    public function pause(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['kind' => 'bad-password'], 403);
        }

        $user->update(['status' => 'paused']);
        $user->tokens()->delete(); // Sign out entirely

        return response()->json(['success' => true]);
    }

    /**
     * Supprimer définitivement le compte
     * 
     * @group Settings - Profil
     * @bodyParam password string required Le mot de passe actuel.
     * @bodyParam confirmation string required Doit être exactement "SUPPRIMER".
     */
    public function delete(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
            'confirmation' => 'required|string',
        ]);

        $user = $request->user();

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['kind' => 'bad-password'], 403);
        }

        if ($request->confirmation !== 'SUPPRIMER') {
            return response()->json(['kind' => 'bad-confirmation'], 400);
        }

        // Set deletion date and save before soft deleting (or update directly)
        $user->scheduled_for_deletion_at = now()->addDays(30);
        $user->save();

        // Soft deletes the user
        $user->delete();
        $user->tokens()->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Restaurer un compte supprimé
     * 
     * @group Settings - Profil
     * @bodyParam identifier string required Email ou téléphone.
     * @bodyParam password string required Mot de passe.
     */
    public function restore(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = \App\Models\User::withTrashed()
                    ->where('email', $request->identifier)
                    ->orWhere('phone', $request->identifier)
                    ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['kind' => 'invalid-credentials'], 401);
        }

        if (!$user->trashed()) {
            return response()->json(['kind' => 'not-deleted'], 400);
        }

        if ($user->scheduled_for_deletion_at && $user->scheduled_for_deletion_at->isPast()) {
            return response()->json(['kind' => 'permanently-deleted'], 403);
        }

        $user->restore();
        $user->scheduled_for_deletion_at = null;
        $user->save();

        return response()->json(['success' => true]);
    }
}
