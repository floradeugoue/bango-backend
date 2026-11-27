<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Support\WaitlistEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WaitlistController extends Controller
{
    /**
     * Inscription depuis l'application mobile BANGO (utilisateur connecté).
     * POST /api/waitlist { "topic": "immo" | "boost" | "badges" | "pages" | "payments" }
     * -> 201 (200 si déjà inscrit)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'topic' => ['required', 'string', 'in:immo,boost,badges,pages,payments,business,app'],
        ]);

        $user = $request->user();
        $topic = strtolower(trim($validated['topic']));

        $existing = WaitlistEntry::where('user_id', $user->id)
            ->where('topic', $topic)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'topic' => $topic,
                'already_registered' => true,
            ], 200);
        }

        WaitlistEntry::create([
            'user_id' => $user->id,
            'contact' => $user->email ?: $user->phone,
            'topic' => $topic,
            'source' => 'app',
            'locale' => $user->locale ?? 'fr',
            'consent' => true,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'success' => true,
            'topic' => $topic,
        ], 201);
    }

    /**
     * Liste des sujets suivis par l'utilisateur connecté dans l'application.
     * GET /api/waitlist
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $topics = WaitlistEntry::where('user_id', $user->id)
            ->pluck('topic')
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'topics' => $topics,
        ]);
    }

    /**
     * Inscription publique depuis le site bangoapp.com (visiteurs sans compte).
     * POST /api/waitlist/public
     * { "contact": "awa@exemple.com", "topic": "immo" | "business" | "app", "locale": "fr", "consent": true }
     * -> 201 { "ok": true } (200 si déjà inscrit, 422 si contact invalide)
     */
    public function storePublic(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'contact' => ['required', 'string', 'max:255'],
            'topic' => ['required', 'string', 'in:immo,business,app,boost,badges,pages,payments'],
            'locale' => ['nullable', 'string', 'in:fr,en'],
            'consent' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'message' => 'Les informations fournies sont incomplètes ou invalides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        if (! $validated['consent']) {
            return response()->json([
                'ok' => false,
                'message' => 'Le consentement préalable est obligatoire pour être prévenu.',
                'errors' => [
                    'consent' => ['Vous devez accepter d\'être prévenu par e-mail ou SMS.'],
                ],
            ], 422);
        }

        $contact = trim((string) $validated['contact']);
        $topic = strtolower(trim((string) $validated['topic']));
        $locale = strtolower((string) ($validated['locale'] ?? 'fr'));

        // Validation stricte du contact : soit un e-mail valide, soit un numéro de téléphone valide
        $isEmail = filter_var($contact, FILTER_VALIDATE_EMAIL) !== false;
        $cleanPhone = preg_replace('/[\s\-\(\)\.]/', '', $contact);
        $isPhone = preg_match('/^\+?[0-9]{6,20}$/', $cleanPhone) === 1;

        if (! $isEmail && ! $isPhone) {
            return response()->json([
                'ok' => false,
                'message' => 'Le contact fourni doit être une adresse e-mail valide ou un numéro de téléphone valide.',
                'errors' => [
                    'contact' => ['Format de contact invalide (adresse e-mail ou numéro attendu).'],
                ],
            ], 422);
        }

        $normalizedContact = $isEmail ? strtolower($contact) : $cleanPhone;

        $existing = WaitlistEntry::where('contact', $normalizedContact)
            ->where('topic', $topic)
            ->first();

        $responsePayload = ['ok' => true];
        $statusCode = 201;

        if ($existing) {
            $responsePayload['already_registered'] = true;
            $statusCode = 200;
        } else {
            WaitlistEntry::create([
                'user_id' => null,
                'contact' => $normalizedContact,
                'topic' => $topic,
                'source' => 'web',
                'locale' => $locale,
                'consent' => true,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        $response = response()->json($responsePayload, $statusCode);

        // Autoriser explicitement le CORS pour https://bangoapp.com
        $origin = $request->header('Origin');
        if ($origin && (str_contains($origin, 'bangoapp.com') || $origin === 'http://localhost:3000')) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With');
        }

        return $response;
    }
}
