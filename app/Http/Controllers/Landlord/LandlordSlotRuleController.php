<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Resources\Landlord\LandlordSlotRuleResource;
use App\Http\Resources\Landlord\LandlordUnavailabilityResource;
use App\Models\Landlord\LandlordSlotRule;
use App\Models\Landlord\LandlordUnavailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class LandlordSlotRuleController extends Controller
{
    /**
     * Obtenir les règles de créneaux par défaut du bailleur.
     * GET /api/landlord/slot-rules
     */
    public function showRules(Request $request): JsonResponse
    {
        $user = $request->user();
        $rule = LandlordSlotRule::firstOrCreate(
            ['user_id' => $user->id],
            [
                'days' => [],
                'minimum_hours' => 4,
                'grouped' => false,
                'group_size' => 5,
                'auto_confirm' => false,
            ]
        );

        return response()->json([
            'data' => new LandlordSlotRuleResource($rule),
        ]);
    }

    /**
     * Mettre à jour les règles de créneaux globales du bailleur.
     * PUT /api/landlord/slot-rules
     */
    public function updateRules(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'array'],
            'days.*.day' => ['required_with:days', 'integer', 'between:0,6'],
            'days.*.from' => ['nullable', 'string'],
            'days.*.to' => ['nullable', 'string'],
            'days.*.closed' => ['nullable', 'boolean'],
            'minimum_hours' => ['nullable', 'integer', 'min:0', 'max:72'],
            'grouped' => ['nullable', 'boolean'],
            'group_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'auto_confirm' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $rule = LandlordSlotRule::firstOrNew(['user_id' => $user->id]);

        if (array_key_exists('days', $validated)) {
            $rule->days = $validated['days'];
        }
        if (array_key_exists('minimum_hours', $validated)) {
            $rule->minimum_hours = (int) $validated['minimum_hours'];
        }
        if (array_key_exists('grouped', $validated)) {
            $rule->grouped = (bool) $validated['grouped'];
        }
        if (array_key_exists('group_size', $validated)) {
            $rule->group_size = (int) $validated['group_size'];
        }
        if (array_key_exists('auto_confirm', $validated)) {
            $rule->auto_confirm = (bool) $validated['auto_confirm'];
        }

        $rule->save();

        return response()->json([
            'message' => 'Règles de créneaux globales enregistrées avec succès.',
            'data' => new LandlordSlotRuleResource($rule),
        ]);
    }

    /**
     * Liste des périodes d'indisponibilité (congés) du bailleur.
     * GET /api/landlord/unavailability
     */
    public function indexUnavailability(Request $request): AnonymousResourceCollection
    {
        $unavailabilities = LandlordUnavailability::where('user_id', $request->user()->id)
            ->latest('from')
            ->get();

        return LandlordUnavailabilityResource::collection($unavailabilities);
    }

    /**
     * Ajouter une période d'indisponibilité (congés).
     * POST /api/landlord/unavailability
     */
    public function storeUnavailability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
        ]);

        $unavailability = LandlordUnavailability::create([
            'user_id' => $request->user()->id,
            'from' => $validated['from'],
            'to' => $validated['to'],
        ]);

        return response()->json([
            'message' => 'Période d\'indisponibilité enregistrée.',
            'data' => new LandlordUnavailabilityResource($unavailability),
        ], 201);
    }

    /**
     * Supprimer une période d'indisponibilité (congés).
     * DELETE /api/landlord/unavailability/{id} ou DELETE /api/landlord/unavailability avec body {id|from, to}
     */
    public function destroyUnavailability(Request $request, ?string $id = null): JsonResponse
    {
        $user = $request->user();

        if ($id) {
            $unavailability = LandlordUnavailability::where('user_id', $user->id)
                ->where('id', $id)
                ->firstOrFail();
            $unavailability->delete();

            return response()->json([
                'message' => 'Période d\'indisponibilité supprimée.',
            ]);
        }

        $validated = $request->validate([
            'id' => ['nullable', 'string'],
            'from' => ['required_without:id', 'date'],
            'to' => ['required_without:id', 'date'],
        ]);

        $query = LandlordUnavailability::where('user_id', $user->id);

        if (! empty($validated['id'])) {
            $query->where('id', $validated['id']);
        } else {
            $fromDate = Carbon::parse($validated['from']);
            $toDate = Carbon::parse($validated['to']);
            $query->where(function ($q) use ($fromDate, $toDate) {
                $q->where('from', $fromDate)->where('to', $toDate);
            });
        }

        $records = $query->get();
        $deleted = $records->count();
        foreach ($records as $record) {
            $record->delete();
        }

        return response()->json([
            'message' => $deleted > 0 ? 'Période d\'indisponibilité supprimée.' : 'Aucune période trouvée.',
            'deleted' => $deleted,
        ]);
    }
}
