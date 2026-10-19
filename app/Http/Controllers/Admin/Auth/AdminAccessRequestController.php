<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\AdminAccessRequestCreateRequest;
use App\Models\Admin\AdminAccessRequest;
use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\AdminInvitation;
use App\Models\Auth\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

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

    /**
     * GET /admin/access-requests
     * Côté direction : lister et examiner les demandes d'accès des collaborateurs.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AdminAccessRequest::with('reviewer:id,name,email');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('department')) {
            $query->where('department', $request->input('department'));
        }

        if ($request->filled('requested_role')) {
            $query->where('requested_role', $request->input('requested_role'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        $requests = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20));

        return response()->json($requests);
    }

    /**
     * POST /admin/access-requests/{id}/approve
     * Côté direction : approuver une demande d'accès et générer l'invitation avec jeton sécurisé.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        /** @var AdminAccessRequest|null $accessRequest */
        $accessRequest = AdminAccessRequest::findOrFail($id);

        if ($accessRequest->status !== 'pending') {
            return response()->json([
                'message' => 'Cette demande d\'accès a déjà été traitée.',
            ], 400);
        }

        $roleSlug = $request->input('role') ?? $accessRequest->requested_role;
        $role = Role::where('slug', $roleSlug)->first();
        if (! $role) {
            return response()->json([
                'message' => "Le rôle '{$roleSlug}' est invalide ou inexistant.",
            ], 422);
        }

        $rawToken = Str::random(40);
        $days = $request->integer('expires_in_days', 3);

        $invitation = AdminInvitation::create([
            'email' => $accessRequest->email,
            'role' => $role->slug,
            'token' => $rawToken,
            'invited_by' => $request->user()?->id,
            'expires_at' => now()->addDays($days),
        ]);

        $accessRequest->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);

        AdminAuditLog::logEvent('auth.access_request.approved', $request->user(), $request->ip(), $request->userAgent(), [
            'access_request_id' => $accessRequest->id,
            'email' => $accessRequest->email,
            'role' => $role->slug,
        ]);

        return response()->json([
            'message' => 'Demande d\'accès approuvée et invitation générée.',
            'access_request' => $accessRequest,
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'token' => $rawToken,
            ],
        ], 200);
    }

    /**
     * POST /admin/access-requests/{id}/reject
     * Côté direction : rejeter une demande d'accès.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        /** @var AdminAccessRequest|null $accessRequest */
        $accessRequest = AdminAccessRequest::findOrFail($id);

        if ($accessRequest->status !== 'pending') {
            return response()->json([
                'message' => 'Cette demande d\'accès a déjà été traitée.',
            ], 400);
        }

        $accessRequest->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);

        AdminAuditLog::logEvent('auth.access_request.rejected', $request->user(), $request->ip(), $request->userAgent(), [
            'access_request_id' => $accessRequest->id,
            'email' => $accessRequest->email,
            'reason' => $request->input('reason'),
        ]);

        return response()->json([
            'message' => 'Demande d\'accès rejetée.',
            'access_request' => $accessRequest,
        ], 200);
    }
}
