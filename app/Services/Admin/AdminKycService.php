<?php

namespace App\Services\Admin;

use App\Enums\Admin\AdminPermission;
use App\Enums\Kyc\KycStatus;
use App\Models\Admin\AdminAuditLog;
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
        $query = KycSubmission::whereHas('user')
            ->with(['user.role', 'decidedBy', 'escalatedTo', 'documents'])
            ->select('kyc_submissions.*');

        // Filtrage selon les pays autorisés dans la session de l'opérateur (Point 20)
        $adminSession = request()?->attributes?->get('admin_session');
        if ($adminSession && ! empty($adminSession->allowed_countries)) {
            $allowedCountries = (array) $adminSession->allowed_countries;
            $query->whereHas('user', function (Builder $uq) use ($allowedCountries) {
                $uq->whereIn('country_code', $allowedCountries)
                    ->orWhereHas('country', function (Builder $cq) use ($allowedCountries) {
                        $cq->whereIn('code', $allowedCountries)->orWhereIn('iso2', $allowedCountries);
                    });
            });
        }

        // 1. Filtre par pays (country query param)
        if (! empty($filters['country'])) {
            $countries = is_array($filters['country']) ? $filters['country'] : [$filters['country']];
            $query->whereHas('user', function (Builder $uq) use ($countries) {
                $uq->whereIn('country_code', $countries)
                    ->orWhereHas('country', fn ($cq) => $cq->whereIn('code', $countries)->orWhereIn('iso2', $countries));
            });
        }

        // 2. Filtre par badge (badge query param ou profile_category)
        if (! empty($filters['badge'])) {
            $badges = is_array($filters['badge']) ? $filters['badge'] : [$filters['badge']];
            $categories = [];
            foreach ($badges as $b) {
                if ($b === 'bailleur' || $b === 'landlord') {
                    $categories[] = 'landlord';
                    $categories[] = 'bailleur';
                } elseif ($b === 'agent') {
                    $categories[] = 'agent';
                } elseif ($b === 'page') {
                    $categories[] = 'page';
                } else {
                    $categories[] = $b;
                }
            }
            $query->where(function (Builder $bq) use ($categories, $badges) {
                $bq->whereIn('profile_category', $categories)
                    ->orWhereHas('user', fn ($uq) => $uq->whereIn('badge', $badges));
            });
        } elseif (! empty($filters['profile_category'])) {
            $query->where('profile_category', $filters['profile_category']);
        }

        // 3. Filtre par ville (city query param)
        if (! empty($filters['city'])) {
            $cities = is_array($filters['city']) ? $filters['city'] : [$filters['city']];
            $query->whereHas('user', function (Builder $uq) use ($cities) {
                $uq->whereIn('city', $cities);
            });
        }

        // 4. Filtre par type / nature de demande (kind: new, renewal, add_property, appeal)
        if (! empty($filters['kind'])) {
            $kinds = is_array($filters['kind']) ? $filters['kind'] : [$filters['kind']];
            $query->where(function (Builder $kq) use ($kinds) {
                $kq->whereIn('metadata->kind', $kinds);
                if (in_array('new', $kinds, true)) {
                    $kq->orWhereNull('metadata->kind');
                }
            });
        }

        // 5. Filtre par ancienneté de la demande (age: lt6h, 6to24h, gt24h)
        if (! empty($filters['age'])) {
            $ages = is_array($filters['age']) ? $filters['age'] : [$filters['age']];
            $query->where(function (Builder $aq) use ($ages) {
                foreach ($ages as $a) {
                    if ($a === 'lt6h') {
                        $aq->orWhere('created_at', '>=', now()->subHours(6));
                    } elseif ($a === '6to24h') {
                        $aq->orWhere(function ($q) {
                            $q->where('created_at', '<', now()->subHours(6))
                                ->where('created_at', '>=', now()->subHours(24));
                        });
                    } elseif ($a === 'gt24h') {
                        $aq->orWhere('created_at', '<', now()->subHours(24));
                    }
                }
            });
        }

        // 6. Filtre par statut et awaiting
        if (isset($filters['awaiting'])) {
            $awaiting = filter_var($filters['awaiting'], FILTER_VALIDATE_BOOLEAN);
            if ($awaiting) {
                $query->whereIn('status', ['pending', 'supplement_requested']);
            } else {
                $query->where('status', 'pending');
            }
        } elseif (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // 7. Recherche textuelle (q)
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

        // 8. Tri et priorités : les demandes en attente les plus anciennes en premier
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
        $query = KycSubmission::whereHas('user')
            ->with(['user.role', 'decidedBy'])
            ->where('status', KycStatus::Rejected->value);

        // Filtrage selon les pays autorisés dans la session de l'opérateur (Point 20)
        $adminSession = request()?->attributes?->get('admin_session');
        if ($adminSession && ! empty($adminSession->allowed_countries)) {
            $allowedCountries = (array) $adminSession->allowed_countries;
            $query->whereHas('user', function (Builder $uq) use ($allowedCountries) {
                $uq->whereIn('country_code', $allowedCountries)
                    ->orWhereHas('country', function (Builder $cq) use ($allowedCountries) {
                        $cq->whereIn('code', $allowedCountries)->orWhereIn('iso2', $allowedCountries);
                    });
            });
        }

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

            // Point 21 : Journalisation d'audit chaînée de la décision KYC
            AdminAuditLog::logChainedEvent(
                event: 'kyc.decision_'.$decision,
                operator: $operator,
                targetType: 'KycSubmission',
                targetId: (string) $submission->id,
                targetLabel: "Décision KYC {$decision} sur dossier {$submission->reference} (utilisateur #{$user->id})",
                context: [
                    'submission_id' => $submission->id,
                    'submission_reference' => $submission->reference,
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'decision' => $decision,
                    'reason' => $reason,
                    'status' => $submission->status->value,
                ],
                ip: request()?->ip(),
                userAgent: request()?->userAgent()
            );

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
