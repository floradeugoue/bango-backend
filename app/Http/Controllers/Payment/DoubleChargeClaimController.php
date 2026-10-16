<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StoreDoubleChargeClaimRequest;
use App\Models\Payment\PaymentDispute;
use App\Models\Payment\PaymentTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DoubleChargeClaimController extends Controller
{
    /**
     * Enregistre une déclaration de double débit depuis l'application mobile (EDGE-18).
     *
     * POST /api/payments/double-charge-claims
     */
    public function store(StoreDoubleChargeClaimRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $transaction = null;
        if (! empty($validated['transaction_id'])) {
            $transaction = PaymentTransaction::find($validated['transaction_id']);
        } elseif (! empty($validated['transaction_reference'])) {
            $transaction = PaymentTransaction::where('reference', $validated['transaction_reference'])->first();
        }

        // Vérification de la propriété de la transaction si renseignée
        if ($transaction && (int) $transaction->user_id !== (int) $user->id) {
            abort(403, 'Vous ne pouvez contester qu\'une transaction effectuée avec votre propre compte.');
        }

        $operatorRef = trim($validated['operator_reference']);

        // Détection des déclarations en double (même référence opérateur)
        $existingDispute = PaymentDispute::where('operator_reference', $operatorRef)->first();
        if ($existingDispute) {
            return response()->json([
                'message' => 'Une déclaration pour cette référence opérateur a déjà été enregistrée sous le dossier '.$existingDispute->reference.'.',
                'dossier_reference' => $existingDispute->reference,
                'status' => $existingDispute->status,
            ], 422);
        }

        // Détection de réclamation en cours pour la même transaction
        if ($transaction) {
            $pendingDispute = PaymentDispute::where('transaction_id', $transaction->id)
                ->where('user_id', $user->id)
                ->whereIn('status', ['pending', 'under_review'])
                ->first();

            if ($pendingDispute) {
                return response()->json([
                    'message' => 'Une réclamation est déjà en cours d\'instruction pour cette transaction (dossier '.$pendingDispute->reference.').',
                    'dossier_reference' => $pendingDispute->reference,
                    'status' => $pendingDispute->status,
                ], 422);
            }
        }

        $currency = $validated['currency'] ?? $transaction?->currency ?? 'XAF';

        // Création du dossier d'incident (sans altération de solde ni remboursement automatique)
        $dispute = PaymentDispute::create([
            'user_id' => $user->id,
            'transaction_id' => $transaction?->id,
            'type' => 'double_charge',
            'status' => 'pending',
            'reason' => $validated['reason'],
            'claimant_phone' => preg_replace('/[^\d+]/', '', $validated['claimant_phone']),
            'operator_reference' => $operatorRef,
            'disputed_amount' => (float) $validated['disputed_amount'],
            'currency' => $currency,
            'metadata' => [
                'operator' => strtolower($validated['operator']),
                'client_ip' => $request->ip(),
                'declared_at' => now()->toIso8601String(),
            ],
            'notes' => [
                [
                    'id' => (string) Str::uuid(),
                    'author_id' => $user->id,
                    'author_name' => $user->name,
                    'note' => 'Déclaration initiale enregistrée par le client depuis l\'application mobile.',
                    'created_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        return response()->json([
            'message' => 'Votre déclaration de double débit a été enregistrée avec succès. Notre équipe financière va instruire votre dossier.',
            'data' => [
                'id' => $dispute->id,
                'reference' => $dispute->reference,
                'status' => $dispute->status,
                'operator' => $validated['operator'],
                'operator_reference' => $dispute->operator_reference,
                'disputed_amount' => (float) $dispute->disputed_amount,
                'currency' => $dispute->currency,
                'created_at' => $dispute->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Liste les déclarations de double débit du client connecté.
     *
     * GET /api/payments/double-charge-claims/mine
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $claims = PaymentDispute::where('user_id', $user->id)
            ->where('type', 'double_charge')
            ->with('transaction')
            ->latest('created_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json($claims);
    }
}
