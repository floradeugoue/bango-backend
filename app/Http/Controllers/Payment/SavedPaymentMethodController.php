<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StoreSavedPaymentMethodRequest;
use App\Http\Resources\Payment\SavedPaymentMethodResource;
use App\Models\Payment\SavedPaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SavedPaymentMethodController extends Controller
{
    /**
     * Liste les moyens de paiement enregistrés de l'utilisateur connecté.
     *
     * GET /api/payments/methods
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $methods = SavedPaymentMethod::where('user_id', $user->id)
            ->orderBy('is_default', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return SavedPaymentMethodResource::collection($methods);
    }

    /**
     * Enregistre un nouveau moyen de paiement Mobile Money sans déclencher de débit.
     *
     * POST /api/payments/methods
     */
    public function store(StoreSavedPaymentMethodRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $operator = strtolower(trim($validated['operator']));
        $rawPhone = trim($validated['phone_number']);
        $normalizedPhone = preg_replace('/[^\d+]/', '', $rawPhone);

        $existingCount = SavedPaymentMethod::where('user_id', $user->id)->count();
        $isDefault = $request->boolean('is_default') || $existingCount === 0;

        $method = DB::transaction(function () use ($user, $operator, $normalizedPhone, $isDefault) {
            if ($isDefault) {
                SavedPaymentMethod::where('user_id', $user->id)->update(['is_default' => false]);
            }

            return SavedPaymentMethod::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'operator' => $operator,
                    'phone_number' => $normalizedPhone,
                ],
                [
                    'is_default' => $isDefault,
                ]
            );
        });

        return (new SavedPaymentMethodResource($method))
            ->additional(['message' => 'Moyen de paiement enregistré avec succès.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Définit un moyen de paiement comme moyen par défaut.
     *
     * PATCH /api/payments/methods/{id}/default
     */
    public function setDefault(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        $method = SavedPaymentMethod::where('id', $id)->firstOrFail();

        Gate::authorize('update', $method);

        DB::transaction(function () use ($user, $method) {
            SavedPaymentMethod::where('user_id', $user->id)->update(['is_default' => false]);
            $method->is_default = true;
            $method->save();
        });

        return (new SavedPaymentMethodResource($method->fresh()))
            ->additional(['message' => 'Moyen de paiement par défaut mis à jour.'])
            ->response();
    }

    /**
     * Supprime un moyen de paiement enregistré.
     *
     * DELETE /api/payments/methods/{id}
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $method = SavedPaymentMethod::where('id', $id)->firstOrFail();

        Gate::authorize('delete', $method);

        $wasDefault = $method->is_default;
        $userId = $method->user_id;

        DB::transaction(function () use ($method, $wasDefault, $userId) {
            $method->delete();

            // Si le moyen supprimé était le moyen par défaut, en élire un autre s'il en reste
            if ($wasDefault) {
                $next = SavedPaymentMethod::where('user_id', $userId)->first();
                if ($next) {
                    $next->is_default = true;
                    $next->save();
                }
            }
        });

        return response()->json([
            'message' => 'Moyen de paiement supprimé avec succès.',
        ]);
    }
}
