<?php

namespace App\Services\Admin\Stats;

use App\Enums\Payment\PaymentStatus;
use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\ScheduledReport;
use App\Models\Boost\CreditWallet;
use App\Models\Listing\Listing;
use App\Models\Payment\LandlordPayout;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use App\Models\Support\ModerationAppeal;
use App\Models\Support\Report;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminStrategicStatsService
{
    /**
     * 6.1. Vue d'ensemble exécutive basée exclusivement sur des données réelles.
     *
     * @return array<string, mixed>
     */
    public function getOverviewStats(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? Carbon::now()->subDays(30);
        $to = $to ?? Carbon::now();

        // 1. Chiffre d'affaires
        $paymentsQuery = PaymentTransaction::whereBetween('created_at', [$from, $to]);

        $grossRevenue = (float) (clone $paymentsQuery)
            ->where('status', PaymentStatus::Confirmed)
            ->sum('amount');

        $refundsAmount = (float) (clone $paymentsQuery)
            ->where('status', PaymentStatus::Refunded)
            ->sum('amount');

        $providerFees = (float) (clone $paymentsQuery)
            ->where('status', PaymentStatus::Confirmed)
            ->sum('fee_amount');

        $netRevenue = $grossRevenue - $refundsAmount - $providerFees;

        // 2. Abonnements et Badges actifs
        $activeSubscriptions = Subscription::where('state', 'active')->count();

        $badgesBreakdown = [
            'landlord' => User::where('badge', 'landlord')->count(),
            'agent' => User::where('badge', 'agent')->count(),
            'page' => User::where('badge', 'page')->count(),
            'certified_profiles' => User::where('is_certified', true)->count(),
        ];

        // 3. Portefeuille de Crédits BANGO
        $totalCreditsInCirculation = (float) CreditWallet::sum('balance');

        // 4. Utilisateurs
        $newUsersInPeriod = User::whereBetween('created_at', [$from, $to])->count();
        $totalUsers = User::count();

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'financial' => [
                'currency' => 'XAF',
                'gross_revenue' => round($grossRevenue, 2),
                'refunds_amount' => round($refundsAmount, 2),
                'provider_fees' => round($providerFees, 2),
                'net_revenue' => round($netRevenue, 2),
            ],
            'subscriptions_and_badges' => [
                'active_subscriptions_count' => $activeSubscriptions,
                'badges_breakdown' => $badgesBreakdown,
            ],
            'credits_wallet' => [
                'total_balance_circulation' => round($totalCreditsInCirculation, 2),
            ],
            'community' => [
                'new_signups' => $newUsersInPeriod,
                'total_accounts' => $totalUsers,
            ],
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * 6.2. Entonnoir d'acquisition et conversion vers les offres payantes.
     *
     * @return array<string, mixed>
     */
    public function getAcquisitionFunnel(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? Carbon::now()->subDays(30);
        $to = $to ?? Carbon::now();

        $signups = User::whereBetween('created_at', [$from, $to])->count();

        $phoneVerified = User::whereBetween('created_at', [$from, $to])
            ->whereNotNull('phone_verified_at')
            ->count();

        $kycVerified = User::whereBetween('created_at', [$from, $to])
            ->where('is_verified', true)
            ->count();

        $paidUsers = PaymentTransaction::whereBetween('created_at', [$from, $to])
            ->where('status', PaymentStatus::Confirmed)
            ->distinct('user_id')
            ->count('user_id');

        $activeSubscribers = Subscription::whereBetween('created_at', [$from, $to])
            ->where('state', 'active')
            ->distinct('user_id')
            ->count('user_id');

        $safeDenominator = max($signups, 1);

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'funnel_steps' => [
                [
                    'step' => 'signups',
                    'label' => 'Inscriptions totales',
                    'count' => $signups,
                    'conversion_rate_from_start' => 100.0,
                ],
                [
                    'step' => 'phone_verified',
                    'label' => 'Téléphone vérifié (OTP)',
                    'count' => $phoneVerified,
                    'conversion_rate_from_start' => round(($phoneVerified / $safeDenominator) * 100, 2),
                ],
                [
                    'step' => 'kyc_verified',
                    'label' => 'Identité KYC validée',
                    'count' => $kycVerified,
                    'conversion_rate_from_start' => round(($kycVerified / $safeDenominator) * 100, 2),
                ],
                [
                    'step' => 'first_payment',
                    'label' => 'Premier paiement validé',
                    'count' => $paidUsers,
                    'conversion_rate_from_start' => round(($paidUsers / $safeDenominator) * 100, 2),
                ],
                [
                    'step' => 'active_subscription',
                    'label' => 'Abonnement récurrent actif',
                    'count' => $activeSubscribers,
                    'conversion_rate_from_start' => round(($activeSubscribers / $safeDenominator) * 100, 2),
                ],
            ],
        ];
    }

    /**
     * 6.3. Analyse fine du chiffre d'affaires par produit, par pays et frais opérateurs.
     *
     * @return array<string, mixed>
     */
    public function getRevenueBreakdown(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? Carbon::now()->subDays(30);
        $to = $to ?? Carbon::now();

        $transactions = PaymentTransaction::whereBetween('created_at', [$from, $to])
            ->whereIn('status', [PaymentStatus::Confirmed, PaymentStatus::Refunded])
            ->get();

        $gross = (float) $transactions->where('status', PaymentStatus::Confirmed)->sum('amount');
        $refunds = (float) $transactions->where('status', PaymentStatus::Refunded)->sum('amount');
        $fees = (float) $transactions->where('status', PaymentStatus::Confirmed)->sum('fee_amount');

        // Répartition par type de service
        $byService = $transactions->where('status', PaymentStatus::Confirmed)
            ->groupBy('service_type')
            ->map(fn ($group, $key) => [
                'service_type' => (string) $key,
                'count' => $group->count(),
                'total_amount' => round((float) $group->sum('amount'), 2),
            ])
            ->values();

        // Répartition par devise / pays
        $byCurrency = $transactions->where('status', PaymentStatus::Confirmed)
            ->groupBy('currency')
            ->map(fn ($group, $cur) => [
                'currency' => $cur,
                'count' => $group->count(),
                'total_amount' => round((float) $group->sum('amount'), 2),
            ])
            ->values();

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'financial_summary' => [
                'gross_revenue' => round($gross, 2),
                'refunds' => round($refunds, 2),
                'operator_fees' => round($fees, 2),
                'net_revenue' => round($gross - $refunds - $fees, 2),
            ],
            'by_service_type' => $byService,
            'by_currency' => $byCurrency,
        ];
    }

    /**
     * 6.4. Indicateurs de modération (délais médians, volumes, contestations).
     *
     * @return array<string, mixed>
     */
    public function getModerationStats(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? Carbon::now()->subDays(30);
        $to = $to ?? Carbon::now();

        $reportsQuery = Report::whereBetween('created_at', [$from, $to]);

        $totalReports = (clone $reportsQuery)->count();
        $resolvedReports = (clone $reportsQuery)->whereIn('status', ['resolved', 'dismissed'])->count();
        $pendingReports = (clone $reportsQuery)->whereIn('status', ['pending', 'investigating'])->count();

        // Calcul du délai médian de résolution en heures
        $durations = (clone $reportsQuery)->whereNotNull('resolved_at')
            ->get()
            ->map(function ($report) {
                return $report->created_at->diffInHours($report->resolved_at);
            })
            ->sort()
            ->values();

        $medianDurationHours = 0.0;
        $countDurations = $durations->count();
        if ($countDurations > 0) {
            $middle = (int) floor($countDurations / 2);
            $medianDurationHours = ($countDurations % 2 === 0)
                ? ($durations[$middle - 1] + $durations[$middle]) / 2.0
                : (float) $durations[$middle];
        }

        // Contestations (appeals)
        $appealsQuery = ModerationAppeal::whereBetween('created_at', [$from, $to]);
        $totalAppeals = (clone $appealsQuery)->count();
        $approvedAppeals = (clone $appealsQuery)->where('status', 'approved')->count();

        $appealRate = $resolvedReports > 0 ? round(($totalAppeals / $resolvedReports) * 100, 2) : 0.0;
        $overturnRate = $totalAppeals > 0 ? round(($approvedAppeals / $totalAppeals) * 100, 2) : 0.0;

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'reports_total' => $totalReports,
            'reports_resolved' => $resolvedReports,
            'reports_pending' => $pendingReports,
            'median_resolution_time_hours' => round($medianDurationHours, 1),
            'appeals_total' => $totalAppeals,
            'appeals_approved' => $approvedAppeals,
            'appeal_rate_percentage' => $appealRate,
            'overturn_rate_percentage' => $overturnRate,
        ];
    }

    /**
     * 6.5. Rétention et analyse des cohortes d'utilisateurs.
     *
     * @return array<string, mixed>
     */
    public function getRetentionCohorts(int $months = 6): array
    {
        $cohorts = [];
        $startDate = Carbon::now()->subMonths($months)->startOfMonth();

        for ($i = 0; $i < $months; $i++) {
            $cohortStart = (clone $startDate)->addMonths($i);
            $cohortEnd = (clone $cohortStart)->endOfMonth();

            $cohortUsers = User::whereBetween('created_at', [$cohortStart, $cohortEnd])->get();
            $cohortSize = $cohortUsers->count();

            if ($cohortSize === 0) {
                continue;
            }

            // Calcul du taux d'activité par mois suivant
            $retentionRates = [];
            for ($m = 0; $m <= 3; $m++) {
                $checkStart = (clone $cohortStart)->addMonths($m);
                $checkEnd = (clone $checkStart)->endOfMonth();

                if ($checkStart->isFuture()) {
                    break;
                }

                if ($m === 0) {
                    $retentionRates['M0'] = 100.0;
                } else {
                    $activeCount = User::whereIn('id', $cohortUsers->pluck('id'))
                        ->where('updated_at', '>=', $checkStart)
                        ->count();
                    $retentionRates["M{$m}"] = round(($activeCount / $cohortSize) * 100, 1);
                }
            }

            $cohorts[] = [
                'cohort' => $cohortStart->format('Y-m'),
                'size' => $cohortSize,
                'retention' => $retentionRates,
            ];
        }

        return [
            'months_analyzed' => $months,
            'cohorts' => $cohorts,
            'retention_definition' => [
                'cohort_start' => 'Mois d\'inscription de l\'utilisateur (User::created_at)',
                'active_event' => 'Mise à jour d\'activité ou transaction (User::updated_at)',
                'denominator' => 'Nombre total d\'inscriptions dans la cohorte du mois M0',
            ],
        ];
    }

    /**
     * 6.6. Performance immobilière (biens loués, visites payées, reversements).
     *
     * @return array<string, mixed>
     */
    public function getRealEstateStats(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? Carbon::now()->subDays(30);
        $to = $to ?? Carbon::now();

        $listingsCount = Listing::whereBetween('created_at', [$from, $to])->count();
        $rentedListingsCount = Listing::whereBetween('created_at', [$from, $to])
            ->where('status', 'rented')
            ->count();

        // Paiements de visites
        $visitPayments = PaymentTransaction::whereBetween('created_at', [$from, $to])
            ->where('service_type', 'visit')
            ->where('status', PaymentStatus::Confirmed)
            ->get();

        $visitsPaidCount = $visitPayments->count();
        $visitsPaidTotal = (float) $visitPayments->sum('amount');

        // Reversements aux bailleurs
        $payouts = LandlordPayout::whereBetween('created_at', [$from, $to])
            ->where('status', 'processed')
            ->get();

        $payoutsCount = $payouts->count();
        $payoutsTotal = (float) $payouts->sum('net_amount');

        $activeListings = Listing::where('status', 'active')->count();

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'rented_properties_count' => $rentedListingsCount,
            'total_active_listings' => $activeListings,
            'visits_kpis' => [
                'paid_count' => $visitsPaidCount,
                'total_volume' => round($visitsPaidTotal, 2),
            ],
            'landlord_payouts_kpis' => [
                'executed_count' => $payoutsCount,
                'executed_amount' => round($payoutsTotal, 2),
            ],
            'listings' => [
                'published_in_period' => $listingsCount,
                'marked_rented' => $rentedListingsCount,
                'rental_conversion_rate' => $listingsCount > 0 ? round(($rentedListingsCount / $listingsCount) * 100, 2) : 0.0,
            ],
            'visits' => [
                'paid_count' => $visitsPaidCount,
                'total_volume' => round($visitsPaidTotal, 2),
            ],
            'landlord_payouts' => [
                'executed_count' => $payoutsCount,
                'executed_amount' => round($payoutsTotal, 2),
            ],
        ];
    }

    // =========================================================================
    // 6.7. RAPPORTS PÉRIODIQUES
    // =========================================================================

    public function getScheduledReports(): Collection
    {
        return ScheduledReport::with('updatedBy:id,name')->get();
    }

    public function updateScheduledReport(int $id, array $data, User $operator): ScheduledReport
    {
        return DB::transaction(function () use ($id, $data, $operator) {
            $report = ScheduledReport::findOrFail($id);

            if (isset($data['name'])) {
                $report->name = $data['name'];
            }
            if (isset($data['cadence'])) {
                $report->cadence = $data['cadence'];
            }
            if (isset($data['recipients'])) {
                $report->recipients = $data['recipients'];
            }
            if (isset($data['metrics'])) {
                $report->metrics = $data['metrics'];
            }
            if (isset($data['format'])) {
                $report->format = $data['format'];
            }
            if (isset($data['is_active'])) {
                $report->is_active = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
            }
            $report->updated_by_id = $operator->id;
            $report->save();

            AdminAuditLog::logChainedEvent(
                event: 'stats.report_configured',
                operator: $operator,
                targetType: 'ScheduledReport',
                targetId: (string) $report->id,
                targetLabel: "Configuration du rapport périodique {$report->name}",
                context: $data
            );

            return $report->fresh('updatedBy');
        });
    }

    // =========================================================================
    // 6.8. EXPORTS STATISTIQUES
    // =========================================================================

    /**
     * Prévisualisation des données statistiques à exporter.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function previewStatsExport(string $domain, array $filters): array
    {
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(30);
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to']) : now();

        $data = match ($domain) {
            'overview' => $this->getOverviewStats($from, $to),
            'acquisition' => $this->getAcquisitionFunnel($from, $to),
            'revenue' => $this->getRevenueBreakdown($from, $to),
            'moderation' => $this->getModerationStats($from, $to),
            'retention' => $this->getRetentionCohorts(6),
            'immo' => $this->getRealEstateStats($from, $to),
            default => throw new DomainException("Domaine d'export statistique inconnu : {$domain}"),
        };

        return [
            'domain' => $domain,
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'preview_data' => $data,
        ];
    }

    /**
     * Génération de l'export CSV statistique agrégé avec protection anti-injection.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportStatsCsv(string $domain, array $filters, User $operator): StreamedResponse
    {
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(30);
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to']) : now();

        $filename = "bango_stats_{$domain}_".date('Ymd_His').'.csv';

        AdminAuditLog::logChainedEvent(
            event: 'stats.export_generated',
            operator: $operator,
            targetType: 'StatsExport',
            targetId: $domain,
            targetLabel: "Export statistique domaine {$domain}",
            context: array_merge($filters, ['domain' => $domain])
        );

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->stream(function () use ($domain, $from, $to) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

            fputcsv($handle, ['# BANGO STATISTICAL EXPORT', strtoupper($domain)]);
            fputcsv($handle, ['# PÉRIODE', $from->toDateString(), 'AU', $to->toDateString()]);
            fputcsv($handle, ['Métrique / Indicateur', 'Valeur', 'Précision / Devise']);

            $data = match ($domain) {
                'overview' => $this->getOverviewStats($from, $to),
                'acquisition' => $this->getAcquisitionFunnel($from, $to),
                'revenue' => $this->getRevenueBreakdown($from, $to),
                'moderation' => $this->getModerationStats($from, $to),
                'retention' => $this->getRetentionCohorts(6),
                'immo' => $this->getRealEstateStats($from, $to),
                default => [],
            };

            $this->flattenAndWriteCsv($handle, $data);

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Aplatit récursivement les données statistiques pour les lignes CSV.
     *
     * @param  resource  $handle
     * @param  array<string, mixed>  $data
     */
    protected function flattenAndWriteCsv($handle, array $data, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            $label = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $this->flattenAndWriteCsv($handle, $value, $label);
            } else {
                fputcsv($handle, [
                    $this->sanitizeCsvField($label),
                    $this->sanitizeCsvField((string) $value),
                    '',
                ]);
            }
        }
    }

    /**
     * Neutralise les formules malveillantes (CSV Injection).
     */
    protected function sanitizeCsvField(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
