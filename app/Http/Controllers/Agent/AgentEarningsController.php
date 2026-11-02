<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Payment\AgentPayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentEarningsController extends Controller
{
    /**
     * Historique et synthèse des gains des visites de l'agent immobilier.
     * GET /api/agent/earnings
     */
    public function index(Request $request): JsonResponse
    {
        $agent = $request->user();

        $payouts = AgentPayout::where('agent_id', $agent->id)
            ->with(['visitReservation.listing', 'landlord'])
            ->latest('created_at')
            ->get();

        $totalEarnings = (float) $payouts->sum('amount');
        $totalScheduled = (float) $payouts->where('status', AgentPayout::STATUS_SCHEDULED)->sum('amount');
        $totalCompleted = (float) $payouts->where('status', AgentPayout::STATUS_COMPLETED)->sum('amount');

        $movements = $payouts->map(function (AgentPayout $payout) {
            return [
                'id' => $payout->id,
                'reference' => $payout->reference,
                'amount' => (float) $payout->amount,
                'percent' => (float) $payout->percent,
                'currency' => $payout->currency,
                'status' => $payout->status,
                'scheduled_at' => $payout->scheduled_at?->toIso8601String(),
                'executed_at' => $payout->executed_at?->toIso8601String(),
                'landlord_id' => $payout->landlord_id,
                'landlord_name' => $payout->landlord?->display_name ?: $payout->landlord?->name,
                'listing_id' => $payout->visitReservation?->listing_id,
                'listing_title' => $payout->visitReservation?->listing?->title,
                'visit_reservation_id' => $payout->visit_reservation_id,
                'created_at' => $payout->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'summary' => [
                'total_earnings' => $totalEarnings,
                'total_scheduled' => $totalScheduled,
                'total_completed' => $totalCompleted,
                'currency' => $payouts->first()?->currency ?? 'XAF',
            ],
            'movements' => $movements,
        ]);
    }
}
