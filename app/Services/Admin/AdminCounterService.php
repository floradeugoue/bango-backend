<?php

namespace App\Services\Admin;

use App\Enums\Admin\AdminPermission;
use App\Enums\Kyc\KycStatus;
use App\Enums\Support\ReportStatus;
use App\Enums\Support\TicketStatus;
use App\Models\Kyc\KycSubmission;
use App\Models\Payment\PaymentDispute;
use App\Models\Payment\PaymentTransaction;
use App\Models\Support\Report;
use App\Models\Support\SupportTicket;
use App\Models\User;

class AdminCounterService
{
    /**
     * Calcule les compteurs administratifs réels en respectant les permissions de l'opérateur.
     *
     * @return array<string, mixed>
     */
    public function getCounters(User $operator): array
    {
        $counters = [];

        // 1. Modération en attente (ReportStatus::Pending)
        if ($operator->hasPermission(AdminPermission::ModerationView->value)) {
            $counters['moderation_pending'] = Report::where('status', ReportStatus::Pending->value)->count();
            $counters['moderation_overdue'] = Report::whereIn('status', [
                ReportStatus::Pending->value,
                ReportStatus::Investigating->value,
            ])->where('created_at', '<', now()->subHours(24))->count();
        }

        // 2. Dossiers KYC en attente (KycStatus::Pending)
        if ($operator->hasPermission(AdminPermission::VerificationView->value)) {
            $counters['kyc_pending'] = KycSubmission::where('status', KycStatus::Pending->value)
                ->whereHas('user')
                ->count();
            $counters['kyc_overdue'] = KycSubmission::where('status', KycStatus::Pending->value)
                ->whereHas('user')
                ->where('created_at', '<', now()->subHours(48))
                ->count();
        }

        // 3. Paiements nécessitant une intervention (litiges ouverts + transactions en attente de revue manuelle)
        if ($operator->hasPermission(AdminPermission::FinanceView->value)) {
            $disputesCount = PaymentDispute::whereIn('status', ['opened', 'pending', 'investigating'])->count();
            $manualReviewCount = PaymentTransaction::where('status', 'pending_manual_review')->count();

            $counters['payments_intervention_required'] = $disputesCount + $manualReviewCount;
            $counters['payments_disputes_open'] = $disputesCount;
        }

        // 4. Tickets de support en attente (TicketStatus::Open ou InProgress)
        if ($operator->hasPermission(AdminPermission::SupportView->value)) {
            $counters['support_tickets_pending'] = SupportTicket::whereIn('status', [
                TicketStatus::Open->value,
                TicketStatus::InProgress->value,
            ])->count();
            $counters['support_overdue'] = SupportTicket::whereIn('status', [
                TicketStatus::Open->value,
                TicketStatus::InProgress->value,
            ])->where('created_at', '<', now()->subHours(24))->count();
        }

        $counters['overdue_total'] = ($counters['moderation_overdue'] ?? 0)
            + ($counters['kyc_overdue'] ?? 0)
            + ($counters['support_overdue'] ?? 0);

        return [
            'operator_id' => $operator->id,
            'role' => $operator->role?->slug,
            'counters' => $counters,
            'calculated_at' => now()->toIso8601String(),
        ];
    }
}
