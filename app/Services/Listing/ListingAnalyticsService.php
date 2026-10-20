<?php

namespace App\Services\Listing;

use App\Enums\Visit\VisitReservationStatus;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingDailyMetric;
use App\Models\Listing\ListingSave;
use App\Models\Message\Message;
use App\Models\Visit\VisitReservation;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class ListingAnalyticsService
{
    /**
     * Génère la série temporelle chronologique des 30 derniers jours pour un bien immobilier.
     *
     * @return array{listing_id: string, period_days: int, series: array<int, array{date: string, views: int, video_views: int, favorites: int, contacts: int, messages: int, paid_visits: int}>, totals: array{views: int, video_views: int, favorites: int, contacts: int, messages: int, messages_count: int, paid_visits: int, paid_visits_count: int}, messages_count: int, paid_visits_count: int}
     */
    public function getPropertyAnalytics(Listing $listing, int $days = 30): array
    {
        $days = min(max($days, 1), 90);
        $endDate = Carbon::now()->startOfDay();
        $startDate = Carbon::now()->subDays($days - 1)->startOfDay();

        // 1. Initialiser chaque jour de la période avec des compteurs à 0
        $period = CarbonPeriod::create($startDate, $endDate);
        $seriesMap = [];

        foreach ($period as $date) {
            $formatted = $date->format('Y-m-d');
            $seriesMap[$formatted] = [
                'date' => $formatted,
                'views' => 0,
                'video_views' => 0,
                'favorites' => 0,
                'contacts' => 0,
                'messages' => 0,
                'paid_visits' => 0,
            ];
        }

        // 2. Charger les métriques quotidiennes enregistrées dans listing_daily_metrics
        $metrics = ListingDailyMetric::where('listing_id', $listing->id)
            ->whereDate('metric_date', '>=', $startDate->toDateString())
            ->whereDate('metric_date', '<=', $endDate->toDateString())
            ->get();

        foreach ($metrics as $metric) {
            $dateKey = $metric->metric_date instanceof Carbon ? $metric->metric_date->format('Y-m-d') : substr((string) $metric->metric_date, 0, 10);
            if (isset($seriesMap[$dateKey])) {
                $seriesMap[$dateKey]['views'] += (int) $metric->views;
                $seriesMap[$dateKey]['video_views'] += (int) $metric->video_views;
                $seriesMap[$dateKey]['favorites'] += (int) $metric->favorites;
                $seriesMap[$dateKey]['contacts'] += (int) $metric->contacts;
            }
        }

        // 3. Charger les favoris réels enregistrés dans listing_saves
        $saves = ListingSave::where('listing_id', $listing->id)
            ->whereBetween('created_at', [$startDate, Carbon::now()->endOfDay()])
            ->selectRaw('DATE(created_at) as save_date, count(*) as count')
            ->groupBy('save_date')
            ->pluck('count', 'save_date');

        foreach ($saves as $dateKey => $count) {
            if (isset($seriesMap[$dateKey])) {
                // Maximum entre le comptage réel et le baseline pour éviter les sous-comptages
                $seriesMap[$dateKey]['favorites'] = max($seriesMap[$dateKey]['favorites'], (int) $count);
            }
        }

        // 4. Charger les contacts / demandes de visite réelles enregistrées dans visit_reservations
        $visits = VisitReservation::where('listing_id', $listing->id)
            ->whereBetween('created_at', [$startDate, Carbon::now()->endOfDay()])
            ->selectRaw('DATE(created_at) as visit_date, count(*) as count')
            ->groupBy('visit_date')
            ->pluck('count', 'visit_date');

        foreach ($visits as $dateKey => $count) {
            if (isset($seriesMap[$dateKey])) {
                $seriesMap[$dateKey]['contacts'] = max($seriesMap[$dateKey]['contacts'], (int) $count);
            }
        }

        // 5. Charger les messages liés à l'annonce (conversations rattachées à l'annonce ou métadonnées)
        $messages = Message::where(function ($query) use ($listing) {
            $query->whereHas('conversation', fn ($q) => $q->where('listing_id', $listing->id))
                ->orWhere('metadata->listing_id', (string) $listing->id);
        })
            ->whereBetween('created_at', [$startDate, Carbon::now()->endOfDay()])
            ->selectRaw('DATE(created_at) as msg_date, count(*) as count')
            ->groupBy('msg_date')
            ->pluck('count', 'msg_date');

        foreach ($messages as $dateKey => $count) {
            if (isset($seriesMap[$dateKey])) {
                $seriesMap[$dateKey]['messages'] = max($seriesMap[$dateKey]['messages'] ?? 0, (int) $count);
            }
        }

        // 6. Charger les visites payées réelles
        $paidVisits = VisitReservation::where('listing_id', $listing->id)
            ->where('fee', '>', 0)
            ->where(function ($query) {
                $query->whereNotNull('paid_at')
                    ->orWhereIn('status', [
                        VisitReservationStatus::Confirmed->value,
                        VisitReservationStatus::Requested->value,
                    ]);
            })
            ->whereBetween('created_at', [$startDate, Carbon::now()->endOfDay()])
            ->selectRaw('DATE(created_at) as visit_date, count(*) as count')
            ->groupBy('visit_date')
            ->pluck('count', 'visit_date');

        foreach ($paidVisits as $dateKey => $count) {
            if (isset($seriesMap[$dateKey])) {
                $seriesMap[$dateKey]['paid_visits'] = max($seriesMap[$dateKey]['paid_visits'] ?? 0, (int) $count);
            }
        }

        // 7. Calculer les totaux consolidés
        $totals = [
            'views' => 0,
            'video_views' => 0,
            'favorites' => 0,
            'contacts' => 0,
            'messages' => 0,
            'messages_count' => 0,
            'paid_visits' => 0,
            'paid_visits_count' => 0,
        ];

        foreach ($seriesMap as $dayData) {
            $totals['views'] += $dayData['views'];
            $totals['video_views'] += $dayData['video_views'];
            $totals['favorites'] += $dayData['favorites'];
            $totals['contacts'] += $dayData['contacts'];
            $totals['messages'] += $dayData['messages'];
            $totals['paid_visits'] += $dayData['paid_visits'];
        }

        $totals['messages_count'] = $totals['messages'];
        $totals['paid_visits_count'] = $totals['paid_visits'];

        return [
            'listing_id' => (string) $listing->id,
            'period_days' => $days,
            'series' => array_values($seriesMap),
            'totals' => $totals,
            'messages_count' => $totals['messages'],
            'paid_visits_count' => $totals['paid_visits'],
        ];
    }
}
