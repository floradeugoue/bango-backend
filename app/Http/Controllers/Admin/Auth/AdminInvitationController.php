<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\AdminInvitationAcceptRequest;
use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\AdminInvitation;
use App\Models\Auth\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminInvitationController extends Controller
{
    /**
     * GET /auth/invitations/{token}
     * Retourne l'état minimal de l'invitation sans divulguer de données sensibles.
     */
    public function show(string $token): JsonResponse
    {
        /** @var AdminInvitation|null $invitation */
        $invitation = AdminInvitation::where('token', $token)->first();

        if (! $invitation || ! $invitation->isValid()) {
            return response()->json([
                'message' => 'Cette invitation est invalide, expirée ou a déjà été utilisée.',
                'is_valid' => false,
            ], 404);
        }

        // Masquage de l'adresse email
        $parts = explode('@', $invitation->email);
        $maskedEmail = Str::mask($parts[0], '*', 2, -1).'@'.($parts[1] ?? 'bango.app');

        return response()->json([
            'is_valid' => true,
            'email' => $maskedEmail,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ], 200);
    }

    /**
     * POST /auth/invitations/{token}/accept
     * Active le compte collaborateur de manière atomique avec définition du mot de passe.
     */
    public function accept(AdminInvitationAcceptRequest $request, string $token): JsonResponse
    {
        return DB::transaction(function () use ($request, $token) {
            /** @var AdminInvitation|null $invitation */
            $invitation = AdminInvitation::where('token', $token)->lockForUpdate()->first();

            if (! $invitation || ! $invitation->isValid()) {
                return response()->json([
                    'message' => 'Cette invitation est invalide, expirée ou a déjà été utilisée.',
                ], 400);
            }

            // Récupération du rôle assigné (aucun auto-surclassement possible)
            $role = Role::where('slug', $invitation->role)->first();

            if (! $role) {
                return response()->json([
                    'message' => 'Le rôle assigné par l\'invitation est introuvable.',
                ], 500);
            }

            // Création ou mise à jour de l'opérateur
            $user = User::where('email', $invitation->email)->first();

            if (! $user) {
                $baseHandle = explode('@', $invitation->email)[0];
                $handle = Str::slug($baseHandle);
                if (User::where('handle', $handle)->exists()) {
                    $handle .= '-'.Str::random(4);
                }

                $user = User::create([
                    'name' => $request->input('name') ?? ucfirst($baseHandle),
                    'email' => $invitation->email,
                    'handle' => $handle,
                    'password' => Hash::make((string) $request->input('password')),
                    'role_id' => $role->id,
                    'email_verified_at' => now(),
                    'status' => 'active',
                ]);
            } else {
                $user->update([
                    'password' => Hash::make((string) $request->input('password')),
                    'role_id' => $role->id,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'status' => 'active',
                ]);
            }

            // Consommation atomique de l'invitation (usage unique)
            $invitation->update([
                'accepted_at' => now(),
            ]);

            AdminAuditLog::logEvent('auth.invitation.activated', $user, $request->ip(), $request->userAgent(), [
                'role' => $role->slug,
            ]);

            return response()->json([
                'message' => 'Votre compte opérateur a été activé avec succès. Vous pouvez désormais vous connecter.',
                'operator_id' => $user->id,
                'email' => $user->email,
                'role' => $role->slug,
            ], 200);
        });
    }
}
