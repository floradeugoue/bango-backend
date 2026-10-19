<?php

namespace App\Services\Admin;

use App\Enums\Admin\AdminPermission;
use App\Enums\Kyc\KycStatus;
use App\Models\Kyc\KycSubmission;
use App\Models\User;
use App\Notifications\Kyc\KycDecisionNotification;
use App\Services\Subscription\BadgeResolutionService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminKycService
{
    public function __construct(
        protected BadgeResolutionService $badgeResolutionService
    ) {}

    /**
     * File paginée des dossiers KYC avec filtres, recherche et calcul des priorités.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getQueue(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = KycSubmission::with(['user.role', 'decidedBy', 'escalatedTo', 'documents'])
            ->select('kyc_submissions.*');

        // 1. Filtre par statut
        if (! empty($filters['status'])) {
            $status = $filters['status'];
            $query->where('status', $status);
        }

        // 2. Filtre par catégorie de profil (landlord, agent, page)
        if (! empty($filters['profile_category'])) {
            $query->where('profile_category', $filters['profile_category']);
        }

        // 3. Recherche textuelle (référence, nom, email, téléphone, n° pièce)
        if (! empty($filters['q'])) {
            $search = trim((string) $filters['q']);
            $query->where(function (Builder $q) use ($search) {
                $q->where('reference', 'LIKE', "%{$search}%")
                    ->orWhere('full_name', 'LIKE', "%{$search}%")
                    ->orWhere('id_number', 'LIKE', "%{$search}%")
                    ->orWhereHas('user', function (Builder $uq) use ($search) {
                        $uq->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%")
                            ->orWhere('phone', 'LIKE', "%{$search}%");
                    });
            });
        }

        // 4. Tri et priorités : les demandes en attente les plus anciennes en premier
        $sort = $filters['sort'] ?? 'priority';
        if ($sort === 'priority') {
            $query->orderByRaw("CASE WHEN status = 'pending' THEN 1 WHEN status = 'supplement_requested' THEN 2 ELSE 3 END ASC")
                ->orderBy('created_at', 'asc');
        } elseif ($sort === 'latest') {
            $query->orderByDesc('created_at');
        } else {
            $query->orderBy('created_at', 'asc');
        }

        return $query->paginate($perPage);
    }

    /**
     * Détail complet d'un dossier KYC sans exposer de chemins de stockage privés.
     */
    public function getSubmissionDetail(string $idOrRef): KycSubmission
    {
        return KycSubmission::with([
            'user.role',
            'user.subscriptions' => fn ($q) => $q->where('state', 'active'),
            'documents',
            'decidedBy',
            'escalatedTo',
            'escalatedBy',
            'accessLogs.operator',
        ])
            ->where(function (Builder $q) use ($idOrRef) {
                if (Str::isUuid($idOrRef)) {
                    $q->where('id', $idOrRef)->orWhere('reference', $idOrRef);
                } else {
                    $q->where('reference', $idOrRef);
                }
            })
            ->firstOrFail();
    }

    /**
     * Surveillance et suivi des badges professionnels.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getBadges(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = User::with(['role', 'subscriptions' => fn ($q) => $q->latest()])
            ->where(function (Builder $q) {
                $q->where('badge', '!=', 'none')
                    ->orWhere('is_verified', true)
                    ->orWhereHas('subscriptions', fn ($sq) => $sq->whereIn('state', ['active', 'grace']));
            });

        // Filtre par type de badge
        if (! empty($filters['badge']) && $filters['badge'] !== 'all') {
            $query->where('badge', $filters['badge']);
        }

        // Filtre expiration sous 7 jours
        if (! empty($filters['expiring_soon'])) {
            $sevenDaysLater = now()->addDays(7);
            $query->whereHas('subscriptions', function (Builder $sq) use ($sevenDaysLater) {
                $sq->where('state', 'active')
                    ->whereBetween('current_period_end', [now(), $sevenDaysLater]);
            });
        }

        // Filtre suspension / déchéance
        if (isset($filters['verification_lapsed'])) {
            $query->where('verification_lapsed', (bool) $filters['verification_lapsed']);
        }

        // Recherche
        if (! empty($filters['q'])) {
            $search = trim((string) $filters['q']);
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        $query->orderByDesc('created_at');

        return $query->paginate($perPage);
    }

    /**
     * File des dossiers refusés avec historique et délai de recours (30 jours).
     *
     * @param  array<string, mixed>  $filters
     */
    public function getRefusals(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = KycSubmission::with(['user.role', 'decidedBy'])
            ->where('status', KycStatus::Rejected->value);

        if (! empty($filters['q'])) {
            $search = trim((string) $filters['q']);
            $query->where(function (Builder $q) use ($search) {
                $q->where('reference', 'LIKE', "%{$search}%")
                    ->orWhere('full_name', 'LIKE', "%{$search}%")
                    ->orWhere('rejection_reason', 'LIKE', "%{$search}%")
                    ->orWhereHas('user', function (Builder $uq) use ($search) {
                        $uq->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%");
                    });
            });
        }

        $query->orderByDesc('decided_at');

        return $query->paginate($perPage);
    }

    /**
     * Prise de décision sur un dossier KYC (approve, reject, request_changes).
     * Transactionnel avec attribution du badge (24 mois) et traçabilité.
     */
    public function decide(KycSubmission $submission, User $operator, string $decision, ?string $reason = null): KycSubmission
    {
        if (in_array($submission->status->value, [KycStatus::Approved->value], true)) {
            throw new DomainException('Ce dossier KYC a déjà été formellement approuvé.');
        }

        return DB::transaction(function () use ($submission, $operator, $decision, $reason) {
            $now = now();
            $user = $submission->user;

            if ($decision === 'approve') {
                // 1. Approbation du dossier avec 24 mois de validité
                $expiresOn = $now->copy()->addMonths(24);

                $submission->update([
                    'status' => KycStatus::Approved,
                    'decided_by_id' => $operator->id,
                    'decided_at' => $now,
                    'expires_on' => $expiresOn,
                    'rejection_reason' => null,
                ]);

                // 2. Mise à jour de l'utilisateur : validation d'identité uniquement
                $user->is_verified = true;
                $user->verification_lapsed = false;
                $user->save();

                // 3. Règle produit : les badges proviennent exclusivement des abonnements actifs.
                // Si l'utilisateur possède déjà un abonnement payé (landlord ou agent), son badge est synchronisé.
                // Sinon, le badge reste 'none' jusqu'à souscription d'un abonnement.
                $this->badgeResolutionService->syncUserBadge($user, 'kyc_approval', $operator, 'Vérification KYC approuvée pour 24 mois');

            } elseif ($decision === 'reject') {
                if (empty(trim((string) $reason))) {
                    throw ValidationException::withMessages([
                        'reason' => ['Un motif explicite est obligatoire pour refuser un dossier KYC.'],
                    ]);
                }

                $submission->update([
                    'status' => KycStatus::Rejected,
                    'decided_by_id' => $operator->id,
                    'decided_at' => $now,
                    'rejection_reason' => $reason,
                ]);

            } elseif (in_array($decision, ['request_changes', 'supplement_requested'], true)) {
                if (empty(trim((string) $reason))) {
                    throw ValidationException::withMessages([
                        'reason' => ['Veuillez spécifier les pièces ou informations complémentaires demandées.'],
                    ]);
                }

                $submission->update([
                    'status' => KycStatus::SupplementRequested,
                    'decided_by_id' => $operator->id,
                    'decided_at' => $now,
                    'rejection_reason' => $reason,
                ]);
            } else {
                throw new DomainException("Décision '{$decision}' non reconnue.");
            }

            // Notification de la décision d'instruction à l'utilisateur
            try {
                $user->notify(new KycDecisionNotification($submission, $decision, $reason));
            } catch (\Throwable $e) {
                Log::warning('Échec envoi notification décision KYC : '.$e->getMessage(), [
                    'user_id' => $user->id,
                    'submission_id' => $submission->id,
                    'decision' => $decision,
                ]);
            }

            return $submission->fresh(['user', 'decidedBy', 'documents']);
        });
    }

    /**
     * Escalade hiérarchique d'un dossier vers un responsable ou la direction.
     */
    public function escalate(KycSubmission $submission, User $operator, int $targetUserId, string $reason): KycSubmission
    {
        $targetUser = User::with('role')->findOrFail($targetUserId);

        // Vérifie que le destinataire a bien les habilitations d'escalade (direction, admin ou approvals:action)
        $isAuthorizedTarget = $targetUser->isAdmin()
            || $targetUser->hasRole('direction')
            || $targetUser->hasPermission(AdminPermission::ApprovalsAction->value);

        if (! $isAuthorizedTarget) {
            throw ValidationException::withMessages([
                'target_user_id' => ['L\'opérateur cible sélectionné n\'a pas les habilitations requises pour recevoir une escalade KYC.'],
            ]);
        }

        $submission->update([
            'escalated_to_id' => $targetUser->id,
            'escalated_by_id' => $operator->id,
            'escalation_reason' => $reason,
            'escalated_at' => now(),
        ]);

        return $submission->fresh(['escalatedTo', 'escalatedBy']);
    }

    /**
     * Retourne la liste des opérateurs autorisés à recevoir une escalade hiérarchique.
     *
     * @return Collection<int, User>
     */
    public function getEscalationTargets(User $operator): Collection
    {
        return User::where('id', '!=', $operator->id)
            ->where(function (Builder $query) {
                $query->whereHas('role', function (Builder $rq) {
                    $rq->whereIn('slug', ['admin', 'direction']);
                });
            })
            ->get(['id', 'name', 'email', 'role_id']);
    }

    /**
     * Règles de vérification officielles, seuils et motifs autorisés.
     *
     * @return array<string, mixed>
     */
    public function getRules(): array
    {
        return [
            'badge_validity_months' => 24,
            'appeal_window_days' => 30,
            'sla_target_hours' => 24,
            'profiles' => [
                'landlord' => [
                    'label' => 'Bailleur particulier',
                    'required_documents' => [
                        'front' => 'Pièce d\'identité (recto)',
                        'back' => 'Pièce d\'identité (verso, si applicable)',
                        'selfie' => 'Selfie de contrôle en direct',
                        'deed' => 'Titre de propriété ou attestation notariée',
                    ],
                    'eligible_id_types' => ['cni', 'passport', 'residence_permit'],
                ],
                'agent' => [
                    'label' => 'Agent immobilier indépendant / Mandataire',
                    'required_documents' => [
                        'front' => 'Pièce d\'identité (recto)',
                        'back' => 'Pièce d\'identité (verso)',
                        'selfie' => 'Selfie de conformité',
                        'deed' => 'Carte professionnelle, mandat d\'exercice ou attestation d\'agence',
                    ],
                    'eligible_id_types' => ['cni', 'passport'],
                ],
                'page' => [
                    'label' => 'Page Professionnelle / Agence immobilière',
                    'required_documents' => [
                        'rccm' => 'Extrait RCCM de moins de 3 mois',
                        'nif' => 'Numéro d\'Identification Fiscale',
                        'rep_id' => 'Pièce d\'identité du représentant légal',
                        'agency_contract' => 'Mandat ou registre de commerce',
                    ],
                ],
            ],
            'authorized_rejection_reasons' => [
                'document_expired' => 'Document d\'identité expiré',
                'document_illegible' => 'Document illisible ou tronqué',
                'identity_mismatch' => 'Nom ou données non concordantes avec le profil',
                'fraudulent_document' => 'Suspicion de falsification ou de montage',
                'incomplete_file' => 'Pièces obligatoires manquantes',
                'deed_invalid' => 'Titre de propriété non recevable ou nom différent',
            ],
            'escalation_thresholds' => [
                'suspicion_fraud' => 'Escalade obligatoire vers Direction en cas de faux document avéré',
                'high_profile_landlord' => 'Patrimoine supérieur à 10 biens : double validation requise',
            ],
        ];
    }
}
