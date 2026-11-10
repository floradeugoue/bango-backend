<?php

namespace App\Http\Controllers\Admin\Shell;

use App\Http\Controllers\Controller;
use App\Models\Admin\AdminPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminPreferenceController extends Controller
{
    public const MAX_VALUE_BYTES = 16384;

    /**
     * Retourne toutes les préférences enregistrées de l'opérateur connecté.
     *
     * GET /api/admin/me/preferences
     */
    public function index(Request $request): JsonResponse
    {
        $operator = $request->attributes->get('admin_operator') ?? $request->user();

        $preferences = AdminPreference::query()
            ->where('user_id', $operator->id)
            ->orderBy('key')
            ->get(['key', 'value']);

        $data = [];
        foreach ($preferences as $preference) {
            $data[$preference->key] = $preference->value;
        }

        return response()->json([
            'success' => true,
            'data' => (object) $data,
        ]);
    }

    /**
     * Crée ou met à jour une préférence de l'opérateur connecté pour la clé donnée (16 Ko max).
     *
     * PUT /api/admin/me/preferences/{key}
     */
    public function upsert(Request $request, string $key): JsonResponse
    {
        $operator = $request->attributes->get('admin_operator') ?? $request->user();
        $normalizedKey = trim(rawurldecode($key));

        if ($normalizedKey === '' || mb_strlen($normalizedKey) > 191) {
            throw ValidationException::withMessages([
                'key' => ['La clé de préférence est invalide ou dépasse 191 caractères.'],
            ]);
        }

        if (! array_key_exists('value', $request->all())) {
            throw ValidationException::withMessages([
                'value' => ['Le champ value est obligatoire.'],
            ]);
        }

        $value = $request->input('value');
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false || strlen($encoded) > self::MAX_VALUE_BYTES) {
            throw ValidationException::withMessages([
                'value' => ['La valeur JSON de la préférence ne peut pas dépasser 16 Ko (16384 octets).'],
            ]);
        }

        $preference = AdminPreference::updateOrCreate(
            [
                'user_id' => $operator->id,
                'key' => $normalizedKey,
            ],
            [
                'value' => $value,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'key' => $preference->key,
                'value' => $preference->value,
            ],
        ]);
    }
}

