<?php

namespace App\Services\Listing;

use App\Enums\Delegation\DelegationStatus;
use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingPhotoRoom;
use App\Enums\Listing\ListingStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Events\Listing\ListingPublished;
use App\Models\Delegation\Delegation;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingMedia;
use App\Models\Listing\ListingSave;
use App\Models\User;
use App\Services\Admin\AdminListingControlService;
use App\Services\Media\MediaStorageService;
use App\Services\Subscription\BadgeResolutionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ListingService
{
    public function __construct(
        protected MediaStorageService $mediaStorage,
        protected BadgeResolutionService $badgeResolution,
        protected ListingPricingService $pricingService
    ) {}

    /**
     * Vérifie si un utilisateur dispose du droit de publier une annonce (rôle admin ou badge actif).
     */
    public function canPublish(User $user): bool
    {
        // 1. Les administrateurs peuvent publier sans restriction de badge
        if ($user->isAdmin()) {
            return true;
        }

        // 2. Vérification des sanctions ou suspensions actives
        if ($user->locked_until && $user->locked_until->isFuture()) {
            return false;
        }

        if ($user->suspended_until && $user->suspended_until->isFuture()) {
            return false;
        }

        if ($user->verification_lapsed) {
            return false;
        }

        // 3. La vérification d'identité (KYC) est un prérequis obligatoire pour tout badge
        if (! $user->is_verified) {
            return false;
        }

        // 4. Vérification du badge direct certifié ou résolu via abonnements
        if (in_array($user->badge, ['landlord', 'agent', 'double'], true)) {
            return true;
        }

        $badgeStatus = $this->badgeResolution->resolveUserBadges($user);

        return in_array($badgeStatus['badge'], ['landlord', 'agent', 'double'], true);
    }

    /**
     * Vérifie si l'acteur dispose des droits de gestion/modification sur une annonce donnée.
     */
    public function canManage(User $actor, Listing $listing): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        // Le propriétaire de l'annonce
        if ($actor->id === $listing->owner_id) {
            return true;
        }

        // L'agent mandaté via une délégation active
        $activeDelegation = Delegation::where('listing_id', $listing->id)
            ->where('agent_id', $actor->id)
            ->where('status', DelegationStatus::Active->value)
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($activeDelegation && $this->badgeResolution->resolveUserBadges($actor)['hasAgent']) {
            return true;
        }

        // Rétrocompatibilité : agent directement assigné sur l'annonce sans enregistrement de délégation
        if ($listing->agent_id && $actor->id === $listing->agent_id) {
            // S'il existe une délégation révoquée ou déclinée pour cet agent, refuser
            $hasRevokedDelegation = Delegation::where('listing_id', $listing->id)
                ->where('agent_id', $actor->id)
                ->where(function ($q) {
                    $q->where('status', DelegationStatus::Revoked->value)
                        ->orWhereNotNull('revoked_at')
                        ->orWhere('status', DelegationStatus::Declined->value);
                })
                ->exists();

            if (! $hasRevokedDelegation) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vérifie si l'acteur est autorisé à éditer les informations d'une annonce (propriétaire ou agent avec edit).
     */
    public function canEditListing(User $actor, Listing $listing): bool
    {
        if ($actor->isAdmin() || $actor->id === $listing->owner_id) {
            return true;
        }

        if (! $this->canManage($actor, $listing)) {
            return false;
        }

        $delegation = Delegation::where('listing_id', $listing->id)
            ->where('agent_id', $actor->id)
            ->where('status', DelegationStatus::Active->value)
            ->first();

        if ($delegation) {
            return $delegation->hasCapability('edit');
        }

        return (bool) ($listing->agent_id && $actor->id === $listing->agent_id);
    }

    /**
     * Vérifie si l'acteur est autorisé à gérer les visites d'une annonce (propriétaire ou agent avec visits).
     */
    public function canManageVisits(User $actor, Listing $listing): bool
    {
        if ($actor->isAdmin() || $actor->id === $listing->owner_id) {
            return true;
        }

        if (! $this->canManage($actor, $listing)) {
            return false;
        }

        $delegation = Delegation::where('listing_id', $listing->id)
            ->where('agent_id', $actor->id)
            ->where('status', DelegationStatus::Active->value)
            ->first();

        if ($delegation) {
            return $delegation->hasCapability('visits');
        }

        return (bool) ($listing->agent_id && $actor->id === $listing->agent_id);
    }

    /**
     * Vérifie si l'acteur est autorisé à répondre aux messages d'une annonce (propriétaire ou agent avec reply).
     */
    public function canReplyMessages(User $actor, Listing $listing): bool
    {
        if ($actor->isAdmin() || $actor->id === $listing->owner_id) {
            return true;
        }

        if (! $this->canManage($actor, $listing)) {
            return false;
        }

        $delegation = Delegation::where('listing_id', $listing->id)
            ->where('agent_id', $actor->id)
            ->where('status', DelegationStatus::Active->value)
            ->first();

        if ($delegation) {
            return $delegation->hasCapability('reply');
        }

        return (bool) ($listing->agent_id && $actor->id === $listing->agent_id);
    }

    /**
     * Sauvegarde ou reprise d'un brouillon d'annonce (CR-40 à CR-48).
     * Permet une sauvegarde progressive même si le formulaire est incomplet.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(User $user, array $data, ?string $submissionKey = null): Listing
    {
        $key = $submissionKey ?? ($data['submission_key'] ?? ($data['key'] ?? null));

        return DB::transaction(function () use ($user, $data, $key) {
            $listing = null;

            if ($key) {
                $listing = Listing::where('owner_id', $user->id)
                    ->where('submission_key', $key)
                    ->first();
            }

            if (! $listing) {
                $listing = new Listing;
                $listing->owner_id = $user->id;
                $listing->created_by_id = $user->id;
                $listing->submission_key = $key ?: (string) Str::uuid();
                $listing->status = ListingStatus::Draft;
            }

            // Mise à jour des valeurs fournies (si présentes)
            if (isset($data['title'])) {
                $listing->title = mb_substr(trim((string) $data['title']), 0, 100);
            }
            if (isset($data['description'])) {
                $listing->description = trim((string) $data['description']);
            }
            if (isset($data['mode'])) {
                $modeValue = $data['mode'] instanceof ListingMode ? $data['mode']->value : (string) $data['mode'];
                $listing->mode = ListingMode::tryFrom($modeValue) ?? ListingMode::Rent;
            }
            if (isset($data['kind'])) {
                $kindValue = $data['kind'] instanceof ListingKind ? $data['kind']->value : (string) $data['kind'];
                $listing->kind = ListingKind::tryFrom($kindValue) ?? ListingKind::Flat;
            }
            if (isset($data['price'])) {
                $listing->price = (float) $data['price'];
            }
            if (isset($data['currency'])) {
                $listing->currency = (string) $data['currency'];
            }
            if (array_key_exists('period', $data)) {
                $listing->period = $data['period'];
            }
            if (isset($data['deposit_months'])) {
                $listing->deposit_months = (int) $data['deposit_months'];
            }
            if (isset($data['advance_months'])) {
                $listing->advance_months = (int) $data['advance_months'];
            }
            if (isset($data['visit_fee'])) {
                $listing->visit_fee = (float) $data['visit_fee'];
            }
            if (isset($data['charges'])) {
                $listing->charges = (float) $data['charges'];
            }
            if (isset($data['negotiable'])) {
                $listing->negotiable = (bool) $data['negotiable'];
            }
            if (isset($data['utilities_included'])) {
                $listing->utilities_included = (bool) $data['utilities_included'];
            }
            if (isset($data['bedrooms'])) {
                $listing->bedrooms = (int) $data['bedrooms'];
            }
            if (isset($data['showers'])) {
                $listing->showers = (int) $data['showers'];
            }
            if (isset($data['area'])) {
                $listing->area = (float) $data['area'];
            }
            if (array_key_exists('floor', $data)) {
                $listing->floor = $data['floor'] !== null ? (int) $data['floor'] : null;
            }
            if (array_key_exists('available_from', $data)) {
                $listing->available_from = ! empty($data['available_from']) ? $data['available_from'] : null;
            }
            if (array_key_exists('min_lease_months', $data)) {
                $listing->min_lease_months = $data['min_lease_months'] !== null ? (int) $data['min_lease_months'] : null;
            }
            if (isset($data['amenities']) && is_array($data['amenities'])) {
                $listing->amenities = $data['amenities'];
            }
            if (isset($data['city'])) {
                $listing->city = trim((string) $data['city']);
            }
            if (isset($data['neighbourhood'])) {
                $listing->neighbourhood = trim((string) $data['neighbourhood']);
            }
            if (isset($data['location'])) {
                $listing->location = trim((string) $data['location']);
            }
            if (isset($data['landmark'])) {
                $listing->location = trim((string) $data['landmark']);
            }
            if (isset($data['lat'])) {
                $listing->lat = (float) $data['lat'];
            }
            if (isset($data['lng'])) {
                $listing->lng = (float) $data['lng'];
            }
            if (isset($data['pin']) && is_array($data['pin'])) {
                $listing->lat = (float) ($data['pin']['lat'] ?? $listing->lat);
                $listing->lng = (float) ($data['pin']['lng'] ?? $listing->lng);
            }

            if (isset($data['step'])) {
                $listing->draft_step = (string) $data['step'];
            }

            // Conserver l'état brut des valeurs de formulaire (pour compatibilité front ListingFormValues)
            $currentDraftData = $listing->draft_data ?? [];
            $listing->draft_data = array_merge($currentDraftData, $data['values'] ?? $data);

            $listing->save();

            return $listing->fresh(['photos', 'videos']);
        });
    }

    /**
     * Récupère le brouillon actif pour un utilisateur et une clé optionnelle.
     */
    public function getDraft(User $user, ?string $submissionKey = null): ?Listing
    {
        $query = Listing::where('owner_id', $user->id)->where('status', ListingStatus::Draft);

        if ($submissionKey) {
            $query->where('submission_key', $submissionKey);
        }

        return $query->with(['photos', 'videos'])->latest('updated_at')->first();
    }

    /**
     * Publication officielle d'une annonce ou soumission en revue de modération (CR-48).
     *
     * @param  array<string, mixed>  $data
     */
    public function createOrPublishListing(User $user, array $data, bool $submitToReview = true): Listing
    {
        // 1. Contrôle strict des droits de publication côté serveur
        if (! $this->canPublish($user)) {
            throw new AuthorizationException("Votre compte ne dispose pas d'un badge certifié actif permettant de publier une annonce.");
        }

        return DB::transaction(function () use ($user, $data, $submitToReview) {
            $key = $data['submission_key'] ?? ($data['key'] ?? null);
            $listing = null;

            if ($key) {
                $listing = Listing::where('owner_id', $user->id)
                    ->where('submission_key', $key)
                    ->first();
            }

            if (! $listing) {
                $listing = new Listing;
                $listing->owner_id = $user->id;
                $listing->created_by_id = $user->id;
                $listing->submission_key = $key ?: (string) Str::uuid();
            }

            // Attribution des champs métier
            $listing->title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 100);
            $listing->description = trim((string) ($data['description'] ?? ''));

            $mode = $data['mode'] instanceof ListingMode ? $data['mode'] : ListingMode::tryFrom((string) ($data['mode'] ?? 'rent'));
            $listing->mode = $mode ?? ListingMode::Rent;

            $kind = $data['kind'] instanceof ListingKind ? $data['kind'] : ListingKind::tryFrom((string) ($data['kind'] ?? 'flat'));
            $listing->kind = $kind ?? ListingKind::Flat;

            $listing->price = (float) ($data['price'] ?? 0);
            $listing->currency = (string) ($data['currency'] ?? 'XAF');
            $listing->period = ($listing->mode === ListingMode::Sale) ? null : 'month';

            $listing->deposit_months = (int) ($data['deposit_months'] ?? 0);
            $listing->advance_months = (int) ($data['advance_months'] ?? 0);
            $listing->visit_fee = (float) ($data['visit_fee'] ?? 0);
            $listing->charges = (float) ($data['charges'] ?? 0);
            $listing->negotiable = (bool) ($data['negotiable'] ?? false);
            $listing->utilities_included = (bool) ($data['utilities_included'] ?? false);

            $listing->bedrooms = (int) ($data['bedrooms'] ?? 0);
            $listing->showers = (int) ($data['showers'] ?? 0);
            $listing->area = (float) ($data['area'] ?? 0);
            $listing->floor = array_key_exists('floor', $data) && $data['floor'] !== null ? (int) $data['floor'] : null;
            $listing->available_from = ! empty($data['available_from']) ? $data['available_from'] : null;
            $listing->min_lease_months = array_key_exists('min_lease_months', $data) && $data['min_lease_months'] !== null ? (int) $data['min_lease_months'] : null;
            $listing->amenities = is_array($data['amenities'] ?? null) ? $data['amenities'] : [];

            $listing->city = trim((string) ($data['city'] ?? ''));
            $listing->neighbourhood = trim((string) ($data['neighbourhood'] ?? ''));
            $listing->location = trim((string) ($data['landmark'] ?? $data['location'] ?? ''));

            if (isset($data['pin']) && is_array($data['pin'])) {
                $listing->lat = (float) ($data['pin']['lat'] ?? 0);
                $listing->lng = (float) ($data['pin']['lng'] ?? 0);
            } elseif (isset($data['lat'], $data['lng'])) {
                $listing->lat = (float) $data['lat'];
                $listing->lng = (float) $data['lng'];
            }

            // Génération des attributs de synthèse pour affichage carte/feed
            $listing->attributes = $this->composeAttributes($listing);

            // Cycle de modération vs publication
            if ($submitToReview) {
                $listing->status = ListingStatus::Review;
                $listing->submitted_at = now();
                $listing->review_due_at = now()->addMinutes(3); // CR-48 échéance persistante
            } else {
                if ($listing->withdrawn_by_bango && ! $user->isAdmin()) {
                    throw ValidationException::withMessages([
                        'status' => ["Cette annonce a été retirée par l'administration de BANGO. Seul le back-office peut autoriser sa republication."],
                    ]);
                }

                $listing->status = ListingStatus::Published;
                $listing->published_at = now();
            }

            $listing->save();

            $freshListing = $listing->fresh(['photos', 'videos', 'owner']);

            if ($freshListing->status === ListingStatus::Published) {
                ListingPublished::dispatch($freshListing);
            }

            return $freshListing;
        });
    }

    /**
     * Met à jour le texte, le prix et les frais d'une annonce sans relancer la modération vidéo (CR-48).
     *
     * @param  array<string, mixed>  $patch
     */
    public function updateListingDetails(Listing $listing, User $actor, array $patch): Listing
    {
        if (! $this->canEditListing($actor, $listing)) {
            throw new AuthorizationException("Vous n'êtes pas autorisé à modifier cette annonce (capacité 'edit' requise).");
        }

        return DB::transaction(function () use ($listing, $patch) {
            if (isset($patch['title'])) {
                $listing->title = mb_substr(trim((string) $patch['title']), 0, 100);
            }
            if (isset($patch['description'])) {
                $listing->description = trim((string) $patch['description']);
            }
            if (isset($patch['price'])) {
                $listing->price = (float) $patch['price'];
            }
            if (isset($patch['deposit_months'])) {
                $listing->deposit_months = (int) $patch['deposit_months'];
            }
            if (isset($patch['advance_months'])) {
                $listing->advance_months = (int) $patch['advance_months'];
            }
            if (isset($patch['visit_fee'])) {
                $listing->visit_fee = (float) $patch['visit_fee'];
            }
            if (isset($patch['charges'])) {
                $listing->charges = (float) $patch['charges'];
            }
            if (isset($patch['negotiable'])) {
                $listing->negotiable = (bool) $patch['negotiable'];
            }
            if (isset($patch['utilities_included'])) {
                $listing->utilities_included = (bool) $patch['utilities_included'];
            }
            if (array_key_exists('floor', $patch)) {
                $listing->floor = $patch['floor'] !== null ? (int) $patch['floor'] : null;
            }
            if (array_key_exists('available_from', $patch)) {
                $listing->available_from = ! empty($patch['available_from']) ? $patch['available_from'] : null;
            }
            if (array_key_exists('min_lease_months', $patch)) {
                $listing->min_lease_months = $patch['min_lease_months'] !== null ? (int) $patch['min_lease_months'] : null;
            }
            if (isset($patch['landmark'])) {
                $listing->location = trim((string) $patch['landmark']);
            }
            if (array_key_exists('amenities', $patch)) {
                $listing->amenities = is_array($patch['amenities']) ? array_values($patch['amenities']) : [];
            }

            $listing->attributes = $this->composeAttributes($listing);
            $listing->save();

            return $listing->fresh(['photos', 'videos', 'owner']);
        });
    }

    /**
     * Transition de statut de l'annonce (BL-18, Modération, etc.).
     */
    public function updateListingStatus(Listing $listing, User $actor, ListingStatus $newStatus, ?string $reason = null): Listing
    {
        if (! $this->canEditListing($actor, $listing)) {
            throw new AuthorizationException("Vous n'êtes pas autorisé à modifier le statut de cette annonce.");
        }

        // Si l'acteur n'est pas admin, il ne peut choisir que parmi published, reserved et taken
        if (! $actor->isAdmin() && ! $newStatus->isChoosableByOwner()) {
            throw ValidationException::withMessages([
                'status' => ["Le statut '{$newStatus->value}' ne peut pas être sélectionné directement par le propriétaire."],
            ]);
        }

        // Point 17 : Verrou « retirée par BANGO » que seul le back-office peut lever
        if ($newStatus === ListingStatus::Published && $listing->withdrawn_by_bango && ! $actor->isAdmin()) {
            throw ValidationException::withMessages([
                'status' => ["Cette annonce a été retirée par l'administration de BANGO. Seul le back-office est habilité à lever ce verrou."],
            ]);
        }

        if (! $listing->status->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => ["Transition interdite depuis '{$listing->status->value}' vers '{$newStatus->value}'."],
            ]);
        }

        $publishedNow = false;

        $updatedListing = DB::transaction(function () use ($listing, $newStatus, $reason, &$publishedNow) {
            $oldStatus = $listing->status;
            $listing->status = $newStatus;

            if ($newStatus === ListingStatus::Published && ! $listing->published_at) {
                $listing->published_at = now();
            }

            if ($newStatus === ListingStatus::Published && $oldStatus !== ListingStatus::Published) {
                $publishedNow = true;
            }

            if ($newStatus === ListingStatus::Taken) {
                $listing->taken_at = now();

                // Point 18 : Clôture, annulation et remboursement des visites confirmées / à venir
                app(AdminListingControlService::class)
                    ->cancelAndRefundUpcomingVisits($listing, 'Le bien a été loué / retiré du marché');
            }

            if ($newStatus === ListingStatus::Rejected && $reason) {
                $listing->rejection_reason = $reason;
            }

            $listing->save();

            return $listing;
        });

        if ($publishedNow) {
            ListingPublished::dispatch($updatedListing);
        }

        return $updatedListing;
    }

    /**
     * Tableau de bord des annonces du bailleur avec filtres et pagination (BL-01, BL-18).
     *
     * @param  array<string, mixed>  $filters
     */
    public function getLandlordProperties(User $landlord, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Listing::where('owner_id', $landlord->id)
            ->with(['photos', 'videos', 'activeDelegation.agent'])
            ->withCount([
                'visitReservations as pending_visits_count' => function ($q) {
                    $q->whereIn('status', [
                        VisitReservationStatus::Requested->value,
                        VisitReservationStatus::PendingPayment->value,
                    ]);
                },
                'visitReservations as confirmed_visits_count' => function ($q) {
                    $q->where('status', VisitReservationStatus::Confirmed->value);
                },
            ]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['city'])) {
            $query->whereRaw('LOWER(city) = ?', [strtolower(trim((string) $filters['city']))]);
        }

        if (! empty($filters['mode'])) {
            $query->where('mode', $filters['mode']);
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = strtolower($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['created_at', 'price', 'views_count', 'saves_count', 'published_at'];
        if (in_array($sort, $allowedSorts, true)) {
            $query->orderBy($sort, $direction);
        } else {
            $query->orderByDesc('created_at');
        }

        return $query->paginate(max(1, min(50, $perPage)));
    }

    /**
     * Sauvegarde idempotente ou bascule d'une annonce dans les favoris.
     *
     * @param  'save'|'unsave'|'toggle'|null  $action
     */
    public function saveListing(Listing $listing, User $user, ?string $action = 'save'): bool
    {
        return DB::transaction(function () use ($listing, $user, $action) {
            $existing = ListingSave::where('user_id', $user->id)
                ->where('listing_id', $listing->id)
                ->first();

            if ($action === 'unsave') {
                if ($existing) {
                    $existing->delete();
                    $listing->decrement('saves_count');
                }

                return false;
            }

            if ($action === 'toggle') {
                if ($existing) {
                    $existing->delete();
                    $listing->decrement('saves_count');

                    return false;
                }

                ListingSave::firstOrCreate([
                    'user_id' => $user->id,
                    'listing_id' => $listing->id,
                ]);
                $listing->increment('saves_count');

                return true;
            }

            // Par défaut ou 'save' : Idempotence stricte
            if ($existing) {
                return true;
            }

            ListingSave::firstOrCreate([
                'user_id' => $user->id,
                'listing_id' => $listing->id,
            ]);
            $listing->increment('saves_count');

            return true;
        });
    }

    /**
     * Sauvegarde ou retrait d'une annonce des favoris (toggle).
     */
    public function toggleSave(Listing $listing, User $user): bool
    {
        return $this->saveListing($listing, $user, 'toggle');
    }

    /**
     * Ajout d'une photo téléversée à une annonce avec qualification de pièce optionnelle.
     */
    public function addPhoto(Listing $listing, User $actor, UploadedFile $file, ?ListingPhotoRoom $room = null): ListingMedia
    {
        if (! $this->canManage($actor, $listing)) {
            throw new AuthorizationException("Vous n'êtes pas autorisé à ajouter des médias sur cette annonce.");
        }

        $stored = $this->mediaStorage->storeListingPhoto($file, $actor);
        $nextOrder = (int) $listing->photos()->max('order') + 1;

        return ListingMedia::create([
            'listing_id' => $listing->id,
            'type' => 'image',
            'path' => $stored['path'],
            'url' => $stored['url'],
            'mime_type' => $stored['mime_type'],
            'file_size' => $stored['file_size'],
            'width' => $stored['width'],
            'height' => $stored['height'],
            'order' => $nextOrder,
            'room' => $room,
        ]);
    }

    /**
     * Ajout d'une visite vidéo guidée à une annonce.
     */
    public function addVideo(Listing $listing, User $actor, UploadedFile $file, ?int $duration = null, ?int $width = null, ?int $height = null): ListingMedia
    {
        if (! $this->canManage($actor, $listing)) {
            throw new AuthorizationException("Vous n'êtes pas autorisé à ajouter des médias sur cette annonce.");
        }

        $stored = $this->mediaStorage->storeListingVideo($file, $actor, null, $duration, $width, $height);

        return ListingMedia::create([
            'listing_id' => $listing->id,
            'type' => 'video',
            'path' => $stored['path'],
            'url' => $stored['url'],
            'mime_type' => $stored['mime_type'],
            'file_size' => $stored['file_size'],
            'width' => $stored['width'],
            'height' => $stored['height'],
            'duration' => $stored['duration'],
            'order' => 0,
        ]);
    }

    /**
     * Réorganisation ordonnée des médias d'une annonce.
     *
     * @param  array<string>  $orderedMediaIds
     */
    public function reorderMedia(Listing $listing, User $actor, array $orderedMediaIds): void
    {
        if (! $this->canManage($actor, $listing)) {
            throw new AuthorizationException("Vous n'êtes pas autorisé à modifier l'ordre des médias de cette annonce.");
        }

        DB::transaction(function () use ($listing, $orderedMediaIds) {
            foreach ($orderedMediaIds as $index => $mediaId) {
                ListingMedia::where('listing_id', $listing->id)
                    ->where('id', $mediaId)
                    ->update(['order' => $index]);
            }
        });
    }

    /**
     * Suppression sécurisée d'un média d'annonce.
     */
    public function removeMedia(Listing $listing, User $actor, string $mediaId): void
    {
        if (! $this->canManage($actor, $listing)) {
            throw new AuthorizationException("Vous n'êtes pas autorisé à supprimer ce média.");
        }

        $media = ListingMedia::where('listing_id', $listing->id)->where('id', $mediaId)->first();
        if ($media) {
            $this->mediaStorage->delete($media->path);
            $media->delete();
        }
    }

    /**
     * Compose les attributs courts descriptifs pour le feed et la carte.
     *
     * @return array<string>
     */
    protected function composeAttributes(Listing $listing): array
    {
        $attributes = [];

        if ($listing->bedrooms === 0) {
            $attributes[] = 'Studio';
        } else {
            $attributes[] = "{$listing->bedrooms} chambre".($listing->bedrooms > 1 ? 's' : '');
        }

        if ($listing->showers > 0) {
            $attributes[] = "{$listing->showers} douche".($listing->showers > 1 ? 's' : '');
        }

        if ($listing->area > 0) {
            $attributes[] = round($listing->area).' m²';
        }

        if (! empty($listing->amenities) && in_array('furnished', $listing->amenities, true)) {
            $attributes[] = 'Meublé';
        }

        return $attributes;
    }
}
