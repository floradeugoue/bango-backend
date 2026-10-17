<?php

namespace App\Services\Admin;

use App\Enums\Boost\CampaignStatus;
use App\Enums\Support\AppealStatus;
use App\Models\Admin\AdminAuditLog;
use App\Models\Boost\Campaign;
use App\Models\Page\Page;
use App\Models\Support\ModerationAppeal;
use App\Models\Support\Report;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ModerationAppealAdminService
{
    /**
     * Retourne la file des recours administratifs paginée avec filtres.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getAppealsQueue(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = ModerationAppeal::query()->with([
            'user:id,name,email,status',
            'reviewer:id,name,email',
            'sanctionOperator:id,name,email',
        ]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', AppealStatus::Pending->value);
        }

        if (! empty($filters['sanction_type'])) {
            $query->where('sanction_type', $filters['sanction_type']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', Carbon::parse($filters['date_from'])->toDateString());
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', Carbon::parse($filters['date_to'])->toDateString());
        }

        // Filtre SLA 72h (urgents ou arrivant à échéance)
        if (! empty($filters['due_soon']) && $filters['due_soon'] === true) {
            $query->where('created_at', '<=', now()->subHours(48))
                ->where('created_at', '>=', now()->subHours(72));
        }

        return $query->orderBy('created_at', 'asc')->paginate($perPage);
    }

    /**
     * Résout un recours avec décision explicite (maintien ou levée de la sanction).
     */
    public function resolveAppeal(
        string|int $appealRef,
        string $resolution,
        string $decisionNotes,
        User $operator
    ): ModerationAppeal {
        $allowedResolutions = ['upheld', 'revoked'];
        if (! in_array($resolution, $allowedResolutions, true)) {
            throw new InvalidArgumentException("Résolution '{$resolution}' invalide (autorisées: upheld, revoked).");
        }

        if (empty(trim($decisionNotes))) {
            throw new InvalidArgumentException('Une justification est obligatoire pour statuer sur un recours.');
        }

        return DB::transaction(function () use ($appealRef, $resolution, $decisionNotes, $operator) {
            /** @var ModerationAppeal $appeal */
            $appeal = ModerationAppeal::query()
                ->where('reference', $appealRef)
                ->orWhere('id', is_numeric($appealRef) ? (int) $appealRef : 0)
                ->lockForUpdate()
                ->firstOrFail();

            if ($appeal->status !== AppealStatus::Pending) {
                throw new InvalidArgumentException("Le recours {$appeal->reference} a déjà été traité (statut: {$appeal->status->value}).");
            }

            // PRINCIPE DE SÉPARATION DES RÔLES :
            // Un opérateur ne peut jamais réexaminer une décision ou sanction dont il est lui-même l'auteur !
            if ($appeal->sanction_operator_id && (int) $appeal->sanction_operator_id === (int) $operator->id) {
                throw new AccessDeniedHttpException('Principe de séparation des rôles : vous ne pouvez pas réexaminer un recours portant sur votre propre sanction initiale.');
            }

            // Vérification croisée si la référence de sanction correspond à un rapport
            if ($appeal->sanction_reference) {
                $initialReport = Report::where('reference', $appeal->sanction_reference)
                    ->orWhere('id', is_numeric($appeal->sanction_reference) ? (int) $appeal->sanction_reference : 0)
                    ->first();

                if ($initialReport && $initialReport->resolved_by && (int) $initialReport->resolved_by === (int) $operator->id) {
                    throw new AccessDeniedHttpException('Principe de séparation des rôles : vous ne pouvez pas réexaminer un recours portant sur votre propre décision initiale.');
                }
            }

            $user = $appeal->user;

            // Application de la résolution
            if ($resolution === 'revoked') {
                $appeal->status = AppealStatus::Approved; // Dans l'enum AppealStatus: approved = recours accepté (sanction levée)
                $appeal->resolution = 'revoked';

                // Levée de la restriction correspondante
                if (in_array($appeal->sanction_type, ['account_suspension', 'account_suspended'], true) && $user) {
                    $user->status = 'active';
                    $user->restricted_until = null;
                    $user->suspended_from = null;
                    $user->suspended_until = null;
                    $user->save();
                } elseif ($appeal->sanction_type === 'page_suspension' && $appeal->sanction_reference) {
                    $page = Page::find($appeal->sanction_reference);
                    if ($page && $page->status === 'suspended') {
                        $page->status = 'active';
                        $page->save();
                    }
                } elseif ($appeal->sanction_type === 'content_removal' && $appeal->sanction_reference) {
                    // Restauration du contenu si aucune autre restriction active ne s'y oppose
                    $report = Report::where('reference', $appeal->sanction_reference)
                        ->orWhere('id', is_numeric($appeal->sanction_reference) ? (int) $appeal->sanction_reference : 0)
                        ->first();

                    if ($report) {
                        $targetClass = Report::resolveReportableClass($report->reportable_type);
                        if ($targetClass && class_exists($targetClass)) {
                            $target = $targetClass::withTrashed()->find($report->reportable_id);
                            if ($target && method_exists($target, 'restore')) {
                                $target->restore();
                            }
                            if ($target && isset($target->is_archived)) {
                                $target->is_archived = false;
                                $target->save();
                            }

                            // Rétablissement de la campagne Boost si présente
                            $campaign = Campaign::where('boostable_type', $target->getMorphClass())
                                ->where('boostable_id', (string) $target->getKey())
                                ->where('status', CampaignStatus::Suspended->value)
                                ->first();

                            if ($campaign) {
                                $campaign->status = CampaignStatus::Active;
                                $campaign->save();
                            }
                        }
                    }
                }
            } else {
                $appeal->status = AppealStatus::Rejected; // Maintien de la sanction
                $appeal->resolution = 'upheld';
            }

            $appeal->decision_notes = $decisionNotes;
            $appeal->reviewed_by = $operator->id;
            $appeal->reviewed_at = now();
            $appeal->save();

            AdminAuditLog::logEvent('moderation.appeal.resolved', $operator, request()->ip(), request()->userAgent(), [
                'appeal_reference' => $appeal->reference,
                'resolution' => $resolution,
                'sanction_type' => $appeal->sanction_type,
                'decision_notes' => $decisionNotes,
            ]);

            return $appeal;
        });
    }
}
