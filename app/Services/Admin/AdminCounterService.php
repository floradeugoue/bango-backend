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
        }

        // 2. Dossiers KYC en attente (KycStatus::Pending)
        if ($operator->hasPermission(AdminPermission::VerificationView->value)) {
            $counters['kyc_pending'] = KycSubmission::where('status', KycStatus::Pending->value)->count();
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
        }

        return [
            'operator_id' => $operator->id,
            'role' => $operator->role?->slug,
            'counters' => $counters,
            'calculated_at' => now()->toIso8601String(),
        ];
    }
}
