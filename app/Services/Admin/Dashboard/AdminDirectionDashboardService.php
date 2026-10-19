<?php

namespace App\Services\Admin\Dashboard;

use App\Models\Admin\AdminApproval;
use App\Models\Admin\AdminAuditExport;
use App\Models\Admin\AdminAuditLog;
use App\Models\Geo\Operator;
use App\Models\User;
use App\Services\Admin\Settings\AdminSystemSettingsService;
use App\Services\Admin\Stats\AdminStrategicStatsService;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminDirectionDashboardService
{
    public function __construct(
        protected AdminStrategicStatsService $statsService,
        protected AdminSystemSettingsService $settingsService
    ) {}

    /**
     * 7.1. Synthèse exécutive globale pour la Direction.
     *
     * @return array<string, mixed>
     */
    public function getExecutiveDashboard(User $operator): array
    {
        $overviewStats = $this->statsService->getOverviewStats();
        $serviceStatus = $this->settingsService->getServiceStatus();
        $chainIntegrity = AdminAuditLog::verifyChain(200);

        $pendingApprovals = AdminApproval::where('status', 'pending')->count();
        $activeOperators = Operator::where('is_active', true)->count();
        $suspendedOperators = Operator::where('is_active', false)->count();

        $recentAuditLogs = AdminAuditLog::with('user:id,name')
            ->orderBy('id', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'event' => $log->event,
                'operator' => $log->user?->name ?? 'Système',
                'target' => $log->target_label ?? $log->target_type,
                'hash' => $log->hash,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return [
            'operator' => [
                'id' => $operator->id,
                'name' => $operator->name,
                'email' => $operator->email,
                'role' => $operator->role?->name ?? 'Direction',
            ],
            'kpis' => $overviewStats,
            'system_health' => [
                'service' => $serviceStatus,
                'audit_chain_status' => $chainIntegrity,
                'active_telecom_operators' => $activeOperators,
                'suspended_telecom_operators' => $suspendedOperators,
            ],
            'governance' => [
                'pending_double_approvals_count' => $pendingApprovals,
                'recent_critical_audit_logs' => $recentAuditLogs,
            ],
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * 7.2. Export du tableau de bord exécutif en CSV sécurisé.
     */
    public function exportDashboardCsv(User $operator): StreamedResponse
    {
        $dashboardData = $this->getExecutiveDashboard($operator);
        $filename = 'bango_direction_dashboard_'.date('Ymd_His').'.csv';

        AdminAuditLog::logChainedEvent(
            event: 'dashboard.exported',
            operator: $operator,
            targetType: 'Dashboard',
            targetId: (string) $operator->id,
            targetLabel: 'Export exécutif du tableau de bord Direction',
            context: ['filename' => $filename]
        );

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->stream(function () use ($dashboardData) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

            fputcsv($handle, ['# BANGO DIRECTION DASHBOARD EXPORT']);
            fputcsv($handle, ['Généré le', $dashboardData['generated_at']]);
            fputcsv($handle, ['Opérateur', $dashboardData['operator']['name'], $dashboardData['operator']['email']]);
            fputcsv($handle, []);
            fputcsv($handle, ['Section', 'Indicateur', 'Valeur']);

            // Écriture aplatie des métriques du dashboard
            $financial = $dashboardData['kpis']['financial'];
            fputcsv($handle, ['Finances', 'Chiffre d\'Affaires Brut', $financial['gross_revenue']]);
            fputcsv($handle, ['Finances', 'Remboursements', $financial['refunds_amount']]);
            fputcsv($handle, ['Finances', 'Frais Opérateurs', $financial['provider_fees']]);
            fputcsv($handle, ['Finances', 'Chiffre d\'Affaires Net', $financial['net_revenue']]);

            $comm = $dashboardData['kpis']['community'];
            fputcsv($handle, ['Communauté', 'Nouveaux Utilisateurs (30j)', $comm['new_signups']]);
            fputcsv($handle, ['Communauté', 'Total Comptes', $comm['total_accounts']]);

            $gov = $dashboardData['governance'];
            fputcsv($handle, ['Gouvernance', 'Demandes en attente (4 yeux)', $gov['pending_double_approvals_count']]);

            $chain = $dashboardData['system_health']['audit_chain_status'];
            fputcsv($handle, ['Intégrité Audit', 'Chaîne Cryptographique Valide', $chain['is_valid'] ? 'OUI' : 'NON']);

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * 7.3. Consultation des métadonnées d'un export généré.
     *
     * @return array<string, mixed>
     */
    public function getExportDetails(int $id, User $operator): array
    {
        $export = AdminAuditExport::with('requester:id,name,email')->findOrFail($id);

        return [
            'id' => $export->id,
            'filename' => $export->filename,
            'records_count' => $export->records_count,
            'sha256_seal' => $export->sha256_seal,
            'status' => $export->status,
            'expires_at' => $export->expires_at?->toIso8601String(),
            'is_expired' => $export->isExpired(),
            'requester' => $export->requester ? [
                'id' => $export->requester->id,
                'name' => $export->requester->name,
                'email' => $export->requester->email,
            ] : null,
            'created_at' => $export->created_at?->toIso8601String(),
        ];
    }

    /**
     * 7.4. Statut d'onboarding de l'opérateur connecté.
     *
     * @return array<string, mixed>
     */
    public function getOnboardingStatus(User $operator): array
    {
        $role = $operator->role;
        $twoFactorEnabled = ! empty($operator->two_factor_secret) || (bool) $operator->two_factor_confirmed_at;

        return [
            'operator_id' => $operator->id,
            'name' => $operator->name,
            'email' => $operator->email,
            'role' => $role?->name ?? 'Direction',
            'role_slug' => $role?->slug ?? 'direction',
            'permissions_count' => count($role?->permissions ?? []),
            'is_direction' => $role?->slug === 'direction' || $role?->slug === 'admin',
            'security_checklist' => [
                'account_active' => $operator->status === 'active',
                'two_factor_configured' => $twoFactorEnabled,
                'corporate_email_domain' => str_ends_with((string) $operator->email, '@bango.app'),
                'session_active' => true,
            ],
            'onboarding_completed' => true,
        ];
    }
}
