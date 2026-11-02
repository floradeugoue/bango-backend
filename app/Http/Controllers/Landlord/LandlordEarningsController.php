<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\OtpChannel;
use App\Enums\Visit\VisitReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment\LandlordPayout;
use App\Models\Visit\VisitReservation;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandlordEarningsController extends Controller
{
    public function __construct(
        protected OtpService $otpService
    ) {}

    /**
     * Synthèse des gains et historique des mouvements du bailleur.
     *
     * GET /api/landlord/earnings
     *
     * Réponse attendue :
     * {
     *   collected, refunded, pending_payout, paid_out, currency,
     *   movements: [{ id, kind: visit|refund|payout, amount, at, reference, visit_id, payer: { name, avatar_url } }]
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $landlord = $request->user();

        // 1. Récupérer toutes les réservations de visite payées / concernées pour ce bailleur
        $reservations = VisitReservation::where('owner_id', $landlord->id)
            ->whereNotNull('paid_at')
            ->with(['prospect', 'listing'])
            ->get();

        // 2. Récupérer tous les versements (payouts) enregistrés pour ce bailleur
        $payouts = LandlordPayout::where('landlord_id', $landlord->id)
            ->latest('created_at')
            ->get();

        $currency = $reservations->first()?->currency ?? $payouts->first()?->currency ?? 'XAF';

        $collected = (float) $reservations->sum('fee');
        $refunded = (float) $reservations->whereNotNull('refunded_at')->sum(function ($r) {
            return $r->refund_amount ?? $r->fee;
        });

        // Payouts effectués (completed)
        $paidOut = (float) $payouts->where('status', LandlordPayout::STATUS_COMPLETED)->sum('amount');

        // Payouts en attente programmés (scheduled ou processing)
        $pendingPayoutFromModel = (float) $payouts->whereIn('status', [
            LandlordPayout::STATUS_SCHEDULED,
            LandlordPayout::STATUS_PROCESSING,
        ])->sum('amount');

        // Solde en attente de versement : net collecté (collected - refunded) moins ce qui a déjà été versé (paidOut)
        // ou le total des versements en cours programmés
        $netEarnings = max(0, $collected - $refunded - $paidOut);
        $pendingPayout = $pendingPayoutFromModel > 0 ? $pendingPayoutFromModel : $netEarnings;

        // 3. Construction des mouvements chronologiques
        $movements = collect();

        $payoutsByReservation = $payouts->groupBy('visit_reservation_id');

        foreach ($reservations as $res) {
            $matchingPayout = $payoutsByReservation->get($res->id)?->first();

            // État des fonds côté bailleur (held: conservé par BANGO, scheduled: programmé, paid: reversé, cancelled: annulé/remboursé)
            $payoutState = 'held';
            $scheduledAt = null;

            if ($res->refunded_at || in_array($res->status, [VisitReservationStatus::Cancelled, VisitReservationStatus::Declined, VisitReservationStatus::Refunded], true)) {
                $payoutState = 'cancelled';
            } elseif ($matchingPayout) {
                if ($matchingPayout->status === LandlordPayout::STATUS_COMPLETED) {
                    $payoutState = 'paid';
                } elseif ($matchingPayout->status === LandlordPayout::STATUS_CANCELLED) {
                    $payoutState = 'cancelled';
                } elseif ($matchingPayout->status === LandlordPayout::STATUS_SCHEDULED || $matchingPayout->status === LandlordPayout::STATUS_PROCESSING) {
                    $payoutState = 'scheduled';
                    $scheduledAt = $matchingPayout->scheduled_at?->toIso8601String();
                }
            }

            $agentCut = null;
            if ($matchingPayout && isset($matchingPayout->metadata['agent_cut'])) {
                $agentCut = $matchingPayout->metadata['agent_cut'];
            }

            // Mouvement de type "visit" (encaissement de visite)
            $movements->push([
                'id' => 'visit-'.$res->id,
                'kind' => 'visit',
                'amount' => (float) ($matchingPayout ? $matchingPayout->amount : $res->fee),
                'total_amount' => (float) $res->fee,
                'agent_cut' => $agentCut,
                'at' => $res->paid_at?->toIso8601String() ?? $res->created_at?->toIso8601String(),
                'reference' => $res->payment_reference ?? ('VIS-'.substr($res->id, 0, 8)),
                'visit_id' => (string) $res->id,
                'status' => $payoutState,
                'payout_state' => $payoutState,
                'scheduled_at' => $scheduledAt,
                'payer' => [
                    'name' => $res->prospect?->display_name ?: ($res->prospect?->name ?? 'Prospect'),
                    'avatar_url' => $res->prospect?->avatar_url,
                ],
            ]);

            // Mouvement de type "refund" si la visite a été remboursée
            if ($res->refunded_at) {
                $movements->push([
                    'id' => 'refund-'.$res->id,
                    'kind' => 'refund',
                    'amount' => (float) ($res->refund_amount ?? $res->fee),
                    'at' => $res->refunded_at->toIso8601String(),
                    'reference' => 'REF-'.substr($res->id, 0, 8),
                    'visit_id' => (string) $res->id,
                    'status' => 'refunded',
                    'payout_state' => 'cancelled',
                    'scheduled_at' => null,
                    'payer' => [
                        'name' => $res->prospect?->display_name ?: ($res->prospect?->name ?? 'Prospect'),
                        'avatar_url' => $res->prospect?->avatar_url,
                    ],
                ]);
            }
        }

        foreach ($payouts as $payout) {
            // Mouvement de type "payout" (versement bancaire / mobile money)
            $movements->push([
                'id' => 'payout-'.$payout->id,
                'kind' => 'payout',
                'amount' => (float) $payout->amount,
                'at' => ($payout->executed_at ?? $payout->created_at)->toIso8601String(),
                'scheduled_at' => $payout->scheduled_at?->toIso8601String(),
                'reference' => $payout->reference,
                'visit_id' => $payout->visit_reservation_id,
                'status' => $payout->status,
                'payer' => [
                    'name' => 'BANGO Payout',
                    'avatar_url' => null,
                ],
            ]);
        }

        // Tri des mouvements du plus récent au plus ancien
        $sortedMovements = $movements->sortByDesc('at')->values()->all();

        $totalEarnings = (float) $payouts->sum('amount');
        if ($totalEarnings === 0.0 && $collected > 0) {
            $totalEarnings = (float) $netEarnings;
        }

        return response()->json([
            'collected' => $collected,
            'refunded' => $refunded,
            'pending_payout' => $pendingPayout,
            'paid_out' => $paidOut,
            'currency' => $currency,
            'summary' => [
                'total_earnings' => $totalEarnings,
                'collected' => $collected,
                'refunded' => $refunded,
                'pending_payout' => $pendingPayout,
                'paid_out' => $paidOut,
                'currency' => $currency,
            ],
            'movements' => $sortedMovements,
        ]);
    }

    /**
     * Consulter le compte de versement configuré pour le bailleur.
     *
     * GET /api/landlord/payout-method
     */
    public function getPayoutMethod(Request $request): JsonResponse
    {
        $landlord = $request->user();

        return response()->json([
            'payout_method' => $landlord->payout_method,
        ]);
    }

    /**
     * Mettre à jour le compte de versement Mobile Money, vérifié par OTP.
     *
     * PUT /api/landlord/payout-method
     */
    public function updatePayoutMethod(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operator' => ['required', 'string', 'max:50'],
            'phone' => ['required', 'string', 'max:30'],
            'otp' => ['nullable', 'string', 'max:10'],
            'code' => ['nullable', 'string', 'max:10'],
        ]);

        $landlord = $request->user();
        $code = $validated['otp'] ?? ($validated['code'] ?? null);

        // Si aucun code OTP n'est fourni, on envoie un OTP par SMS sur le numéro indiqué
        if (! $code) {
            $sendResult = $this->otpService->sendOtp(
                identifier: $validated['phone'],
                channel: OtpChannel::Sms,
                context: 'payout_method'
            );

            if (is_array($sendResult)) {
                return response()->json($sendResult['data'], $sendResult['status']);
            }

            return response()->json([
                'status' => 'otp_sent',
                'message' => 'Un code OTP de confirmation a été envoyé sur ce numéro.',
            ], 200);
        }

        // Vérification de l'OTP
        $verification = $this->otpService->verifyOtp(
            identifier: $validated['phone'],
            code: $code,
            channel: OtpChannel::Sms,
            context: 'payout_method'
        );

        if ($verification !== true) {
            return response()->json($verification['data'], $verification['status']);
        }

        $payoutMethodData = [
            'operator' => $validated['operator'],
            'phone' => $validated['phone'],
            'verified_at' => now()->toIso8601String(),
        ];

        $landlord->payout_method = $payoutMethodData;
        $landlord->save();

        return response()->json([
            'message' => 'Compte de versement enregistré et vérifié avec succès.',
            'payout_method' => $payoutMethodData,
        ], 200);
    }

    /**
     * Liste des versements du vendredi avec statut et référence opérateur.
     *
     * GET /api/landlord/payouts
     */
    public function payouts(Request $request): JsonResponse
    {
        $landlord = $request->user();

        $payouts = LandlordPayout::where('landlord_id', $landlord->id)
            ->with(['visitReservation.listing'])
            ->latest('scheduled_at')
            ->latest('id')
            ->get();

        $data = $payouts->map(function (LandlordPayout $payout) {
            return [
                'id' => (int) $payout->id,
                'reference' => $payout->reference,
                'amount' => (float) $payout->amount,
                'currency' => $payout->currency,
                'status' => $payout->status, // scheduled, sent/completed, failed
                'operator_reference' => $payout->external_reference,
                'external_reference' => $payout->external_reference,
                'provider' => $payout->provider,
                'channel' => $payout->channel,
                'scheduled_at' => $payout->scheduled_at?->toIso8601String(),
                'executed_at' => $payout->executed_at?->toIso8601String(),
                'created_at' => $payout->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }
}
