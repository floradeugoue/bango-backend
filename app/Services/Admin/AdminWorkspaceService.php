<?php

namespace App\Services\Admin;

use App\Enums\Support\TicketStatus;
use App\Models\Admin\AdminApproval;
use App\Models\Admin\AdminAuditLog;
use App\Models\Payment\PaymentDispute;
use App\Models\Support\SupportTicket;
use App\Models\User;
use Illuminate\Support\Carbon;

class AdminWorkspaceService
{
    /**
     * Retourne les indicateurs de l'espace de travail personnel de l'opérateur.
     *
     * @return array<string, mixed>
     */
    public function getWorkspaceData(User $operator): array
    {
        $todayStart = Carbon::today();
        $sevenDaysAgo = Carbon::today()->subDays(6);

        // 1. Métriques du jour adaptées au rôle de l'opérateur
        $assignedTickets = SupportTicket::where('assigned_to', $operator->id)
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value])
            ->count();

        $resolvedToday = SupportTicket::where('assigned_to', $operator->id)
            ->where('status', TicketStatus::Resolved->value)
            ->where('resolved_at', '>=', $todayStart)
            ->count();

        $actionsToday = AdminAuditLog::where('user_id', $operator->id)
            ->where('created_at', '>=', $todayStart)
            ->count();

        $pendingApprovalsForMe = AdminApproval::where('status', 'pending')
            ->where('requester_id', '!=', $operator->id)
            ->count();

        // 2. Éléments prioritaires (nécessitant une attention urgente)
        $priorityTickets = SupportTicket::where('assigned_to', $operator->id)
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value])
            ->where('priority', 'urgent')
            ->limit(5)
            ->get(['id', 'reference', 'subject', 'priority', 'status', 'created_at']);

        $urgentDisputes = [];
        if ($operator->hasRole('finance') || $operator->isAdmin() || $operator->hasRole('direction')) {
            $urgentDisputes = PaymentDispute::whereIn('status', ['opened', 'investigating'])
                ->orderBy('created_at', 'asc')
                ->limit(5)
                ->get(['id', 'reference', 'disputed_amount', 'currency', 'status', 'created_at']);
        }

        // 3. Volume de travail sur les 7 derniers jours (agrégation réelle journalière)
        $sevenDaysWorkload = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i);
            $dayStart = $day->copy()->startOfDay();
            $dayEnd = $day->copy()->endOfDay();

            $auditActions = AdminAuditLog::where('user_id', $operator->id)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->count();

            $sevenDaysWorkload[] = [
                'date' => $day->format('Y-m-d'),
                'day_label' => $day->locale('fr')->isoFormat('ddd'),
                'actions_count' => $auditActions,
            ];
        }

        return [
            'operator' => [
                'id' => $operator->id,
                'name' => $operator->name,
                'role' => $operator->role?->slug,
            ],
            'today_metrics' => [
                'assigned_tickets' => $assignedTickets,
                'resolved_tickets_today' => $resolvedToday,
                'audit_actions_today' => $actionsToday,
                'pending_approvals_count' => $pendingApprovalsForMe,
            ],
            'priority_items' => [
                'urgent_tickets' => $priorityTickets,
                'urgent_disputes' => $urgentDisputes,
            ],
            'seven_days_workload' => $sevenDaysWorkload,
            'workspace_updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * File des validations selon le principe des 4 yeux (l'opérateur ne peut pas valider sa propre requête).
     *
     * @return array<string, mixed>
     */
    public function getApprovalsQueue(User $operator): array
    {
        // Un opérateur ne peut approuver que les requêtes dont il n'est PAS le demandeur
        $pendingApprovals = AdminApproval::with(['requester:id,name,email', 'validator:id,name,email'])
            ->where('status', 'pending')
            ->where('requester_id', '!=', $operator->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Les requêtes initiées par cet opérateur (pour suivi de statut)
        $myRequests = AdminApproval::with(['validator:id,name,email'])
            ->where('requester_id', $operator->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return [
            'pending_for_my_approval' => $pendingApprovals->map(fn (AdminApproval $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'requester' => [
                    'id' => $a->requester?->id,
                    'name' => $a->requester?->name,
                    'email' => $a->requester?->email,
                ],
                'approvable_type' => $a->approvable_type,
                'approvable_id' => $a->approvable_id,
                'payload' => $a->payload,
                'status' => $a->status,
                'can_validate' => $a->canBeValidatedBy($operator),
                'created_at' => $a->created_at->toIso8601String(),
            ]),
            'my_submitted_requests' => $myRequests->map(fn (AdminApproval $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'status' => $a->status,
                'validator' => $a->validator ? [
                    'id' => $a->validator->id,
                    'name' => $a->validator->name,
                ] : null,
                'decision_reason' => $a->decision_reason,
                'decided_at' => $a->decided_at?->toIso8601String(),
                'created_at' => $a->created_at->toIso8601String(),
            ]),
        ];
    }
}
