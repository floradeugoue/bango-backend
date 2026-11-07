<?php

namespace App\Services\Admin;

use App\Enums\Support\AppealStatus;
use App\Enums\Support\ReportReason;
use App\Enums\Support\ReportStatus;
use App\Models\Admin\AdminAuditLog;
use App\Models\Support\ModerationAppeal;
use App\Models\Support\ModerationDecision;
use App\Models\Support\ModerationRule;
use App\Models\Support\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class ModerationRuleService
{
    /**
     * Retourne la liste de toutes les règles de modération configurées avec leurs statistiques consolidées.
     */
    public function getRules(?bool $activeOnly = null): Collection
    {
        $query = ModerationRule::query()->with(['creator:id,name,email', 'updater:id,name,email']);

        if ($activeOnly !== null) {
            $query->where('is_active', $activeOnly);
        }

        $rules = $query->orderBy('code', 'asc')->get();

        // Calcul des statistiques par motif (this_month, prev_month, pending, appeal_rate, overturn_rate)
        $startThisMonth = now()->startOfMonth();
        $startPrevMonth = now()->subMonth()->startOfMonth();
        $endPrevMonth = now()->subMonth()->endOfMonth();

        // 1. Signalements ce mois groupés par motif
        $thisMonthReports = Report::where('created_at', '>=', $startThisMonth)
            ->groupBy('reason')
            ->selectRaw('reason, count(*) as count')
            ->get();

        // 2. Signalements mois précédent groupés par motif
        $prevMonthReports = Report::whereBetween('created_at', [$startPrevMonth, $endPrevMonth])
            ->groupBy('reason')
            ->selectRaw('reason, count(*) as count')
            ->get();

        // 3. Signalements en attente groupés par motif
        $pendingReports = Report::whereIn('status', [ReportStatus::Pending->value, ReportStatus::Investigating->value])
            ->groupBy('reason')
            ->selectRaw('reason, count(*) as count')
            ->get();

        // 4. Décisions totales par motif de signalement
        $decisionsByReason = ModerationDecision::join('reports', 'reports.id', '=', 'moderation_decisions.report_id')
            ->groupBy('reports.reason')
            ->selectRaw('reports.reason, count(moderation_decisions.id) as count')
            ->get();

        // 5. Recours par motif de signalement (total recours et recours acceptés/overturned)
        $appealsByReason = ModerationAppeal::query()
            ->join('moderation_decisions', 'moderation_decisions.id', '=', 'moderation_appeals.moderation_decision_id')
            ->join('reports', 'reports.id', '=', 'moderation_decisions.report_id')
            ->groupBy('reports.reason')
            ->selectRaw('reports.reason, count(moderation_appeals.id) as total_appeals, sum(case when moderation_appeals.status = ? then 1 else 0 end) as approved_appeals', [AppealStatus::Approved->value])
            ->get();

        $normalizeReason = fn ($r) => $r instanceof ReportReason ? $r->value : (string) $r;

        $thisMonthMap = [];
        foreach ($thisMonthReports as $row) {
            $thisMonthMap[$normalizeReason($row->reason)] = (int) $row->count;
        }

        $prevMonthMap = [];
        foreach ($prevMonthReports as $row) {
            $prevMonthMap[$normalizeReason($row->reason)] = (int) $row->count;
        }

        $pendingMap = [];
        foreach ($pendingReports as $row) {
            $pendingMap[$normalizeReason($row->reason)] = (int) $row->count;
        }

        $decisionsMap = [];
        foreach ($decisionsByReason as $row) {
            $decisionsMap[$normalizeReason($row->reason)] = (int) $row->count;
        }

        $appealsMap = [];
        foreach ($appealsByReason as $row) {
            $appealsMap[$normalizeReason($row->reason)] = [
                'total' => (int) $row->total_appeals,
                'approved' => (int) $row->approved_appeals,
            ];
        }

        foreach ($rules as $rule) {
            $code = $rule->code;
            $appReason = $rule->app_reason;

            $thisMonth = (int) ($thisMonthMap[$code] ?? $thisMonthMap[$appReason] ?? 0);
            $prevMonth = (int) ($prevMonthMap[$code] ?? $prevMonthMap[$appReason] ?? 0);
            $pending = (int) ($pendingMap[$code] ?? $pendingMap[$appReason] ?? 0);

            $decisionsCount = (int) ($decisionsMap[$code] ?? $decisionsMap[$appReason] ?? 0);
            $appealData = $appealsMap[$code] ?? $appealsMap[$appReason] ?? ['total' => 0, 'approved' => 0];

            $totalAppeals = (int) $appealData['total'];
            $approvedAppeals = (int) $appealData['approved'];

            $appealRate = $decisionsCount > 0 ? round(($totalAppeals / $decisionsCount) * 100, 1) : 0.0;
            $overturnRate = $totalAppeals > 0 ? round(($approvedAppeals / $totalAppeals) * 100, 1) : 0.0;

            $rule->setAttribute('stats', [
                'this_month' => $thisMonth,
                'prev_month' => $prevMonth,
                'pending' => $pending,
                'appeal_rate' => $appealRate,
                'overturn_rate' => $overturnRate,
            ]);
        }

        return $rules;
    }

    /**
     * Crée une nouvelle règle de modération.
     *
     * @param  array<string, mixed>  $data
     */
    public function createRule(array $data, User $operator): ModerationRule
    {
        if (ModerationRule::where('code', $data['code'])->exists()) {
            throw new InvalidArgumentException("Une règle avec le code '{$data['code']}' existe déjà.");
        }

        $rule = ModerationRule::create([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'app_reason' => $data['app_reason'] ?? null,
            'severity' => $data['severity'] ?? 'medium',
            'default_action' => $data['default_action'] ?? 'hide',
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $operator->id,
            'updated_by' => $operator->id,
        ]);

        AdminAuditLog::logEvent('moderation.rule.created', $operator, request()->ip(), request()->userAgent(), [
            'code' => $rule->code,
            'name' => $rule->name,
        ]);

        return $rule;
    }

    /**
     * Met à jour une règle de modération existante.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateRule(string $reasonCode, array $data, User $operator): ModerationRule
    {
        /** @var ModerationRule $rule */
        $rule = ModerationRule::where('code', $reasonCode)->firstOrFail();

        $rule->fill(array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'app_reason' => $data['app_reason'] ?? null,
            'severity' => $data['severity'] ?? null,
            'default_action' => $data['default_action'] ?? null,
        ]));

        if (isset($data['is_active'])) {
            $rule->is_active = (bool) $data['is_active'];
        }

        $rule->updated_by = $operator->id;
        $rule->save();

        AdminAuditLog::logEvent('moderation.rule.updated', $operator, request()->ip(), request()->userAgent(), [
            'code' => $rule->code,
            'changes' => $data,
        ]);

        return $rule;
    }

    /**
     * Retourne la table de correspondance officielle entre les motifs de l'application et les motifs internes.
     *
     * @return array<string, mixed>
     */
    public function getAppReasonsMapping(): array
    {
        $appReasons = ReportReason::cases();
        $mapping = [];

        foreach ($appReasons as $reason) {
            $rule = ModerationRule::where('app_reason', $reason->value)
                ->orWhere('code', $reason->value)
                ->first();

            $mapping[] = [
                'app_reason_code' => $reason->value,
                'app_reason_label' => $reason->label(),
                'internal_rule_code' => $rule?->code ?? $reason->value,
                'internal_rule_name' => $rule?->name ?? $reason->label(),
                'severity' => $rule?->severity ?? 'medium',
                'default_action' => $rule?->default_action ?? 'hide',
                'is_active' => $rule ? $rule->is_active : true,
            ];
        }

        return $mapping;
    }
}
