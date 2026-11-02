<?php

namespace App\Services\Admin\Finance;

use App\Enums\Visit\VisitReservationStatus;
use App\Models\Delegation\VisitShare;
use App\Models\Payment\AgentPayout;
use App\Models\Payment\LandlordPayout;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminPayoutService
{
    /**
     * 4.5. Liste paginée des reversements aux bailleurs avec filtres et statuts.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getPayouts(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = LandlordPayout::with([
            'landlord:id,name,email,phone,badge',
            'visitReservation.listing:id,title,city',
            'transaction:id,reference,total_amount,status',
        ])
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['landlord_id'])) {
            $query->where('landlord_id', (int) $filters['landlord_id']);
        }

        if (! empty($filters['reference'])) {
            $query->where('reference', trim((string) $filters['reference']));
        }

        if (! empty($filters['from'])) {
            $query->whereDate('scheduled_at', '>=', Carbon::parse($filters['from']));
        }

        if (! empty($filters['to'])) {
            $query->whereDate('scheduled_at', '<=', Carbon::parse($filters['to']));
        }

        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $query->where(function (Builder $sub) use ($term, $like) {
                $sub->where('reference', $like, "%{$term}%")
                    ->orWhere('external_reference', $like, "%{$term}%")
                    ->orWhereHas('landlord', function (Builder $uq) use ($term, $like) {
                        $uq->where('name', $like, "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%");
                    });
            });
        }

        $boundedPerPage = min(100, max(1, $perPage));

        return $query->paginate($boundedPerPage);
    }

    /**
     * 4.6. Relance d'un reversement échoué ou bloqué.
     */
    public function retryPayout(LandlordPayout $payout, User $operator): LandlordPayout
    {
        return DB::transaction(function () use ($payout, $operator) {
            /** @var LandlordPayout $lockedPayout */
            $lockedPayout = LandlordPayout::where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayout->isCompleted()) {
                throw new DomainException('Ce reversement a déjà été exécuté avec succès.');
            }

            if (! $lockedPayout->canRetry()) {
                throw new DomainException(
                    sprintf('Impossible de relancer un reversement en statut "%s".', $lockedPayout->status)
                );
            }

            $lockedPayout->attempts_count += 1;
            $lockedPayout->status = LandlordPayout::STATUS_COMPLETED;
            $lockedPayout->executed_at = now();
            $lockedPayout->external_reference = 'PAYOUT-RETRY-'.strtoupper(Str::random(12));
            $lockedPayout->last_error = null;

            $meta = $lockedPayout->metadata ?? [];
            $meta['retries'][] = [
                'operator_id' => $operator->id,
                'operator_name' => $operator->name,
                'retried_at' => now()->toIso8601String(),
                'attempt_number' => $lockedPayout->attempts_count,
            ];
            $lockedPayout->metadata = $meta;
            $lockedPayout->save();

            return $lockedPayout;
        });
    }

    /**
     * 4.7. Règle de reversement J+1 à 08:00 après une visite effectuée.
     * Planifie le reversement des frais de visite au bailleur.
     */
    public function schedulePayoutForVisit(VisitReservation $reservation): LandlordPayout
    {
        // Contrôle d'éligibilité : la visite doit être visitée/confirmée et payée
        $isEligible = in_array($reservation->status, [
            VisitReservationStatus::Confirmed,
            VisitReservationStatus::Visited,
            'completed',
            'visited',
        ], true) && (float) $reservation->fee > 0;

        if (! $isEligible) {
            throw new DomainException('Seule une visite confirmée avec frais acquittés peut faire l\'objet d\'un reversement.');
        }

        // Empêcher les doubles reversements
        $existing = LandlordPayout::where('visit_reservation_id', $reservation->id)->first();
        if ($existing) {
            return $existing;
        }

        // Calcul de l'échéance : J+1 à 08:00 (heure locale / fuseau configuré)
        $visitDate = $reservation->slot?->start_at ?? $reservation->paid_at ?? now();
        $scheduledAt = Carbon::parse($visitDate)
            ->addDay()
            ->setTime(8, 0, 0);

        $totalFee = (float) $reservation->fee;
        $agentPercent = 0.0;
        $agentId = $reservation->agent_id ?? $reservation->listing?->agent_id;

        if ($agentId && $reservation->owner_id) {
            $share = VisitShare::where('landlord_id', $reservation->owner_id)
                ->where('agent_id', $agentId)
                ->first();
            if ($share && $share->agreed_percent !== null) {
                $agentPercent = (float) $share->agreed_percent;
            }
        }

        $agentAmount = round(($totalFee * $agentPercent) / 100, 2);
        $landlordAmount = round($totalFee - $agentAmount, 2);

        // Si l'agent a une part, planifier le versement de l'agent
        if ($agentId && $agentAmount > 0) {
            AgentPayout::firstOrCreate([
                'visit_reservation_id' => $reservation->id,
            ], [
                'reference' => 'PAYOUT-AG-'.strtoupper(Str::random(10)),
                'agent_id' => $agentId,
                'landlord_id' => $reservation->owner_id,
                'transaction_id' => $reservation->transaction_id,
                'amount' => $agentAmount,
                'percent' => $agentPercent,
                'currency' => $reservation->currency ?: 'XAF',
                'status' => AgentPayout::STATUS_SCHEDULED,
                'scheduled_at' => $scheduledAt,
                'provider' => 'monetbil',
                'channel' => 'mobile_money',
                'metadata' => [
                    'rule' => 'Agent visit fee cut',
                    'visit_slot_start' => $reservation->slot?->start_at?->toIso8601String(),
                ],
            ]);
        }

        return LandlordPayout::create([
            'reference' => 'PAYOUT-'.strtoupper(Str::random(10)),
            'landlord_id' => $reservation->owner_id,
            'visit_reservation_id' => $reservation->id,
            'transaction_id' => $reservation->transaction_id,
            'amount' => $landlordAmount,
            'currency' => $reservation->currency ?: 'XAF',
            'status' => LandlordPayout::STATUS_SCHEDULED,
            'scheduled_at' => $scheduledAt,
            'provider' => 'monetbil',
            'channel' => 'mobile_money',
            'metadata' => [
                'rule' => 'J+1 08:00 post-visit fee disbursement',
                'visit_slot_start' => $reservation->slot?->start_at?->toIso8601String(),
                'scheduled_time' => '08:00:00',
                'agent_cut' => $agentId && $agentAmount > 0 ? [
                    'agent_id' => (int) $agentId,
                    'percent' => (float) $agentPercent,
                    'amount' => (float) $agentAmount,
                ] : null,
            ],
        ]);
    }

    /**
     * Traitement par lot des reversements planifiés dont l'échéance J+1 08:00 est échue.
     */
    public function processScheduledPayouts(): int
    {
        $duePayouts = LandlordPayout::where('status', LandlordPayout::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->get();

        $processed = 0;
        foreach ($duePayouts as $payout) {
            DB::transaction(function () use ($payout, &$processed) {
                /** @var LandlordPayout $locked */
                $locked = LandlordPayout::where('id', $payout->id)->lockForUpdate()->first();
                if ($locked && $locked->status === LandlordPayout::STATUS_SCHEDULED) {
                    $locked->status = LandlordPayout::STATUS_COMPLETED;
                    $locked->executed_at = now();
                    $locked->external_reference = 'PAYOUT-AUTO-'.strtoupper(Str::random(12));
                    $locked->save();
                    $processed++;
                }
            });
        }

        return $processed;
    }

    /**
     * Annule les reversements programmés (bailleur et agent) associés à une réservation si elle est annulée/déclinée/remboursée.
     */
    public function cancelScheduledPayoutForVisit(VisitReservation $reservation): int
    {
        $cancelledCount = 0;

        $landlordPayouts = LandlordPayout::where('visit_reservation_id', $reservation->id)
            ->whereIn('status', [LandlordPayout::STATUS_SCHEDULED, LandlordPayout::STATUS_PROCESSING])
            ->get();

        foreach ($landlordPayouts as $payout) {
            $payout->status = LandlordPayout::STATUS_CANCELLED;
            $payout->last_error = 'Réservation annulée, refusée ou remboursée avant échéance.';
            $payout->save();
            $cancelledCount++;
        }

        $agentPayouts = AgentPayout::where('visit_reservation_id', $reservation->id)
            ->whereIn('status', [AgentPayout::STATUS_SCHEDULED, AgentPayout::STATUS_PROCESSING])
            ->get();

        foreach ($agentPayouts as $payout) {
            $payout->status = AgentPayout::STATUS_CANCELLED;
            $payout->last_error = 'Réservation annulée, refusée ou remboursée avant échéance.';
            $payout->save();
            $cancelledCount++;
        }

        return $cancelledCount;
    }
}
