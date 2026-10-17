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
        $query = CopyrightClaim::query()->with(['claimant:id,name,email', 'resolver:id,name,email']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->whereIn('status', ['pending', 'under_review']);
        }

        if (! empty($filters['content_type'])) {
            $query->where('content_type', $filters['content_type']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', Carbon::parse($filters['date_from'])->toDateString());
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', Carbon::parse($filters['date_to'])->toDateString());
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'LIKE', "%{$search}%")
                    ->orWhere('work_title', 'LIKE', "%{$search}%")
                    ->orWhere('claimant_name', 'LIKE', "%{$search}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Statue sur une revendication de propriété intellectuelle.
     */
    public function resolveClaim(
        string|int $claimRef,
        string $decision,
        string $decisionNotes,
        User $operator
    ): CopyrightClaim {
        $allowedDecisions = ['content_removed', 'sound_muted', 'dismissed'];
        if (! in_array($decision, $allowedDecisions, true)) {
            throw new InvalidArgumentException("Décision '{$decision}' invalide (autorisées: content_removed, sound_muted, dismissed).");
        }

        if (empty(trim($decisionNotes))) {
            throw new InvalidArgumentException('Une justification est requise pour statuer sur une revendication.');
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
