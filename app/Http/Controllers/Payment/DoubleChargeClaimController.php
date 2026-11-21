<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StoreDoubleChargeClaimRequest;
use App\Models\Payment\PaymentDispute;
use App\Models\Payment\PaymentTransaction;
use App\Services\Boost\CreditWalletService;
use App\Services\Payment\Gateways\MonetbilGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        $rawRefundMethod = strtolower((string) ($validated['refund_method'] ?? 'original_payment_method'));
        $refundMethod = in_array($rawRefundMethod, ['cash', 'mobile_money', 'original_payment_method'], true)
            ? 'original_payment_method'
            : 'bango_credit';

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
                'refund_method' => $refundMethod,
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
                'refund_method' => $refundMethod,
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

    /**
     * Exécute ou convertit à la demande de l'utilisateur le remboursement d'un double débit
     * en argent sur le moyen de paiement d'origine (Mobile Money).
     *
     * POST /api/payments/double-charge-claims/{id}/refund-to-source
     */
    public function refundToSource(
        Request $request,
        string $id,
        MonetbilGateway $monetbilGateway,
        CreditWalletService $creditWalletService
    ): JsonResponse {
        $user = $request->user();

        /** @var PaymentDispute|null $dispute */
        $dispute = PaymentDispute::where('id', $id)
            ->orWhere('reference', $id)
            ->first();

        if (! $dispute || $dispute->type !== 'double_charge') {
            abort(404, 'Déclaration de double débit introuvable.');
        }

        if ((int) $dispute->user_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403, 'Vous n\'êtes pas autorisé à demander le remboursement de ce dossier.');
        }

        $meta = $dispute->metadata ?? [];
        if (($meta['refund_channel'] ?? null) === 'original_payment_method' && ! empty($meta['cash_refund'])) {
            throw ValidationException::withMessages([
                'refund_channel' => ['Ce double débit a déjà été remboursé en argent sur le moyen de paiement d\'origine.'],
            ]);
        }

        return DB::transaction(function () use ($dispute, $user, $monetbilGateway, $creditWalletService) {
            /** @var PaymentDispute $locked */
            $locked = PaymentDispute::where('id', $dispute->id)->lockForUpdate()->firstOrFail();
            $meta = $locked->metadata ?? [];
            $amount = (float) $locked->disputed_amount;

            // Si le double débit avait précédemment été compensé en crédit BANGO, débiter le crédit avant versement Mobile Money
            $owner = $locked->user ?? $user;
            if (($meta['refund_channel'] ?? null) === 'bango_credit' || $creditWalletService->getBalance($owner) >= $amount) {
                if (($meta['refund_channel'] ?? null) === 'bango_credit' && $creditWalletService->getBalance($owner) >= $amount) {
                    $creditWalletService->debit(
                        account: $owner,
                        amount: (int) round($amount),
                        source: 'double_charge_cash_refund_conversion',
                        reference: 'CASH-CONV-DBL-'.$locked->reference,
                        description: sprintf('Conversion en remboursement Mobile Money du double débit #%s', $locked->reference),
                        idempotencyKey: 'double_charge_cash_conv_'.$locked->id,
                        referenceModel: $locked
                    );
                }
            }

            $phone = (string) ($locked->claimant_phone ?: $owner->phone ?: '+237690000000');
            $operator = $meta['operator'] ?? null;

            $disburse = $monetbilGateway->disburseToMobileMoney(
                phone: $phone,
                amount: $amount,
                currency: $locked->currency ?: 'XAF',
                reference: 'REFUND-DBL-'.$locked->reference,
                operator: is_string($operator) ? $operator : null,
                metadata: [
                    'dispute_id' => $locked->id,
                    'dispute_reference' => $locked->reference,
                    'operator_reference' => $locked->operator_reference,
                ]
            );

            $meta['refund_method'] = 'original_payment_method';
            $meta['refund_channel'] = 'original_payment_method';
            $meta['cash_refund'] = [
                'amount_refunded' => $amount,
                'currency' => $locked->currency ?: 'XAF',
                'phone' => $disburse['phone'],
                'operator' => $disburse['operator'],
                'external_reference' => $disburse['external_reference'],
                'refunded_at' => now()->toIso8601String(),
            ];
            $locked->metadata = $meta;
            $locked->status = 'resolved';
            $locked->resolved_at = $locked->resolved_at ?? now();
            $locked->decision = $locked->decision ?? 'Remboursement en argent effectué sur le moyen de paiement d\'origine.';
            $locked->save();

            return response()->json([
                'message' => 'Le double débit a été remboursé en argent sur votre moyen de paiement d\'origine.',
                'data' => [
                    'id' => $locked->id,
                    'reference' => $locked->reference,
                    'status' => $locked->status,
                    'refund_channel' => 'original_payment_method',
                    'disputed_amount' => (float) $locked->disputed_amount,
                    'currency' => $locked->currency,
                    'cash_refund' => $meta['cash_refund'],
                ],
            ]);
        });
    }
}
