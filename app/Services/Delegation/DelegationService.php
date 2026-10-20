<?php

namespace App\Services\Delegation;

use App\Enums\Delegation\DelegationCapability;
use App\Enums\Delegation\DelegationStatus;
use App\Models\Delegation\Delegation;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Notifications\Delegation\AgentAcceptedNotification;
use App\Notifications\Delegation\AgentDeclinedNotification;
use App\Notifications\Delegation\AgentInvitationNotification;
use App\Notifications\Delegation\DelegationRevokedNotification;
use App\Services\Subscription\BadgeResolutionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DelegationService
{
    public const MAX_AGENTS_PER_LANDLORD = 5;

    public const INVITATION_TTL_DAYS = 7;

    public function __construct(
        protected BadgeResolutionService $badgeResolution
    ) {}

    /**
     * Vérifie si un bailleur dispose d'un badge Bailleur actif.
     */
    public function canLandlordDelegate(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->locked_until && $user->locked_until->isFuture()) {
            return false;
        }

        if ($user->suspended_until && $user->suspended_until->isFuture()) {
            return false;
        }

        if ($user->verification_lapsed || ! $user->is_verified) {
            return false;
        }

        if (in_array($user->badge, ['bailleur', 'landlord', 'double'], true)) {
            return true;
        }

        $badgeStatus = $this->badgeResolution->resolveUserBadges($user);

        return $badgeStatus['hasLandlord'];
    }

    /**
     * Vérifie si un utilisateur dispose du badge Agent actif et certifié.
     */
    public function hasActiveAgentBadge(User $agent): bool
    {
        if ($agent->isAdmin()) {
            return true;
        }

        if ($agent->locked_until && $agent->locked_until->isFuture()) {
            return false;
        }

        if ($agent->suspended_until && $agent->suspended_until->isFuture()) {
            return false;
        }

        if ($agent->verification_lapsed || ! $agent->is_verified) {
            return false;
        }

        if (in_array($agent->badge, ['agent', 'double'], true)) {
            return true;
        }

        $badgeStatus = $this->badgeResolution->resolveUserBadges($agent);

        return $badgeStatus['hasAgent'];
    }

    /**
     * Inviter un agent sur un bien déterminé.
     *
     * @param  array{
     *     listing_id: string,
     *     agent_id: int|string,
     *     capabilities?: array<string>,
     *     expires_days?: int
     * }  $data
     */
    public function inviteAgent(User $landlord, array $data): Delegation
    {
        // 1. Contrôle des droits du bailleur
        if (! $this->canLandlordDelegate($landlord)) {
            throw new AuthorizationException('Un badge Bailleur actif et vérifié est requis pour inviter un agent.');
        }

        // 2. Contrôle du bien
        /** @var Listing $listing */
        $listing = Listing::findOrFail($data['listing_id']);

        if ($listing->owner_id !== $landlord->id && ! $landlord->isAdmin()) {
            throw new AuthorizationException('Vous ne pouvez déléguer la gestion que de vos propres annonces.');
        }

        // 3. Contrôle de l'agent
        $agentId = (int) $data['agent_id'];

        if ($agentId === $landlord->id) {
            throw ValidationException::withMessages([
                'agent_id' => ['Vous ne pouvez pas vous désigner vous-même comme agent délégué.'],
            ]);
        }

        /** @var User $agent */
        $agent = User::findOrFail($agentId);

        if (! $this->hasActiveAgentBadge($agent)) {
            throw ValidationException::withMessages([
                'agent_id' => ['Cet utilisateur ne dispose pas d\'un badge Agent actif et valide.'],
            ]);
        }

        // 4. Normalisation des capacités
        $allowedCaps = [
            DelegationCapability::Visits->value,
            DelegationCapability::Reply->value,
            DelegationCapability::Edit->value,
        ];

        $rawCaps = $data['capabilities'] ?? [DelegationCapability::Visits->value, DelegationCapability::Reply->value];
        $capabilities = array_values(array_intersect($allowedCaps, $rawCaps));

        if (empty($capabilities)) {
            $capabilities = [DelegationCapability::Visits->value];
        }

        return DB::transaction(function () use ($landlord, $listing, $agent, $capabilities, $data) {
            // Vérifier les limites de mandats simultanés
            $activeOrPendingCount = Delegation::where('bailleur_id', $landlord->id)
                ->whereIn('status', [DelegationStatus::Active->value, DelegationStatus::Pending->value])
                ->where(function (Builder $q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->distinct('agent_id')
                ->count('agent_id');

            // Vérifier si une délégation existe déjà pour ce bien et cet agent
            /** @var Delegation|null $existing */
            $existing = Delegation::where('listing_id', $listing->id)
                ->where('agent_id', $agent->id)
                ->whereIn('status', [DelegationStatus::Active->value, DelegationStatus::Pending->value])
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'agent_id' => ['Une invitation ou un mandat actif existe déjà pour cet agent sur ce bien.'],
                ]);
            }

            if ($activeOrPendingCount >= self::MAX_AGENTS_PER_LANDLORD) {
                throw ValidationException::withMessages([
                    'agent_id' => ['Vous avez atteint le quota maximal de '.self::MAX_AGENTS_PER_LANDLORD.' agents mandatés.'],
                ]);
            }

            $ttlDays = (int) ($data['expires_days'] ?? self::INVITATION_TTL_DAYS);

            $delegation = Delegation::create([
                'bailleur_id' => $landlord->id,
                'agent_id' => $agent->id,
                'listing_id' => $listing->id,
                'status' => DelegationStatus::Pending,
                'capabilities' => $capabilities,
                'invited_at' => now(),
                'expires_at' => now()->addDays(max(1, $ttlDays)),
            ]);

            // Notification asynchrone sécurisée
            try {
                $agent->notify(new AgentInvitationNotification($delegation));
            } catch (\Throwable $e) {
                Log::warning("Échec notification invitation agent: {$e->getMessage()}");
            }

            return $delegation->load(['bailleur', 'agent', 'listing']);
        });
    }

    /**
     * Acceptation d'un mandat par l'agent invité.
     */
    public function acceptInvitation(Delegation $delegation, User $agent): Delegation
    {
        if ($delegation->agent_id !== $agent->id) {
            throw new AuthorizationException('Cette invitation ne vous est pas destinée.');
        }

        if (! $this->hasActiveAgentBadge($agent)) {
            throw new AuthorizationException('Un badge Agent actif est requis pour accepter un mandat de gestion.');
        }

        return DB::transaction(function () use ($delegation, $agent) {
            /** @var Delegation $locked */
            $locked = Delegation::where('id', $delegation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DelegationStatus::Pending) {
                throw ValidationException::withMessages([
                    'delegation' => ["Cette invitation n'est plus en attente (statut actuel : {$locked->status->value})."],
                ]);
            }

            if ($locked->expires_at && $locked->expires_at->isPast()) {
                $locked->status = DelegationStatus::Expired;
                $locked->save();

                throw ValidationException::withMessages([
                    'delegation' => ['Cette invitation a expiré.'],
                ]);
            }

            // Vérifier la validité du bailleur et du bien
            if ($locked->listing->trashed()) {
                throw ValidationException::withMessages([
                    'delegation' => ['L\'annonce immobilière concernée n\'est plus disponible.'],
                ]);
            }

            $locked->status = DelegationStatus::Active;
            $locked->answered_at = now();
            $locked->save();

            // Mettre à jour l'agent gestionnaire sur l'annonce si aucun agent n'est encore affecté
            if (! $locked->listing->agent_id) {
                $locked->listing->agent_id = $agent->id;
                $locked->listing->save();
            }

            try {
                $locked->bailleur->notify(new AgentAcceptedNotification($locked));
            } catch (\Throwable $e) {
                Log::warning("Échec notification acceptation mandat: {$e->getMessage()}");
            }

            return $locked->load(['bailleur', 'agent', 'listing']);
        });
    }

    /**
     * Refus d'un mandat par l'agent invité.
     */
    public function rejectInvitation(Delegation $delegation, User $agent): Delegation
    {
        if ($delegation->agent_id !== $agent->id) {
            throw new AuthorizationException('Cette invitation ne vous est pas destinée.');
        }

        return DB::transaction(function () use ($delegation) {
            /** @var Delegation $locked */
            $locked = Delegation::where('id', $delegation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DelegationStatus::Pending) {
                throw ValidationException::withMessages([
                    'delegation' => ["Cette invitation n'est plus en attente (statut actuel : {$locked->status->value})."],
                ]);
            }

            $locked->status = DelegationStatus::Declined;
            $locked->answered_at = now();
            $locked->save();

            try {
                $locked->bailleur->notify(new AgentDeclinedNotification($locked));
            } catch (\Throwable $e) {
                Log::warning("Échec notification refus mandat: {$e->getMessage()}");
            }

            return $locked->load(['bailleur', 'agent', 'listing']);
        });
    }

    /**
     * Révocation logique d'un mandat par le bailleur.
     */
    public function revokeDelegation(Delegation $delegation, User $actor, ?string $reason = null): Delegation
    {
        if ($delegation->bailleur_id !== $actor->id && ! $actor->isAdmin()) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à révoquer cette délégation.');
        }

        return DB::transaction(function () use ($delegation, $actor, $reason) {
            /** @var Delegation $locked */
            $locked = Delegation::where('id', $delegation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === DelegationStatus::Revoked) {
                return $locked;
            }

            $previousStatus = $locked->status;
            $locked->status = DelegationStatus::Revoked;
            $locked->revoked_at = now();
            $locked->revoked_by_id = $actor->id;

            if ($reason) {
                $meta = $locked->metadata ?? [];
                $meta['revocation_reason'] = $reason;
                $locked->metadata = $meta;
            }

            $locked->save();

            // Si l'agent était affecté comme gestionnaire exclusif sur l'annonce, le désassigner
            if ($locked->listing->agent_id === $locked->agent_id) {
                $locked->listing->agent_id = null;
                $locked->listing->save();
            }

            if ($previousStatus === DelegationStatus::Active) {
                try {
                    $locked->agent->notify(new DelegationRevokedNotification($locked, $reason));
                } catch (\Throwable $e) {
                    Log::warning("Échec notification révocation mandat: {$e->getMessage()}");
                }
            }

            return $locked->load(['bailleur', 'agent', 'listing']);
        });
    }

    /**
     * Relance un agent pour une invitation en attente.
     */
    public function remindInvitation(Delegation $delegation, User $landlord): Delegation
    {
        if ($delegation->bailleur_id !== $landlord->id && ! $landlord->isAdmin()) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à relancer cette invitation.');
        }

        if ($delegation->status !== DelegationStatus::Pending) {
            throw ValidationException::withMessages([
                'delegation' => ['Seule une invitation en attente peut être relancée.'],
            ]);
        }

        $meta = $delegation->metadata ?? [];
        $meta['last_reminded_at'] = now()->toIso8601String();
        $meta['remind_count'] = ($meta['remind_count'] ?? 0) + 1;
        $delegation->metadata = $meta;
        $delegation->save();

        try {
            $delegation->agent->notify(new AgentInvitationNotification($delegation));
        } catch (\Throwable $e) {
            Log::warning("Échec notification relance invitation: {$e->getMessage()}");
        }

        return $delegation->load(['bailleur', 'agent', 'listing']);
    }

    /**
     * Modifie les capacités ou l'expiration d'une délégation active ou en attente.
     *
     * @param  array{capabilities?: array<string>, expires_at?: string|null}  $data
     */
    public function updateDelegation(Delegation $delegation, User $landlord, array $data): Delegation
    {
        if ($delegation->bailleur_id !== $landlord->id && ! $landlord->isAdmin()) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à modifier cette délégation.');
        }

        if (! in_array($delegation->status, [DelegationStatus::Active, DelegationStatus::Pending], true)) {
            throw ValidationException::withMessages([
                'delegation' => ['Seule une délégation active ou en attente peut être modifiée.'],
            ]);
        }

        if (isset($data['capabilities']) && is_array($data['capabilities'])) {
            $valid = [DelegationCapability::Visits->value, DelegationCapability::Reply->value, DelegationCapability::Edit->value];
            $caps = array_values(array_intersect($valid, $data['capabilities']));
            if (empty($caps)) {
                $caps = [DelegationCapability::Visits->value];
            }
            $delegation->capabilities = $caps;
        }

        if (array_key_exists('expires_at', $data)) {
            $delegation->expires_at = $data['expires_at'] ? Carbon::parse($data['expires_at']) : null;
        }

        $delegation->save();

        return $delegation->load(['bailleur', 'agent', 'listing']);
    }

    /**
     * Rétablit un mandat révoqué ou expiré.
     */
    public function reinstateDelegation(Delegation $delegation, User $landlord): Delegation
    {
        if ($delegation->bailleur_id !== $landlord->id && ! $landlord->isAdmin()) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à rétablir cette délégation.');
        }

        if (! in_array($delegation->status, [DelegationStatus::Revoked, DelegationStatus::Declined, DelegationStatus::Expired], true)) {
            throw ValidationException::withMessages([
                'delegation' => ['Cette délégation est déjà active ou en cours.'],
            ]);
        }

        // Vérifier que l'agent a toujours le badge agent
        if (! $this->hasActiveAgentBadge($delegation->agent)) {
            throw ValidationException::withMessages([
                'agent_id' => ['L\'agent ne dispose plus d\'un badge Agent actif et certifié.'],
            ]);
        }

        $delegation->status = DelegationStatus::Active;
        $delegation->revoked_at = null;
        $delegation->revoked_by_id = null;
        $meta = $delegation->metadata ?? [];
        $meta['reinstated_at'] = now()->toIso8601String();
        $delegation->metadata = $meta;
        $delegation->save();

        // Réassigner l'agent sur l'annonce si aucun agent n'est affecté
        if ($delegation->listing && ! $delegation->listing->agent_id) {
            $delegation->listing->agent_id = $delegation->agent_id;
            $delegation->listing->save();
        }

        return $delegation->load(['bailleur', 'agent', 'listing']);
    }

    /**
     * Détermine si un agent peut exercer une capacité donnée sur un bien.
     */
    public function canAgentPerform(User $agent, Listing $listing, DelegationCapability|string $capability): bool
    {
        if ($agent->isAdmin()) {
            return true;
        }

        if (! $this->hasActiveAgentBadge($agent)) {
            return false;
        }

        $capValue = $capability instanceof DelegationCapability ? $capability->value : (string) $capability;

        /** @var Delegation|null $delegation */
        $delegation = Delegation::where('listing_id', $listing->id)
            ->where('agent_id', $agent->id)
            ->where('status', DelegationStatus::Active->value)
            ->whereNull('revoked_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $delegation) {
            return false;
        }

        return $delegation->hasCapability($capValue);
    }

    /**
     * Portefeuille des biens délégués à l'agent.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getAgentProperties(User $agent, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        if (! $this->hasActiveAgentBadge($agent)) {
            throw new AuthorizationException('Votre badge Agent est inactif ou a expiré.');
        }

        $query = Listing::query()
            ->whereHas('delegations', function (Builder $q) use ($agent) {
                $q->where('agent_id', $agent->id)
                    ->where('status', DelegationStatus::Active->value)
                    ->whereNull('revoked_at')
                    ->where(function (Builder $sub) {
                        $sub->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    });
            })
            ->with([
                'photos',
                'videos',
                'owner',
                'delegations' => function ($q) use ($agent) {
                    $q->where('agent_id', $agent->id)
                        ->where('status', DelegationStatus::Active->value);
                },
            ]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['city'])) {
            $query->whereRaw('LOWER(city) = ?', [strtolower(trim((string) $filters['city']))]);
        }

        return $query->latest('created_at')->paginate(max(1, min(50, $perPage)));
    }

    /**
     * Prospects et contacts autorisés pour l'agent dans le cadre de ses mandats actifs.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getAgentProspects(User $agent, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        if (! $this->hasActiveAgentBadge($agent)) {
            throw new AuthorizationException('Votre badge Agent est inactif ou a expiré.');
        }

        // Biens sur lesquels l'agent dispose des capacités visits ou reply
        $authorizedListingIds = Delegation::where('agent_id', $agent->id)
            ->where('status', DelegationStatus::Active->value)
            ->whereNull('revoked_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get()
            ->filter(fn (Delegation $d) => $d->hasCapability('visits') || $d->hasCapability('reply'))
            ->pluck('listing_id')
            ->all();

        $query = VisitReservation::whereIn('listing_id', $authorizedListingIds)
            ->with(['prospect', 'listing', 'slot'])
            ->latest('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['listing_id'])) {
            $query->where('listing_id', $filters['listing_id']);
        }

        return $query->paginate(max(1, min(50, $perPage)));
    }

    /**
     * Annuaire public et recherche des agents immobiliers certifiés.
     *
     * @param  array<string, mixed>  $filters
     */
    public function searchAgents(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = User::query()
            ->where(function (Builder $q) {
                $q->whereIn('badge', ['agent', 'double'])
                    ->orWhereHas('activeSubscriptions', function (Builder $sub) {
                        $sub->where('badge_type', 'agent');
                    });
            })
            ->where('is_verified', true)
            ->where(function (Builder $q) {
                $q->whereNull('verification_lapsed')->orWhere('verification_lapsed', false);
            })
            ->whereNull('suspended_until')
            ->whereNull('locked_until');

        if (! empty($filters['city'])) {
            $query->whereRaw('LOWER(city) = ?', [strtolower(trim((string) $filters['city']))]);
        }

        if (! empty($filters['neighbourhood'])) {
            $query->whereRaw('LOWER(neighbourhood) = ?', [strtolower(trim((string) $filters['neighbourhood']))]);
        }

        if (! empty($filters['q'])) {
            $term = strtolower(trim((string) $filters['q']));
            $query->where(function (Builder $q) use ($term) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(handle) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(city) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(neighbourhood) LIKE ?', ["%{$term}%"]);
            });
        }

        if (! empty($filters['active_30d']) || ! empty($filters['has_activity_30d'])) {
            $cutoff = now()->subDays(30);
            $query->where(function (Builder $q) use ($cutoff) {
                $q->where('updated_at', '>=', $cutoff)
                    ->orWhereHas('listings', fn ($l) => $l->where('updated_at', '>=', $cutoff))
                    ->orWhereHas('delegationsAsAgent', fn ($d) => $d->where('updated_at', '>=', $cutoff));
            });
        }

        return $query->latest('created_at')->paginate(max(1, min(50, $perPage)));
    }
}
