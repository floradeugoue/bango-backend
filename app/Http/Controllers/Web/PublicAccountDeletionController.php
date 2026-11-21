<?php

namespace App\Http\Controllers\Web;

use App\Enums\OtpChannel;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\Auth\AccountDeletionRequestedNotification;
use App\Services\Boost\CreditWalletService;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class PublicAccountDeletionController extends Controller
{
    public function __construct(
        protected OtpService $otpService
    ) {}

    /**
     * Affiche la page web publique de suppression de compte (FR ou EN).
     */
    public function show(Request $request, string $locale = 'fr'): View
    {
        $locale = in_array(strtolower($locale), ['en', 'english'], true) ? 'en' : 'fr';

        return view('pages.delete-account', [
            'locale' => $locale,
            'identifier' => old('identifier', $request->query('identifier', '')),
        ]);
    }

    /**
     * Envoie un code OTP par email ou SMS pour valider l'identité sans mot de passe.
     */
    public function requestOtp(Request $request, string $locale = 'fr'): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'locale' => ['nullable', 'string', 'in:fr,en'],
        ]);

        $locale = $validated['locale'] ?? ($request->route('locale') ?: $locale);
        $rawIdentifier = trim($validated['identifier']);

        $throttleKey = 'web.account.delete.otp:'.request()->ip().':'.$rawIdentifier;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $msg = $locale === 'en'
                ? "Too many code requests. Please wait {$seconds} seconds."
                : "Trop de demandes de code. Veuillez patienter {$seconds} secondes.";

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 429);
            }

            return back()->withInput()->with('error', $msg);
        }

        RateLimiter::hit($throttleKey, 300);

        $user = $this->findUserByIdentifier($rawIdentifier);

        // Si l'utilisateur existe, on envoie le code au bon canal
        if ($user) {
            $isEmail = filter_var($rawIdentifier, FILTER_VALIDATE_EMAIL) !== false;
            $channel = $isEmail ? OtpChannel::Email : OtpChannel::Sms;
            $target = $isEmail ? ($user->email ?? $rawIdentifier) : ($user->phone ?? $rawIdentifier);

            $this->otpService->sendOtp(
                identifier: $target,
                channel: $channel,
                context: 'account_deletion',
                ttlMinutes: 10
            );
        }

        $successMsg = $locale === 'en'
            ? 'If an account matches this identifier, a 6-digit confirmation code has been sent (valid 10 minutes).'
            : 'Si un compte correspond à cet identifiant, un code à 6 chiffres a été envoyé (valable 10 minutes).';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $successMsg,
                'identifier' => $rawIdentifier,
            ]);
        }

        return back()
            ->withInput()
            ->with('status', 'otp_sent')
            ->with('message', $successMsg);
    }

    /**
     * Traite la demande de suppression du compte (délai de 30 jours, même règle que l'application).
     */
    public function deleteAccount(Request $request, string $locale = 'fr'): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'auth_method' => ['required', 'string', 'in:password,otp'],
            'password' => ['nullable', 'string', 'required_if:auth_method,password'],
            'otp_code' => ['nullable', 'string', 'required_if:auth_method,otp'],
            'confirm_delete' => ['required', 'accepted'],
            'locale' => ['nullable', 'string', 'in:fr,en'],
        ]);

        $locale = $validated['locale'] ?? ($request->route('locale') ?: $locale);
        $identifier = trim($validated['identifier']);
        $authMethod = $validated['auth_method'];

        $user = $this->findUserByIdentifier($identifier);

        if (! $user) {
            $error = $locale === 'en'
                ? 'No account was found with this email or phone number.'
                : 'Aucun compte n\'a été trouvé avec cet e-mail ou ce numéro de téléphone.';

            return $this->errorResponse($request, $error);
        }

        // Vérification de l'authentification
        if ($authMethod === 'password') {
            if (! $user->password || ! Hash::check($request->input('password'), $user->password)) {
                $error = $locale === 'en'
                    ? 'The password entered is incorrect.'
                    : 'Le mot de passe saisi est incorrect.';

                return $this->errorResponse($request, $error);
            }
        } else {
            $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
            $channel = $isEmail ? OtpChannel::Email : OtpChannel::Sms;
            $target = $isEmail ? ($user->email ?? $identifier) : ($user->phone ?? $identifier);

            $verified = $this->otpService->verifyOtp(
                identifier: $target,
                code: trim((string) $request->input('otp_code')),
                channel: $channel,
                context: 'account_deletion'
            );

            if ($verified !== true) {
                $error = $locale === 'en'
                    ? 'The verification code is invalid or has expired.'
                    : 'Le code de vérification est incorrect ou a expiré.';

                return $this->errorResponse($request, $error);
            }
        }

        // Si le compte est déjà programmé pour suppression
        if ($user->trashed() && $user->scheduled_for_deletion_at && $user->scheduled_for_deletion_at->isFuture()) {
            $date = $user->scheduled_for_deletion_at->locale($locale)->isoFormat('LL');
            $msg = $locale === 'en'
                ? "This account is already scheduled for permanent deletion on {$date}. You can restore it anytime before that date simply by signing in."
                : "Ce compte est déjà programmé pour suppression le {$date}. Vous pouvez le réactiver à tout moment avant cette date en vous reconnectant simplement.";

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'status' => 'already_scheduled',
                    'message' => $msg,
                    'scheduled_date' => $date,
                ]);
            }

            return back()->with('status', 'already_scheduled')->with('message', $msg);
        }

        // Remboursement automatique du solde de crédit BANGO en argent sur le moyen de paiement d'origine avant suppression
        $creditRefund = app(CreditWalletService::class)->refundBalanceBeforeAccountDeletion(
            user: $user,
            phone: $request->input('phone'),
            operator: $request->input('operator')
        );

        // Application de la suppression dans 30 jours (identique à l'application mobile)
        $scheduledDeletionDate = Carbon::now()->addDays(30);

        $user->scheduled_for_deletion_at = $scheduledDeletionDate;
        $user->save();

        // Soft delete et révocation immédiate de toutes les sessions
        $user->delete();
        $user->tokens()->delete();

        // Envoi de la confirmation par e-mail
        if (! empty($user->email)) {
            try {
                $user->notify(new AccountDeletionRequestedNotification($scheduledDeletionDate, $locale));
            } catch (\Throwable) {
                // Silently continue if mail transport is not configured
            }
        }

        $dateFormatted = $scheduledDeletionDate->locale($locale)->isoFormat('LL');
        $successMsg = $locale === 'en'
            ? "Your deletion request has been registered. Your account and all associated data will be permanently deleted on {$dateFormatted}. If you change your mind, simply sign in before that date to cancel the deletion."
            : "Votre demande de suppression a bien été prise en compte. Votre compte et l'ensemble de vos données seront définitivement effacés le {$dateFormatted}. Si vous changez d'avis, connectez-vous simplement à l'application avant cette date pour annuler la suppression.";

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'status' => 'scheduled_for_deletion',
                'message' => $successMsg,
                'scheduled_date' => $dateFormatted,
                'credit_refund' => $creditRefund,
            ]);
        }

        return back()
            ->with('status', 'scheduled_for_deletion')
            ->with('message', $successMsg)
            ->with('scheduled_date', $dateFormatted);
    }

    /**
     * Traite la demande de suppression de données spécifiques sans supprimer le compte (recommandé par Google).
     */
    public function deletePartialData(Request $request, string $locale = 'fr'): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'auth_method' => ['required', 'string', 'in:password,otp'],
            'password' => ['nullable', 'string', 'required_if:auth_method,password'],
            'otp_code' => ['nullable', 'string', 'required_if:auth_method,otp'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['string', 'in:location_history,devices,search_history'],
            'locale' => ['nullable', 'string', 'in:fr,en'],
        ]);

        $locale = $validated['locale'] ?? ($request->route('locale') ?: $locale);
        $identifier = trim($validated['identifier']);
        $authMethod = $validated['auth_method'];

        $user = $this->findUserByIdentifier($identifier);

        if (! $user) {
            $error = $locale === 'en'
                ? 'No account was found with this email or phone number.'
                : 'Aucun compte n\'a été trouvé avec cet e-mail ou ce numéro de téléphone.';

            return $this->errorResponse($request, $error);
        }

        // Vérification de l'authentification
        if ($authMethod === 'password') {
            if (! $user->password || ! Hash::check($request->input('password'), $user->password)) {
                $error = $locale === 'en'
                    ? 'The password entered is incorrect.'
                    : 'Le mot de passe saisi est incorrect.';

                return $this->errorResponse($request, $error);
            }
        } else {
            $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
            $channel = $isEmail ? OtpChannel::Email : OtpChannel::Sms;
            $target = $isEmail ? ($user->email ?? $identifier) : ($user->phone ?? $identifier);

            $verified = $this->otpService->verifyOtp(
                identifier: $target,
                code: trim((string) $request->input('otp_code')),
                channel: $channel,
                context: 'account_deletion'
            );

            if ($verified !== true) {
                $error = $locale === 'en'
                    ? 'The verification code is invalid or has expired.'
                    : 'Le code de vérification est incorrect ou a expiré.';

                return $this->errorResponse($request, $error);
            }
        }

        $categories = $validated['categories'];

        // 1. Suppression des jetons et de l'historique d'appareils
        if (in_array('devices', $categories, true)) {
            $user->deviceTokens()->delete();
        }

        // 2. Réinitialisation des coordonnées de localisation déclarées
        if (in_array('location_history', $categories, true)) {
            $user->update([
                'city' => null,
                'city_id' => null,
                'neighbourhood' => null,
                'neighbourhood_id' => null,
                'neighbourhood_verified_at' => null,
            ]);
        }

        $successMsg = $locale === 'en'
            ? 'The selected data categories have been successfully deleted from your account.'
            : 'Les catégories de données sélectionnées ont été supprimées avec succès de votre compte.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'status' => 'partial_data_deleted',
                'message' => $successMsg,
            ]);
        }

        return back()
            ->with('status', 'partial_data_deleted')
            ->with('message', $successMsg);
    }

    /**
     * Recherche un utilisateur par e-mail ou numéro de téléphone (supporte avec ou sans indicatif).
     */
    protected function findUserByIdentifier(string $identifier): ?User
    {
        $clean = trim($identifier);

        if (filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            return User::withTrashed()->where('email', strtolower($clean))->first();
        }

        $digits = preg_replace('/[^0-9]/', '', $clean);

        return User::withTrashed()
            ->where(function ($query) use ($clean, $digits) {
                $query->where('phone', $clean)
                    ->orWhere('phone', '+'.$digits)
                    ->orWhere('phone', $digits);

                if (str_starts_with($digits, '237') && strlen($digits) > 3) {
                    $query->orWhere('phone', substr($digits, 3));
                }
            })
            ->first();
    }

    protected function errorResponse(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }

        return back()->withInput()->with('error', $message);
    }
}
