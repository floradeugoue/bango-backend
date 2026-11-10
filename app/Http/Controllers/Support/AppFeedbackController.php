<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Support\AppFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppFeedbackController extends Controller
{
    /**
     * Enregistrer un avis sur l'application (« Comment ça se passe pour toi ? »).
     *
     * POST /api/app-feedback
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mood' => ['required', 'string', Rule::in(['poor', 'fine', 'great'])],
            'comment' => ['nullable', 'string', 'max:2000'],
            'platform' => ['nullable', 'string', 'max:32'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'metadata' => ['nullable', 'array'],
        ]);

        $activePage = $request->attributes->get('acting_page') ?? $request->attributes->get('active_page');

        $feedback = AppFeedback::create([
            'user_id' => $request->user()?->id,
            'page_id' => $activePage?->id,
            'mood' => $validated['mood'],
            'comment' => $validated['comment'] ?? null,
            'platform' => $validated['platform'] ?? $request->header('X-Bango-Platform'),
            'app_version' => $validated['app_version'] ?? $request->header('X-Bango-App-Version'),
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Merci pour ton retour !',
            'data' => [
                'id' => $feedback->id,
                'mood' => $feedback->mood,
                'comment' => $feedback->comment,
                'platform' => $feedback->platform,
                'app_version' => $feedback->app_version,
                'created_at' => $feedback->created_at?->toIso8601String(),
            ],
        ], 201);
    }
}
