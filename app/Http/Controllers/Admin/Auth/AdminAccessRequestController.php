<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\AdminAccessRequestCreateRequest;
use App\Models\Admin\AdminAccessRequest;
use App\Models\Admin\AdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

class AdminAccessRequestController extends Controller
{
    /**
     * POST /auth/access-requests
     * Soumettre une demande d'accès interne avec une adresse professionnelle @bango.app.
     */
    public function store(AdminAccessRequestCreateRequest $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        $throttleKey = 'access_request:'.$request->ip().':'.$email;

        // Limite : 3 demandes par tranche de 60 minutes par IP et email
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'message' => "Trop de demandes soumises. Veuillez patienter {$seconds} secondes avant de renouveler.",
            ], 429);
        }

        RateLimiter::hit($throttleKey, 3600);

        // Enregistrement de la demande d'accès
        $accessRequest = AdminAccessRequest::create([
            'first_name' => (string) $request->input('first_name'),
            'last_name' => (string) $request->input('last_name'),
            'email' => $email,
            'department' => (string) $request->input('department'),
            'requested_role' => (string) $request->input('requested_role'),
            'reason' => (string) $request->input('reason'),
            'status' => 'pending',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        AdminAuditLog::logEvent('auth.access_request.created', null, $request->ip(), $request->userAgent(), [
            'email' => $email,
            'role' => $request->input('requested_role'),
        ]);

        return response()->json([
            'message' => 'Votre demande d\'accès a bien été enregistrée et transmise à la direction pour validation.',
            'reference_id' => $accessRequest->id,
            'status' => 'pending',
        ], 201);
    }
}
