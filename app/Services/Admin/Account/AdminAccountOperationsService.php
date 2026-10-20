<?php

namespace App\Services\Admin\Account;

use App\Enums\Support\TicketCategory;
use App\Enums\Support\TicketMessageSenderType;
use App\Enums\Support\TicketPriority;
use App\Enums\Support\TicketStatus;
use App\Models\Listing\Listing;
use App\Models\Page\Page;
use App\Models\Payment\PaymentTransaction;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\Subscription\BadgeHistory;
use App\Models\Support\Report;
use App\Models\Support\SupportTicket;
use App\Models\Support\SupportTicketMessage;
use App\Models\Support\UserAccountSanction;
use App\Models\Support\UserAnonymizationRequest;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAccountOperationsService
{
    /**
     * 5.1. Annuaire paginé des comptes utilisateurs avec filtres et téléphone masqué.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getAccounts(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), 100);

        $query = User::with(['role:id,name,slug', 'country:id,name', 'city:id,name'])
            ->withCount(['supportTickets', 'listings', 'posts', 'reels']);

        // Point 20 : Filtrage selon les pays autorisés dans la session de l'opérateur
        $adminSession = request()?->attributes?->get('admin_session');
        if ($adminSession && ! empty($adminSession->allowed_countries)) {
            $allowedCountries = (array) $adminSession->allowed_countries;
            $query->where(function (Builder $uq) use ($allowedCountries) {
                $uq->whereIn('country_code', $allowedCountries)
                    ->orWhereHas('country', function (Builder $cq) use ($allowedCountries) {
                        $cq->whereIn('code', $allowedCountries)->orWhereIn('iso2', $allowedCountries);
                    });
            });
        }

        // Recherche par nom, display_name, email, phone, handle
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('handle', 'like', "%{$search}%");
            });
        }

        // Filtre statut
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filtre vérification KYC
        if (isset($filters['is_verified'])) {
            $query->where('is_verified', filter_var($filters['is_verified'], FILTER_VALIDATE_BOOLEAN));
        }

        // Filtre certification notable
        if (isset($filters['is_certified'])) {
            $query->where('is_certified', filter_var($filters['is_certified'], FILTER_VALIDATE_BOOLEAN));
        }

        // Filtre badge
        if (! empty($filters['badge'])) {
            $query->where('badge', $filters['badge']);
        }

        // Tri sur liste blanche
        $sort = $filters['sort'] ?? 'created_at';
        $order = strtolower($filters['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['created_at', 'name', 'email', 'status', 'badge'];

        if (in_array($sort, $allowedSorts, true)) {
            $query->orderBy($sort, $order);
        } else {
            $query->orderByDesc('created_at');
        }

        $paginator = $query->paginate($perPage);

        // Masquer systématiquement le téléphone pour la liste
        $paginator->getCollection()->transform(function (User $user) {
            $user->masked_phone = $this->maskPhone($user->phone);
            unset($user->phone); // Ne pas exposer le téléphone brut

            return $user;
        });

        return $paginator;
    }

    /**
     * 5.1 bis. Fiche détaillée d'un compte avec informations pertinentes et téléphone masqué.
     */
    public function getAccountDetails(int|string $id): User
    {
        $user = User::with([
            'role:id,name,slug',
            'country:id,name,code',
            'city:id,name',
            'neighbourhood:id,name',
            'sanctions' => fn ($q) => $q->orderByDesc('created_at')->limit(10),
            'anonymizationRequests' => fn ($q) => $q->orderByDesc('created_at')->limit(5),
        ])
            ->where(function (Builder $query) use ($id) {
                if (is_numeric($id)) {
                    $query->where('id', (int) $id);
                } else {
                    $query->where('handle', (string) $id)->orWhere('email', (string) $id);
                }
            })
            ->firstOrFail();

        $user->masked_phone = $this->maskPhone($user->phone);
        $user->masked_email = $this->maskEmail($user->email);

        return $user;
    }

    /**
     * 5.2. Annuaire de l'équipe interne et rôles opérationnels.
     *
     * @return Collection<int, User>
     */
    public function getTeamMembers(): Collection
    {
        return User::whereHas('role', function (Builder $q) {
            $q->whereIn('slug', ['admin', 'direction', 'moderation', 'support', 'finance', 'verification']);
        })
            ->select(['id', 'name', 'display_name', 'email', 'avatar_url', 'role_id', 'status', 'created_at'])
            ->with('role:id,name,slug,description,permissions')
            ->orderBy('name')
            ->get()
            ->transform(function (User $member) {
                $member->masked_phone = $this->maskPhone($member->phone);

                return $member;
            });
    }

    /**
     * 5.3. Sous-onglet : Annonces immobilières de l'utilisateur.
     */
    public function getUserListings(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return Listing::where('user_id', $user->id)
            ->with(['city:id,name', 'country:id,name'])
            ->orderByDesc('created_at')
            ->paginate(min(max($perPage, 1), 50));
    }

    /**
     * 5.3. Sous-onglet : Contenus publiés par l'utilisateur (Square Posts & Reels).
     *
     * @return array<string, mixed>
     */
    public function getUserContents(User $user, int $perPage = 10): array
    {
        $posts = Post::where('user_id', $user->id)
            ->withCount(['comments', 'likes', 'shares'])
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'posts_page');

        $reels = Reel::where('user_id', $user->id)
            ->withCount(['comments', 'reactions', 'views'])
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'reels_page');

        return [
            'posts' => $posts,
            'reels' => $reels,
        ];
    }

    /**
     * 5.3. Sous-onglet : Transactions financières de l'utilisateur.
     */
    public function getUserPayments(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return PaymentTransaction::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate(min(max($perPage, 1), 50));
    }

    /**
     * 5.3. Sous-onglet : Dossiers et signalements de modération associés au compte.
     */
    public function getUserCases(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return Report::where('user_id', $user->id)
            ->orWhere(function (Builder $q) use ($user) {
                $q->where('reportable_type', User::class)->where('reportable_id', $user->id);
            })
            ->with(['reporter:id,name', 'operator:id,name'])
            ->orderByDesc('created_at')
            ->paginate(min(max($perPage, 1), 50));
    }

    /**
     * 5.3. Sous-onglet : Tickets de support du compte.
     */
    public function getUserTickets(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return SupportTicket::where('user_id', $user->id)
            ->with(['assignedAgent:id,name', 'resolvedBy:id,name'])
            ->withCount('messages')
            ->orderByDesc('created_at')
            ->paginate(min(max($perPage, 1), 50));
    }

    /**
     * 5.4. Chronologie unifiée des événements du compte depuis son inscription.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getUserTimeline(User $user): Collection
    {
        $events = collect();

        // 1. Inscription
        $events->push([
            'type' => 'account_registered',
            'title' => 'Création du compte',
            'description' => "Compte créé avec l'email {$this->maskEmail($user->email)}",
            'date' => $user->created_at?->toIso8601String(),
            'source' => 'AUTH',
        ]);

        // 2. Historique des badges
        $badges = BadgeHistory::where('user_id', $user->id)->get();
        foreach ($badges as $badge) {
            $events->push([
                'type' => 'badge_event',
                'title' => "Badge : {$badge->action}",
                'description' => "Badge {$badge->badge_type} ({$badge->action})",
                'date' => $badge->created_at?->toIso8601String(),
                'source' => 'SUB',
            ]);
        }

        // 3. Transactions financières récentes
        $transactions = PaymentTransaction::where('user_id', $user->id)->limit(20)->get();
        foreach ($transactions as $tx) {
            $events->push([
                'type' => 'payment_transaction',
                'title' => "Paiement : {$tx->service_type} ({$tx->status})",
                'description' => "Montant : {$tx->amount} {$tx->currency} via {$tx->provider}",
                'date' => $tx->created_at?->toIso8601String(),
                'source' => 'PAY',
            ]);
        }

        // 4. Tickets de support
        $tickets = SupportTicket::where('user_id', $user->id)->limit(20)->get();
        foreach ($tickets as $ticket) {
            $events->push([
                'type' => 'support_ticket',
                'title' => "Ticket {$ticket->reference} ({$ticket->status->value})",
                'description' => "Sujet : {$ticket->subject}",
                'date' => $ticket->created_at?->toIso8601String(),
                'source' => 'SUP',
            ]);
        }

        // 5. Sanctions et avertissements
        $sanctions = UserAccountSanction::where('user_id', $user->id)->get();
        foreach ($sanctions as $sanction) {
            $events->push([
                'type' => 'sanction',
                'title' => "Sanction : {$sanction->type}".($sanction->level ? " (Niveau {$sanction->level})" : ''),
                'description' => "Motif : {$sanction->reason}".($sanction->ends_at ? " — Jusqu'au {$sanction->ends_at->toIso8601String()}" : ''),
                'date' => $sanction->created_at?->toIso8601String(),
                'source' => 'SUPPORT_ADMIN',
            ]);
        }

        // 6. Demandes RGPD
        $anonymizations = UserAnonymizationRequest::where('user_id', $user->id)->get();
        foreach ($anonymizations as $anon) {
            $events->push([
                'type' => 'gdpr_anonymization',
                'title' => "Demande d'anonymisation ({$anon->status})",
                'description' => "Motif : {$anon->reason}",
                'date' => $anon->created_at?->toIso8601String(),
                'source' => 'GDPR',
            ]);
        }

        return $events->sortByDesc('date')->values();
    }

    /**
     * 4.9. Synthèse utilisateur 360° pour le panneau d'assistance support.
     *
     * @return array<string, mixed>
     */
    public function getSupportSummary(User $user): array
    {
        $ticketCount = SupportTicket::where('user_id', $user->id)->count();
        $openTickets = SupportTicket::where('user_id', $user->id)
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value])
            ->count();

        $activeSanctions = UserAccountSanction::where('user_id', $user->id)
            ->where('is_active', true)
            ->get();

        $recentPayments = PaymentTransaction::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'reference', 'amount', 'currency', 'status', 'created_at']);

        $pendingGdpr = UserAnonymizationRequest::where('user_id', $user->id)
            ->where('status', 'pending_approval')
            ->exists();

        return [
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'display_name' => $user->display_name,
                'masked_email' => $this->maskEmail($user->email),
                'masked_phone' => $this->maskPhone($user->phone),
                'status' => $user->status,
                'badge' => $user->badge,
                'is_verified' => $user->is_verified,
                'is_certified' => $user->is_certified,
                'restricted_until' => $user->restricted_until?->toIso8601String(),
                'suspended_until' => $user->suspended_until?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'tickets_overview' => [
                'total' => $ticketCount,
                'open' => $openTickets,
            ],
            'sanctions_overview' => [
                'has_active_sanctions' => $activeSanctions->isNotEmpty(),
                'active_count' => $activeSanctions->count(),
                'details' => $activeSanctions,
            ],
            'financial_overview' => [
                'recent_transactions' => $recentPayments,
            ],
            'gdpr_status' => [
                'has_pending_anonymization' => $pendingGdpr,
            ],
        ];
    }

    /**
     * 5.5. Prévisualisation de l'impact d'une sanction sans aucune mutation en base.
     *
     * @return array<string, mixed>
     */
    public function previewAction(User $user, string $kind): array
    {
        $allowedKinds = [
            'suspend_7d',
            'suspend_30d',
            'suspend_indefinite',
            'warning_level_1',
            'warning_level_2',
            'warning_level_3',
            'badge_extend',
            'badge_remove',
            'certification_remove',
            'logout_sessions',
            'anonymize',
        ];

        if (! in_array($kind, $allowedKinds, true)) {
            throw new DomainException("Action de sanction inconnue ou non supportée : {$kind}");
        }

        $activeTokensCount = $user->tokens()->count();
        $activeListingsCount = Listing::where('user_id', $user->id)->where('status', 'published')->count();

        $consequences = match ($kind) {
            'suspend_7d' => [
                'duration' => '7 jours',
                'account_status' => 'suspended',
                'sessions_revoked' => $activeTokensCount,
                'listings_impact' => "{$activeListingsCount} annonces suspendues temporairement",
                'badge_impact' => 'Badge gelé pendant la suspension',
            ],
            'suspend_30d' => [
                'duration' => '30 jours',
                'account_status' => 'suspended',
                'sessions_revoked' => $activeTokensCount,
                'listings_impact' => "{$activeListingsCount} annonces suspendues temporairement",
                'badge_impact' => 'Badge gelé pendant la suspension',
            ],
            'suspend_indefinite' => [
                'duration' => 'Indéfinie',
                'account_status' => 'suspended',
                'sessions_revoked' => $activeTokensCount,
                'listings_impact' => "{$activeListingsCount} annonces dépubliées",
                'badge_impact' => 'Badge suspendu',
            ],
            'warning_level_1' => [
                'level' => 1,
                'consequence' => 'Notification d\'avertissement formel envoyée',
                'restriction' => 'Aucune restriction immédiate',
            ],
            'warning_level_2' => [
                'level' => 2,
                'consequence' => 'Avertissement sévère et inscription dans le journal de conformité',
                'restriction' => 'Surveillance accrue des publications',
            ],
            'warning_level_3' => [
                'level' => 3,
                'consequence' => 'Dernier avertissement avant suspension définitive',
                'restriction' => 'Restriction des droits de publication pendant 7 jours',
            ],
            'badge_remove' => [
                'current_badge' => $user->badge ?? 'aucun',
                'consequence' => 'Retrait immédiat du badge et perte des avantages professionnels',
            ],
            'badge_extend' => [
                'current_badge' => $user->badge ?? 'aucun',
                'consequence' => 'Prolongation de validité du badge',
            ],
            'certification_remove' => [
                'is_certified' => $user->is_certified,
                'consequence' => 'Suppression de la coche de certification officielle',
            ],
            'logout_sessions' => [
                'consequence' => "Déconnexion forcée immédiate de {$activeTokensCount} sessions actives",
            ],
            'anonymize' => [
                'consequence' => 'Création d\'une demande d\'anonymisation soumise à 2e approbation',
                'warning' => 'Opération irréversible après approbation',
            ],
        };

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'status' => $user->status,
                'badge' => $user->badge,
            ],
            'action' => $kind,
            'simulated_effects' => $consequences,
            'mutation_applied' => false,
        ];
    }

    /**
     * 5.6. Suspension d'un compte (7 jours, 30 jours, indéfinie) avec traçabilité.
     */
    public function suspendAccount(User $user, User $operator, string $duration, string $reason): User
    {
        return DB::transaction(function () use ($user, $operator, $duration, $reason) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $startsAt = Carbon::now();
            $durationDays = match ($duration) {
                '7_days' => 7,
                '30_days' => 30,
                default => null,
            };

            $endsAt = $durationDays ? $startsAt->copy()->addDays($durationDays) : null;

            $lockedUser->status = 'suspended';
            $lockedUser->suspended_from = $startsAt;
            $lockedUser->suspended_until = $endsAt;
            $lockedUser->suspension_reason = $reason;
            $lockedUser->save();

            // Déconnexion immédiate de toutes les sessions actives
            $lockedUser->tokens()->delete();

            // Enregistrement de la sanction
            UserAccountSanction::create([
                'user_id' => $lockedUser->id,
                'operator_id' => $operator->id,
                'type' => 'suspension',
                'duration_days' => $durationDays,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $reason,
                'is_active' => true,
            ]);

            return $lockedUser->fresh(['sanctions']);
        });
    }

    /**
     * 5.6 bis. Levée d'une suspension de compte.
     */
    public function liftSuspension(User $user, User $operator, string $reason): User
    {
        return DB::transaction(function () use ($user, $operator, $reason) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $lockedUser->status = 'active';
            $lockedUser->suspended_until = null;
            $lockedUser->suspension_reason = null;
            $lockedUser->save();

            // Clôture des sanctions de suspension actives
            UserAccountSanction::where('user_id', $lockedUser->id)
                ->where('type', 'suspension')
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'lifted_at' => Carbon::now(),
                    'lifted_by_id' => $operator->id,
                    'lift_reason' => $reason,
                ]);

            return $lockedUser->fresh(['sanctions']);
        });
    }

    /**
     * 5.7. Avertissement formel (niveaux 1, 2, 3 avec restriction de 7j pour le niveau 3).
     */
    public function applyWarning(
        User $user,
        User $operator,
        int $level,
        string $reason,
        bool $applyRestriction = false
    ): UserAccountSanction {
        return DB::transaction(function () use ($user, $operator, $level, $reason, $applyRestriction) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $endsAt = null;
            if ($level === 3 || $applyRestriction) {
                $endsAt = Carbon::now()->addDays(7);
                $lockedUser->restricted_until = $endsAt;
                $lockedUser->save();
            }

            return UserAccountSanction::create([
                'user_id' => $lockedUser->id,
                'operator_id' => $operator->id,
                'type' => 'warning',
                'level' => $level,
                'starts_at' => Carbon::now(),
                'ends_at' => $endsAt,
                'reason' => $reason,
                'is_active' => true,
                'metadata' => [
                    'restricted_until' => $endsAt?->toIso8601String(),
                ],
            ]);
        });
    }

    /**
     * 5.8. Prolongation commerciale d'un badge.
     */
    public function extendBadge(User $user, User $operator, string $badge, int $durationDays, string $reason): User
    {
        return DB::transaction(function () use ($user, $operator, $badge, $durationDays, $reason) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $lockedUser->badge = $badge;
            $lockedUser->save();

            BadgeHistory::create([
                'user_id' => $lockedUser->id,
                'badge' => $badge,
                'event' => 'extended',
                'source' => 'admin_support',
                'actor_id' => $operator->id,
                'reason' => "Prolongation de {$durationDays} jours par {$operator->name} : {$reason}",
                'created_at' => Carbon::now(),
            ]);

            return $lockedUser->fresh();
        });
    }

    /**
     * 5.8 bis. Retrait d'un badge utilisateur.
     */
    public function removeBadge(User $user, User $operator, string $reason): User
    {
        return DB::transaction(function () use ($user, $operator, $reason) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $oldBadge = $lockedUser->badge;

            $lockedUser->badge = 'none';
            $lockedUser->save();

            BadgeHistory::create([
                'user_id' => $lockedUser->id,
                'badge' => $oldBadge ?? 'none',
                'event' => 'revoked',
                'source' => 'admin_support',
                'actor_id' => $operator->id,
                'reason' => "Retrait de badge par {$operator->name} : {$reason}",
                'created_at' => Carbon::now(),
            ]);

            return $lockedUser->fresh();
        });
    }

    /**
     * 5.9. Attribution de la certification (compte personnel ou page professionnelle).
     */
    public function grantCertification(User $user, User $operator, string $type, ?int $pageId, string $reason): array
    {
        return DB::transaction(function () use ($user, $type, $pageId, $reason) {
            if ($type === 'page') {
                $page = Page::findOrFail($pageId);
                $page->certification_status = 'approved';
                $page->save();

                return [
                    'type' => 'page',
                    'page_id' => $page->id,
                    'name' => $page->name,
                    'certification_status' => 'approved',
                    'reason' => $reason,
                ];
            }

            // Certification d'un compte utilisateur (figure publique, créateur notable)
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $lockedUser->is_certified = true;
            $lockedUser->certified_at = Carbon::now();
            $lockedUser->save();

            return [
                'type' => 'account',
                'user_id' => $lockedUser->id,
                'name' => $lockedUser->name,
                'is_certified' => true,
                'certified_at' => $lockedUser->certified_at->toIso8601String(),
                'reason' => $reason,
            ];
        });
    }

    /**
     * 5.9 bis. Retrait de la certification.
     */
    public function removeCertification(User $user, User $operator, string $type, ?int $pageId, string $reason): array
    {
        return DB::transaction(function () use ($user, $type, $pageId, $reason) {
            if ($type === 'page') {
                $page = Page::findOrFail($pageId);
                $page->certification_status = 'rejected';
                $page->save();

                return [
                    'type' => 'page',
                    'page_id' => $page->id,
                    'certification_status' => 'rejected',
                    'reason' => $reason,
                ];
            }

            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $lockedUser->is_certified = false;
            $lockedUser->certified_at = null;
            $lockedUser->save();

            return [
                'type' => 'account',
                'user_id' => $lockedUser->id,
                'is_certified' => false,
                'reason' => $reason,
            ];
        });
    }

    /**
     * 5.10. Contact direct de l'utilisateur (In-App, SMS, Ticket).
     *
     * @return array<string, mixed>
     */
    public function contactUser(
        User $user,
        User $operator,
        string $channel,
        ?string $subject,
        string $message
    ): array {
        return DB::transaction(function () use ($user, $operator, $channel, $subject, $message) {
            if ($channel === 'ticket') {
                $ticket = SupportTicket::create([
                    'reference' => SupportTicket::generateReference(),
                    'user_id' => $user->id,
                    'assigned_to' => $operator->id,
                    'subject' => $subject ?? 'Message du Support Client BANGO',
                    'category' => TicketCategory::Other,
                    'channel' => 'in_app',
                    'status' => TicketStatus::Open,
                    'priority' => TicketPriority::Normal,
                    'last_reply_at' => Carbon::now(),
                ]);

                SupportTicketMessage::create([
                    'support_ticket_id' => $ticket->id,
                    'sender_id' => $operator->id,
                    'sender_type' => TicketMessageSenderType::Support,
                    'message' => $message,
                    'is_internal' => false,
                ]);

                return [
                    'channel' => 'ticket',
                    'ticket_reference' => $ticket->reference,
                    'status' => 'sent',
                ];
            }

            // Canal In-App ou SMS simulé avec traçabilité
            return [
                'channel' => $channel,
                'recipient_id' => $user->id,
                'recipient_name' => $user->name,
                'subject' => $subject,
                'status' => 'dispatched',
                'sent_at' => Carbon::now()->toIso8601String(),
            ];
        });
    }

    /**
     * 5.11. Création d'une demande d'anonymisation RGPD (soumise à 2e approbation).
     */
    public function requestAnonymization(User $user, User $operator, string $reason): UserAnonymizationRequest
    {
        return DB::transaction(function () use ($user, $operator, $reason) {
            // Vérifier si une demande en attente existe déjà
            $existing = UserAnonymizationRequest::where('user_id', $user->id)
                ->where('status', 'pending_approval')
                ->first();

            if ($existing) {
                throw new DomainException('Une demande d\'anonymisation est déjà en attente d\'approbation pour ce compte.');
            }

            return UserAnonymizationRequest::create([
                'user_id' => $user->id,
                'requested_by_id' => $operator->id,
                'reason' => $reason,
                'status' => 'pending_approval',
            ]);
        });
    }

    /**
     * 5.11 bis. Approbation par un second opérateur et exécution de l'anonymisation contrôlée.
     */
    public function approveAnonymization(
        int $requestId,
        User $approver,
        ?string $decisionNote = null
    ): UserAnonymizationRequest {
        return DB::transaction(function () use ($requestId, $approver, $decisionNote) {
            $request = UserAnonymizationRequest::where('id', $requestId)->lockForUpdate()->firstOrFail();

            if ($request->status !== 'pending_approval') {
                throw new DomainException("Cette demande n'est plus en attente d'approbation (statut actuel : {$request->status}).");
            }

            // RÈGLE IMPÉRATIVE DE DOUBLE APPROBATION : le demandeur ne peut pas approuver sa propre demande
            if ((int) $request->requested_by_id === (int) $approver->id) {
                throw new DomainException('SELF_APPROVAL_FORBIDDEN: L\'opérateur ayant initié la demande d\'anonymisation ne peut pas l\'approuver lui-même.');
            }

            $user = User::where('id', $request->user_id)->lockForUpdate()->firstOrFail();

            // Exécution de l'anonymisation tout en préservant l'intégrité référentielle
            // (clés étrangères des paiements, visites, transactions intactes)
            $anonymizedTag = "anon_{$user->id}_".Str::random(6);

            $user->name = 'Utilisateur Supprimé';
            $user->display_name = 'Anonyme';
            $user->email = "{$anonymizedTag}@bango.invalid";
            $user->phone = null;
            $user->bio = null;
            $user->avatar_url = null;
            $user->handle = $anonymizedTag;
            $user->status = 'deleted';
            $user->scheduled_for_deletion_at = Carbon::now();
            $user->save();

            // Révocation de toutes les sessions actives
            $user->tokens()->delete();

            $request->status = 'processed';
            $request->approved_by_id = $approver->id;
            $request->decided_at = Carbon::now();
            $request->decision_note = $decisionNote;
            $request->processed_at = Carbon::now();
            $request->execution_summary = [
                'anonymized_tag' => $anonymizedTag,
                'financial_records_preserved' => true,
                'sessions_revoked' => true,
            ];
            $request->save();

            return $request->load(['user', 'requester', 'approver']);
        });
    }

    /**
     * 5.12. Opérations de sécurité du compte (déconnexion forcée, réinitialisation 2FA).
     *
     * @return array<string, mixed>
     */
    public function executeSecurityAction(User $user, User $operator, string $kind): array
    {
        $allowed = ['logout_sessions', 'reset_2fa'];
        if (! in_array($kind, $allowed, true)) {
            throw new DomainException("Opération de sécurité non supportée : {$kind}");
        }

        return DB::transaction(function () use ($user, $kind) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            if ($kind === 'logout_sessions') {
                $revokedCount = $lockedUser->tokens()->count();
                $lockedUser->tokens()->delete();

                return [
                    'action' => 'logout_sessions',
                    'sessions_revoked' => $revokedCount,
                    'status' => 'success',
                ];
            }

            if ($kind === 'reset_2fa') {
                $lockedUser->two_factor_method = null;
                $lockedUser->two_factor_secret = null;
                $lockedUser->backup_codes = null;
                $lockedUser->save();

                return [
                    'action' => 'reset_2fa',
                    'status' => 'success',
                    'message' => 'Le double facteur d\'authentification a été réinitialisé.',
                ];
            }

            return ['status' => 'noop'];
        });
    }

    /**
     * 5.13. Export de portabilité RGPD d'un compte utilisateur unique.
     *
     * @return array<string, mixed>
     */
    public function exportUserPortability(User $user, User $operator): array
    {
        return [
            'export_type' => 'gdpr_portability',
            'generated_at' => Carbon::now()->toIso8601String(),
            'operator' => $operator->name,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'display_name' => $user->display_name,
                'email' => $user->email,
                'handle' => $user->handle,
                'status' => $user->status,
                'badge' => $user->badge,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'listings_count' => Listing::where('user_id', $user->id)->count(),
            'posts_count' => Post::where('user_id', $user->id)->count(),
            'reels_count' => Reel::where('user_id', $user->id)->count(),
            'tickets_count' => SupportTicket::where('user_id', $user->id)->count(),
            'transactions_count' => PaymentTransaction::where('user_id', $user->id)->count(),
        ];
    }

    /**
     * 5.13 bis. Export CSV de la liste des comptes avec protection anti-injection.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportAccountsCsv(array $filters, User $operator): StreamedResponse
    {
        $filename = 'comptes_utilisateurs_'.date('Ymd_His').'.csv';

        return new StreamedResponse(function () use ($filters) {
            $output = fopen('php://output', 'w');

            // BOM UTF-8
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($output, [
                'ID',
                'Nom',
                'Pseudo',
                'Email',
                'Telephone_Masque',
                'Statut',
                'Badge',
                'Verifie_KYC',
                'Certifie',
                'Date_Inscription',
            ]);

            $query = User::orderByDesc('created_at');

            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (! empty($filters['badge'])) {
                $query->where('badge', $filters['badge']);
            }

            $query->chunk(200, function ($users) use ($output) {
                foreach ($users as $user) {
                    fputcsv($output, [
                        $user->id,
                        $this->sanitizeCsvField($user->name),
                        $this->sanitizeCsvField($user->handle ?? ''),
                        $this->sanitizeCsvField($user->email ?? ''),
                        $this->sanitizeCsvField($this->maskPhone($user->phone)),
                        $this->sanitizeCsvField($user->status),
                        $this->sanitizeCsvField($user->badge ?? 'aucun'),
                        $user->is_verified ? 'OUI' : 'NON',
                        $user->is_certified ? 'OUI' : 'NON',
                        $user->created_at?->toIso8601String() ?? '',
                    ]);
                }
            });

            fclose($output);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * Masque le numéro de téléphone pour préserver la vie privée.
     */
    protected function maskPhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $len = strlen($phone);
        if ($len > 6) {
            return substr($phone, 0, 4).'***'.substr($phone, -2);
        }

        return '***';
    }

    /**
     * Masque l'email pour le panneau d'assistance support.
     */
    protected function maskEmail(?string $email): ?string
    {
        if (! $email) {
            return null;
        }

        $parts = explode('@', $email);
        $name = $parts[0];
        $domain = $parts[1] ?? '';
        $len = strlen($name);

        $maskedName = $len > 2 ? substr($name, 0, 1).str_repeat('*', max(1, $len - 2)).substr($name, -1) : $name.'***';

        return "{$maskedName}@{$domain}";
    }

    /**
     * Protection anti-injection CSV sur cellules utilisateurs (=, +, -, @, tab, cr).
     */
    protected function sanitizeCsvField(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $trimmed = trim($value);

        if (in_array(substr($trimmed, 0, 1), ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$trimmed;
        }

        return $trimmed;
    }
}
