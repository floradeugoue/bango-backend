<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Auth\Otp;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class EmailVerificationController extends Controller
{
    /**
     * Confirme l'adresse e-mail via le lien signé envoyé par e-mail.
     * GET /api/auth/email/verify/{id}/{hash}
     */
    public function verify(Request $request, string|int $id, string $hash): JsonResponse|Response
    {
        if (! $request->hasValidSignature()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le lien de confirmation est invalide ou a expiré.',
                ], 403);
            }

            return response('<h1>Lien invalide ou expiré</h1><p>Ce lien de confirmation n\'est plus valide.</p>', 403);
        }

        $user = User::findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le lien de confirmation ne correspond pas à cette adresse e-mail.',
                ], 403);
            }

            return response('<h1>Lien non valide</h1>', 403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $locale = $user->locale ?? 'fr';
        $msg = $locale === 'en'
            ? 'Your email address has been successfully confirmed.'
            : 'Votre adresse e-mail a été confirmée avec succès.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $msg,
                'email_verified' => true,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            ]);
        }

        $title = $locale === 'en' ? 'Email Confirmed' : 'E-mail confirmé';
        $sub = $locale === 'en' ? 'You can now return to the BANGO app.' : 'Vous pouvez à présent retourner sur l\'application BANGO.';

        return response("<!DOCTYPE html><html><head><meta charset='utf-8'><title>{$title}</title><style>body{font-family:sans-serif;text-align:center;padding:50px;background:#f8fafc;}h1{color:#059669;}</style></head><body><h1>{$title}</h1><p>{$msg}</p><p>{$sub}</p></body></html>", 200);
    }

    /**
     * Confirme l'adresse e-mail en validant le code OTP à 6 chiffres.
     * POST /api/auth/email/verify
     */
    public function verifyWithCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:6'],
            'email' => ['nullable', 'email'],
        ]);

        /** @var User|null $user */
        $user = $request->user('api') ?: $request->user() ?: ($validated['email'] ?? null ? User::where('email', $validated['email'])->first() : null);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable. Veuillez vous connecter ou renseigner votre adresse e-mail.',
            ], 404);
        }

        if ($user->hasVerifiedEmail()) {
            $locale = $user->locale ?? 'fr';

            return response()->json([
                'success' => true,
                'message' => $locale === 'en' ? 'Your email address is already verified.' : 'Votre adresse e-mail est déjà confirmée.',
                'email_verified' => true,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            ]);
        }

        $code = trim($validated['code']);

        $otp = Otp::where('identifier', $user->email)
            ->where('channel', 'email')
            ->where('context', 'email_verification')
            ->first();

        if (! $otp) {
            throw ValidationException::withMessages([
                'code' => ['Aucun code de confirmation en attente pour cette adresse e-mail.'],
            ]);
        }

        if ($otp->locked_until && $otp->locked_until->isFuture()) {
            $minutes = ceil(now()->diffInMinutes($otp->locked_until));
            throw ValidationException::withMessages([
                'code' => ["Trop de tentatives erronées. Veuillez réessayer dans {$minutes} minutes."],
            ]);
        }

        if ($otp->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'code' => ['Le code de confirmation a expiré. Veuillez demander un nouvel e-mail.'],
            ]);
        }

        $isValid = ($otp->code === $code)
            || ((str_starts_with($otp->code, '$2y$') || str_starts_with($otp->code, '$2a$') || str_starts_with($otp->code, '$argon2')) && Hash::check($code, $otp->code));

        if (! $isValid) {
            $otp->increment('attempts');
            if ($otp->attempts >= 5) {
                $otp->update(['locked_until' => now()->addMinutes(15)]);
            }

            throw ValidationException::withMessages([
                'code' => ['Le code de confirmation saisi est incorrect.'],
            ]);
        }

        $otp->delete();
        $user->markEmailAsVerified();

        $locale = $user->locale ?? 'fr';

        return response()->json([
            'success' => true,
            'message' => $locale === 'en' ? 'Your email address has been successfully confirmed.' : 'Votre adresse e-mail a été confirmée avec succès.',
            'email_verified' => true,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
        ]);
    }

    /**
     * Renvoie l'e-mail de confirmation d'adresse.
     * POST /api/auth/email/resend
     */
    public function resend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'email'],
        ]);

        /** @var User|null $user */
        $user = $request->user('api') ?: $request->user() ?: ($validated['email'] ?? null ? User::where('email', $validated['email'])->first() : null);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable.',
            ], 404);
        }

        $locale = $user->locale ?? 'fr';

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'message' => $locale === 'en' ? 'Your email address is already verified.' : 'Votre adresse e-mail est déjà confirmée.',
                'email_verified' => true,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            ]);
        }

        $throttleKey = 'email_verification_resend:'.$user->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $waitMsg = $locale === 'en'
                ? "Please wait {$seconds} seconds before requesting a new verification email."
                : "Veuillez patienter {$seconds} secondes avant de demander un nouvel envoi.";

            return response()->json([
                'success' => false,
                'message' => $waitMsg,
            ], 429);
        }

        RateLimiter::hit($throttleKey, 60);
        $userToNotify = $user;

        dispatch(function () use ($userToNotify) {
            try {
                $userToNotify->sendWelcomeEmailVerificationNotification();
            } catch (\Throwable $e) {
                Log::warning('Échec renvoi e-mail de confirmation : '.$e->getMessage());
            }
        })->afterResponse();

        $sentMsg = $locale === 'en'
            ? 'A new confirmation email has been sent.'
            : 'Un nouvel e-mail de confirmation vous a été envoyé.';

        return response()->json([
            'success' => true,
            'message' => $sentMsg,
        ]);
    }
}
