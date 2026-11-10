<?php

namespace App\Services\Admin;

use App\Models\Admin\AdminAuditLog;
use App\Models\Reel\Reel;
use App\Models\Reel\Sound;
use App\Models\Square\Post;
use App\Models\Support\CopyrightClaim;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class CopyrightService
{
    /**
     * Récupère la file des revendications de droits d'auteur paginée.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getClaimsQueue(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = CopyrightClaim::query()->with(['claimant:id,name,display_name,email,country_code,city', 'resolver:id,name,email']);

        // 1. Filtre status / status[] (avec prise en charge de status=decided, status=open, status=all)
        if (! empty($filters['status'])) {
            $rawStatuses = is_array($filters['status'])
                ? $filters['status']
                : array_map('trim', explode(',', (string) $filters['status']));

            $statuses = [];
            $includeAll = false;
            foreach ($rawStatuses as $statusItem) {
                $normalized = strtolower(trim((string) $statusItem));
                if ($normalized === '') {
                    continue;
                }
                if ($normalized === 'all') {
                    $includeAll = true;
                    break;
                }
                if ($normalized === 'decided') {
                    $statuses[] = 'approved';
                    $statuses[] = 'rejected';
                } elseif ($normalized === 'open') {
                    $statuses[] = 'pending';
                    $statuses[] = 'under_review';
                } else {
                    $statuses[] = $normalized;
                }
            }

            if (! $includeAll) {
                $statuses = array_values(array_unique($statuses));
                if (! empty($statuses)) {
                    $query->whereIn('status', $statuses);
                }
            }
        } else {
            $query->whereIn('status', ['pending', 'under_review']);
        }

        // 2. Filtre claim_kind / claim_kind[] (media, trademark, text, impersonation)
        if (! empty($filters['claim_kind'])) {
            $claimKinds = is_array($filters['claim_kind'])
                ? $filters['claim_kind']
                : array_map('trim', explode(',', (string) $filters['claim_kind']));
            $claimKinds = array_values(array_filter(array_map(fn ($v) => strtolower(trim((string) $v)), $claimKinds)));

            if (! empty($claimKinds)) {
                $query->whereIn('claim_kind', $claimKinds);
            }
        }

        // 3. Filtre country / country[]
        if (! empty($filters['country'])) {
            $countries = is_array($filters['country'])
                ? $filters['country']
                : array_map('trim', explode(',', (string) $filters['country']));
            $countries = array_values(array_filter(array_map(fn ($v) => strtoupper(trim((string) $v)), $countries)));

            if (! empty($countries)) {
                $query->whereIn('country', $countries);
            }
        }

        // 4. Filtre content_type / content_type[]
        if (! empty($filters['content_type'])) {
            $contentTypes = is_array($filters['content_type'])
                ? $filters['content_type']
                : array_map('trim', explode(',', (string) $filters['content_type']));
            $contentTypes = array_values(array_filter($contentTypes));

            if (! empty($contentTypes)) {
                $query->whereIn('content_type', $contentTypes);
            }
        }

        // 5. Filtres de dates
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', Carbon::parse($filters['date_from'])->toDateString());
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', Carbon::parse($filters['date_to'])->toDateString());
        }

        // 6. Recherche plein texte
        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'LIKE', "%{$search}%")
                    ->orWhere('work_title', 'LIKE', "%{$search}%")
                    ->orWhere('claimant_name', 'LIKE', "%{$search}%")
                    ->orWhere('claimant_email', 'LIKE', "%{$search}%");
            });
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * Calcule les indicateurs agrégés (meta) des réclamations de droits :
     * totaux par statut, délai médian de décision (heures) et taux d'acceptation sur 90 jours.
     *
     * @return array<string, mixed>
     */
    public function getClaimsMeta(int $days = 90): array
    {
        $statusRows = CopyrightClaim::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $pendingCount = (int) ($statusRows['pending'] ?? 0);
        $underReviewCount = (int) ($statusRows['under_review'] ?? 0);
        $approvedCount = (int) ($statusRows['approved'] ?? 0);
        $rejectedCount = (int) ($statusRows['rejected'] ?? 0);
        $decidedCount = $approvedCount + $rejectedCount;
        $openCount = $pendingCount + $underReviewCount;
        $totalCount = $openCount + $decidedCount;

        $counts = [
            'pending' => $pendingCount,
            'under_review' => $underReviewCount,
            'approved' => $approvedCount,
            'rejected' => $rejectedCount,
            'decided' => $decidedCount,
            'open' => $openCount,
            'total' => $totalCount,
        ];

        $since90d = now()->subDays($days);

        $decided90d = CopyrightClaim::query()
            ->whereIn('status', ['approved', 'rejected'])
            ->where(function ($q) use ($since90d) {
                $q->where('resolved_at', '>=', $since90d)
                    ->orWhere(function ($sub) use ($since90d) {
                        $sub->whereNull('resolved_at')->where('created_at', '>=', $since90d);
                    });
            })
            ->get(['status', 'created_at', 'resolved_at']);

        $durationsHours = $decided90d
            ->filter(fn (CopyrightClaim $claim) => $claim->created_at !== null && $claim->resolved_at !== null)
            ->map(fn (CopyrightClaim $claim) => max(0.0, abs($claim->resolved_at->diffInSeconds($claim->created_at)) / 3600))
            ->sort()
            ->values();

        $medianDecisionHours = 0.0;
        if ($durationsHours->isNotEmpty()) {
            $count = $durationsHours->count();
            $mid = (int) floor($count / 2);
            $medianDecisionHours = round($count % 2 === 0
                ? ($durationsHours[$mid - 1] + $durationsHours[$mid]) / 2
                : $durationsHours[$mid], 1);
        }

        $totalDecided90d = $decided90d->count();
        $approved90d = $decided90d->where('status', 'approved')->count();
        $acceptRate = $totalDecided90d > 0
            ? round(($approved90d / $totalDecided90d) * 100, 1)
            : 0.0;

        return [
            'counts' => $counts,
            'by_status' => $counts,
            'pending' => $pendingCount,
            'under_review' => $underReviewCount,
            'approved' => $approvedCount,
            'rejected' => $rejectedCount,
            'decided' => $decidedCount,
            'median_decision_hours' => $medianDecisionHours,
            'accept_rate' => $acceptRate,
            'window_days' => $days,
        ];
    }

    /**
     * Statue sur une revendication de propriété intellectuelle ou demande une pièce complémentaire.
     */
    public function resolveClaim(
        string|int $claimRef,
        string $decision,
        string $decisionNotes,
        User $operator
    ): CopyrightClaim {
        $allowedDecisions = ['content_removed', 'sound_muted', 'dismissed', 'request_info'];
        if (! in_array($decision, $allowedDecisions, true)) {
            throw new InvalidArgumentException("Décision '{$decision}' invalide (autorisées: content_removed, sound_muted, dismissed, request_info).");
        }

        if (empty(trim($decisionNotes))) {
            throw new InvalidArgumentException('Une justification ou un message est requis pour traiter une revendication.');
        }

        return DB::transaction(function () use ($claimRef, $decision, $decisionNotes, $operator) {
            /** @var CopyrightClaim $claim */
            $claim = CopyrightClaim::query()
                ->where('reference', $claimRef)
                ->orWhere('id', is_numeric($claimRef) ? (int) $claimRef : 0)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($claim->status, ['approved', 'rejected'], true)) {
                throw new InvalidArgumentException("La revendication {$claim->reference} a déjà été résolue.");
            }

            if ($decision === 'request_info') {
                $claim->status = 'under_review';
                $claim->decision = 'request_info';
                $claim->decision_notes = $decisionNotes;
                $claim->resolved_by = $operator->id;
                $claim->resolved_at = null;
                $claim->save();

                if (! empty($claim->claimant_email)) {
                    try {
                        Mail::raw(
                            "Bonjour {$claim->claimant_name},\n\nConcernant votre réclamation {$claim->reference} ({$claim->work_title}), notre équipe a besoin d'éléments complémentaires :\n\n{$decisionNotes}\n\nCordialement,\nL'équipe Bango",
                            function ($mail) use ($claim) {
                                $mail->to($claim->claimant_email)
                                    ->subject("Demande de pièce complémentaire — Réclamation {$claim->reference}");
                            }
                        );
                    } catch (\Throwable $e) {
                        Log::warning('Échec envoi email request_info copyright : '.$e->getMessage());
                    }
                }

                AdminAuditLog::logEvent('moderation.copyright.request_info', $operator, request()->ip(), request()->userAgent(), [
                    'reference' => $claim->reference,
                    'decision' => $decision,
                    'message' => $decisionNotes,
                ]);

                return $claim;
            }

            // Application des effets sur le contenu ou son média
            if ($decision === 'content_removed') {
                if ($claim->content_type === 'post') {
                    $post = Post::find($claim->content_id);
                    $post?->delete();
                } elseif ($claim->content_type === 'reel') {
                    $reel = Reel::find($claim->content_id);
                    $reel?->delete();
                } elseif ($claim->content_type === 'sound') {
                    $sound = Sound::find($claim->content_id);
                    if ($sound) {
                        $sound->is_removed = true;
                        $sound->is_published = false;
                        $sound->save();
                    }
                }
                $claim->status = 'approved';
            } elseif ($decision === 'sound_muted') {
                if ($claim->content_type === 'reel') {
                    $reel = Reel::find($claim->content_id);
                    if ($reel) {
                        $reel->sound_id = null;
                        $reel->save();
                    }
                } elseif ($claim->content_type === 'sound') {
                    $sound = Sound::find($claim->content_id);
                    if ($sound) {
                        $sound->is_locked = true;
                        $sound->is_published = false;
                        $sound->rights_status = 'disputed';
                        $sound->save();
                    }
                }
                $claim->status = 'approved';
            } else {
                // dismissed -> revendication rejetée
                $claim->status = 'rejected';
            }

            $claim->decision = $decision;
            $claim->decision_notes = $decisionNotes;
            $claim->resolved_by = $operator->id;
            $claim->resolved_at = now();
            $claim->save();

            AdminAuditLog::logEvent('moderation.copyright.decision', $operator, request()->ip(), request()->userAgent(), [
                'reference' => $claim->reference,
                'decision' => $decision,
                'content_type' => $claim->content_type,
                'content_id' => $claim->content_id,
            ]);

            return $claim;
        });
    }
}
