<?php

namespace App\Services\Content;

use App\Enums\Square\PostAudience;
use App\Exceptions\Content\ResourceGoneException;
use App\Exceptions\Content\ResourceLockedException;
use App\Exceptions\Content\ResourceNotFoundException;
use App\Models\Content\ShortLink;
use App\Models\Listing\Listing;
use App\Models\Page\Page;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\Support\Report;
use App\Models\User;
use Illuminate\Support\Str;

class LinkResolverService
{
    /**
     * Crée un lien court unique pour une cible donnée.
     */
    public function createShortLink(
        string $targetType,
        string|int $targetId,
        ?User $creator = null,
        ?int $durationDays = null
    ): ShortLink {
        do {
            $code = Str::lower(Str::random(8));
        } while (ShortLink::where('code', $code)->exists());

        return ShortLink::create([
            'code' => $code,
            'target_type' => strtolower($targetType),
            'target_id' => (string) $targetId,
            'creator_id' => $creator?->id,
            'expires_at' => $durationDays ? now()->addDays($durationDays) : null,
        ]);
    }

    /**
     * Résout un lien ou chemin universel et renvoie les métadonnées de destination.
     *
     * @return array<string, mixed>
     *
     * @throws ResourceNotFoundException
     * @throws ResourceGoneException
     * @throws ResourceLockedException
     */
    public function resolve(string $rawPath, ?User $currentUser = null): array
    {
        $path = $this->sanitizePath($rawPath);

        // 1. Détection des liens courts (/r/{code})
        if (preg_match('#^(?:/)?r/([a-z0-9_-]+)$#i', $path, $matches)) {
            $code = $matches[1];
            $shortLink = ShortLink::where('code', $code)->first();

            if ($shortLink === null || $shortLink->isExpired()) {
                throw new ResourceNotFoundException('Lien court introuvable ou expiré.', 'SHORT_LINK_NOT_FOUND', 'short_link', $code);
            }

            $shortLink->incrementClicks();

            return $this->resolveTarget($shortLink->target_type, $shortLink->target_id, $currentUser, $shortLink);
        }

        // 2. Détection des chemins directs de publications (/posts/{id})
        if (preg_match('#^(?:/)?posts/([0-9]+)$#i', $path, $matches)) {
            return $this->resolveTarget('post', $matches[1], $currentUser);
        }

        // 3. Détection des chemins directs de Reels (/reels/{uuid})
        if (preg_match('#^(?:/)?reels/([a-z0-9-]+)$#i', $path, $matches)) {
            return $this->resolveTarget('reel', $matches[1], $currentUser);
        }

        // 4. Détection des chemins directs d'annonces immobilières (/listings/{uuid})
        if (preg_match('#^(?:/)?listings/([a-z0-9-]+)$#i', $path, $matches)) {
            return $this->resolveTarget('listing', $matches[1], $currentUser);
        }

        // 5. Détection des profils utilisateurs (/users/{id}, /p/{handle}, /@{handle})
        if (preg_match('#^(?:/)?users/([0-9]+)$#i', $path, $matches)) {
            return $this->resolveTarget('user', $matches[1], $currentUser);
        }
        if (preg_match('#^(?:/)?(?:p/|@)([a-z0-9_.-]+)$#i', $path, $matches)) {
            return $this->resolveTarget('user', $matches[1], $currentUser);
        }

        // 6. Détection des Pages Professionnelles (/pages/{id_or_handle})
        if (preg_match('#^(?:/)?pages/([a-z0-9_.-]+)$#i', $path, $matches)) {
            return $this->resolveTarget('page', $matches[1], $currentUser);
        }

        throw new ResourceNotFoundException('Format de lien non reconnu ou invalide.', 'INVALID_LINK_FORMAT', null, $rawPath);
    }

    /**
     * Assainit et extrait le chemin relatif à partir d'une URL complète ou d'un fragment.
     */
    protected function sanitizePath(string $rawPath): string
    {
        $path = trim($rawPath);

        // Si l'utilisateur fournit une URL absolue (ex: https://bango.africa/posts/1)
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $parsed = parse_url($path);
            $path = $parsed['path'] ?? '/';
        }

        return ltrim($path, '/');
    }

    /**
     * Résout la cible unitaire selon son type.
     *
     * @return array<string, mixed>
     */
    protected function resolveTarget(string $type, string $id, ?User $currentUser, ?ShortLink $shortLink = null): array
    {
        return match (strtolower($type)) {
            'post' => $this->resolvePost($id, $currentUser, $shortLink),
            'reel' => $this->resolveReel($id, $currentUser, $shortLink),
            'listing' => $this->resolveListing($id, $currentUser, $shortLink),
            'user' => $this->resolveUser($id, $currentUser, $shortLink),
            'page' => $this->resolvePage($id, $currentUser, $shortLink),
            default => throw new ResourceNotFoundException('Type de ressource cible non supporté.', 'UNSUPPORTED_TARGET_TYPE', $type, $id),
        };
    }

    /**
     * Résolution d'une publication Square.
     *
     * @return array<string, mixed>
     */
    protected function resolvePost(string $id, ?User $currentUser, ?ShortLink $shortLink): array
    {
        $post = Post::withTrashed()->with('user')->find($id);

        if ($post === null) {
            throw new ResourceNotFoundException('Publication introuvable.', 'POST_NOT_FOUND', 'post', $id);
        }

        if ($post->trashed()) {
            throw new ResourceGoneException('Cette publication a été supprimée définitivement par son auteur.', 'POST_GONE', 'post', $id);
        }

        // Contrôle de modération (auteur suspendu ou rapport résolu avec blocage)
        if ($this->isPostModerated($post)) {
            throw new ResourceLockedException('Cette publication est temporairement verrouillée ou indisponible suite à une décision de modération.', 'POST_LOCKED', 'post', $id);
        }

        // Vérification des blocages relationnels
        if ($currentUser !== null && $post->user_id !== null) {
            if ($currentUser->hasBlocked($post->user_id) || $currentUser->isBlockedBy($post->user_id)) {
                throw new ResourceNotFoundException('Publication introuvable ou inaccessible.', 'POST_NOT_FOUND', 'post', $id);
            }
        }

        $requiresAuth = false;
        $isAccessible = true;

        if ($post->audience === PostAudience::Followers) {
            if ($currentUser === null) {
                $requiresAuth = true;
                $isAccessible = false;
            } elseif ($currentUser->id !== $post->user_id && ! $currentUser->isFollowing($post->user)) {
                $isAccessible = false;
            }
        }

        return [
            'is_valid' => true,
            'target_type' => 'post',
            'target_id' => (string) $post->id,
            'status' => $post->is_archived ? 'archived' : 'available',
            'requires_auth' => $requiresAuth,
            'is_accessible' => $isAccessible,
            'metadata' => [
                'caption_preview' => Str::limit($post->body ?? '', 100),
                'author_handle' => $post->user?->handle,
                'author_name' => $post->user?->name,
                'is_archived' => (bool) $post->is_archived,
                'created_at' => $post->created_at?->toIso8601String(),
            ],
            'short_link' => $shortLink ? ['code' => $shortLink->code, 'clicks_count' => $shortLink->clicks_count] : null,
        ];
    }

    /**
     * Résolution d'un Reel.
     *
     * @return array<string, mixed>
     */
    protected function resolveReel(string $id, ?User $currentUser, ?ShortLink $shortLink): array
    {
        $reel = Reel::withTrashed()->with('user')->find($id);

        if ($reel === null) {
            throw new ResourceNotFoundException('Reel introuvable.', 'REEL_NOT_FOUND', 'reel', $id);
        }

        if ($reel->trashed()) {
            throw new ResourceGoneException('Ce Reel a été supprimé définitivement par son auteur.', 'REEL_GONE', 'reel', $id);
        }

        if ($this->isReelModerated($reel)) {
            throw new ResourceLockedException('Ce Reel est temporairement indisponible suite à une décision de modération.', 'REEL_LOCKED', 'reel', $id);
        }

        if ($currentUser !== null && $reel->user_id !== null) {
            if ($currentUser->hasBlocked($reel->user_id) || $currentUser->isBlockedBy($reel->user_id)) {
                throw new ResourceNotFoundException('Reel introuvable ou inaccessible.', 'REEL_NOT_FOUND', 'reel', $id);
            }
        }

        $isReady = $reel->status === 'ready' || (is_object($reel->status) && $reel->status->value === 'ready');

        return [
            'is_valid' => true,
            'target_type' => 'reel',
            'target_id' => (string) $reel->id,
            'status' => $isReady ? 'available' : 'processing',
            'requires_auth' => false,
            'is_accessible' => $isReady,
            'metadata' => [
                'caption_preview' => Str::limit($reel->caption ?? '', 100),
                'thumbnail_url' => $reel->thumbnail_url,
                'author_handle' => $reel->user?->handle,
                'duration' => $reel->duration,
                'views_count' => $reel->views_count,
            ],
            'short_link' => $shortLink ? ['code' => $shortLink->code, 'clicks_count' => $shortLink->clicks_count] : null,
        ];
    }

    /**
     * Résolution d'une annonce immobilière.
     *
     * @return array<string, mixed>
     */
    protected function resolveListing(string $id, ?User $currentUser, ?ShortLink $shortLink): array
    {
        $listing = Listing::withTrashed()->find($id);

        if ($listing === null) {
            throw new ResourceNotFoundException('Annonce immobilière introuvable.', 'LISTING_NOT_FOUND', 'listing', $id);
        }

        if ($listing->trashed()) {
            throw new ResourceGoneException('Cette annonce immobilière a été retirée définitivement.', 'LISTING_GONE', 'listing', $id);
        }

        if ($this->isListingModerated($listing)) {
            throw new ResourceLockedException('Cette annonce est temporairement indisponible suite à une décision de modération.', 'LISTING_LOCKED', 'listing', $id);
        }

        $statusValue = is_object($listing->status) ? $listing->status->value : (string) $listing->status;
        $kindValue = is_object($listing->kind) ? $listing->kind->value : (string) $listing->kind;
        $modeValue = is_object($listing->mode) ? $listing->mode->value : (string) $listing->mode;

        return [
            'is_valid' => true,
            'target_type' => 'listing',
            'target_id' => (string) $listing->id,
            'status' => $statusValue,
            'requires_auth' => false,
            'is_accessible' => in_array($statusValue, ['published', 'reserved', 'taken']),
            'metadata' => [
                'title' => $listing->title,
                'city' => $listing->city,
                'neighbourhood' => $listing->neighbourhood,
                'indicative_location' => $listing->location, // Repère public sans adresse exacte
                'price' => (float) $listing->price,
                'currency' => $listing->currency,
                'kind' => $kindValue,
                'mode' => $modeValue,
                'is_taken' => $statusValue === 'taken',
            ],
            'short_link' => $shortLink ? ['code' => $shortLink->code, 'clicks_count' => $shortLink->clicks_count] : null,
        ];
    }

    /**
     * Résolution d'un profil utilisateur.
     *
     * @return array<string, mixed>
     */
    protected function resolveUser(string $identifier, ?User $currentUser, ?ShortLink $shortLink): array
    {
        $query = User::withTrashed();

        if (is_numeric($identifier)) {
            $query->where('id', $identifier);
        } else {
            $query->where('handle', ltrim($identifier, '@'));
        }

        $user = $query->first();

        if ($user === null) {
            throw new ResourceNotFoundException('Profil utilisateur introuvable.', 'USER_NOT_FOUND', 'user', $identifier);
        }

        if ($user->trashed()) {
            throw new ResourceGoneException('Ce compte utilisateur a été supprimé.', 'USER_GONE', 'user', $identifier);
        }

        if ($user->status === 'suspended' || ($user->locked_until && $user->locked_until->isFuture())) {
            throw new ResourceLockedException('Ce compte utilisateur est suspendu ou verrouillé par la modération.', 'USER_LOCKED', 'user', $identifier);
        }

        if ($currentUser !== null) {
            if ($currentUser->hasBlocked($user->id) || $currentUser->isBlockedBy($user->id)) {
                throw new ResourceNotFoundException('Profil utilisateur introuvable ou inaccessible.', 'USER_NOT_FOUND', 'user', $identifier);
            }
        }

        $requiresAuth = (bool) $user->is_private;
        $isAccessible = ! $requiresAuth || ($currentUser !== null && ($currentUser->id === $user->id || $currentUser->isFollowing($user)));

        return [
            'is_valid' => true,
            'target_type' => 'user',
            'target_id' => (string) $user->id,
            'status' => 'available',
            'requires_auth' => $requiresAuth,
            'is_accessible' => $isAccessible,
            'metadata' => [
                'name' => $user->name,
                'handle' => $user->handle,
                'avatar_url' => $user->avatar_url,
                'account_type' => is_object($user->account_type) ? $user->account_type->value : (string) $user->account_type,
                'is_verified' => (bool) $user->is_verified,
                'is_private' => (bool) $user->is_private,
            ],
            'short_link' => $shortLink ? ['code' => $shortLink->code, 'clicks_count' => $shortLink->clicks_count] : null,
        ];
    }

    /**
     * Résolution d'une Page Professionnelle.
     *
     * @return array<string, mixed>
     */
    protected function resolvePage(string $identifier, ?User $currentUser, ?ShortLink $shortLink): array
    {
        $query = Page::withTrashed();

        if (is_numeric($identifier)) {
            $query->where('id', $identifier);
        } else {
            $query->where('handle', ltrim($identifier, '@'));
        }

        $page = $query->first();

        if ($page === null) {
            throw new ResourceNotFoundException('Page Professionnelle introuvable.', 'PAGE_NOT_FOUND', 'page', $identifier);
        }

        if ($page->trashed()) {
            throw new ResourceGoneException('Cette Page Professionnelle a été supprimée définitivement.', 'PAGE_GONE', 'page', $identifier);
        }

        if ($page->status === 'suspended' || (is_object($page->status) && $page->status->value === 'suspended')) {
            throw new ResourceLockedException('Cette Page Professionnelle est suspendue par la modération.', 'PAGE_LOCKED', 'page', $identifier);
        }

        return [
            'is_valid' => true,
            'target_type' => 'page',
            'target_id' => (string) $page->id,
            'status' => is_object($page->status) ? $page->status->value : (string) $page->status,
            'requires_auth' => false,
            'is_accessible' => true,
            'metadata' => [
                'name' => $page->name,
                'handle' => $page->handle,
                'logo_url' => $page->logo_url,
                'sector' => is_object($page->sector) ? $page->sector->value : (string) $page->sector,
                'certification_status' => is_object($page->certification_status) ? $page->certification_status->value : (string) $page->certification_status,
                'city' => $page->city,
                'neighbourhood' => $page->neighbourhood,
            ],
            'short_link' => $shortLink ? ['code' => $shortLink->code, 'clicks_count' => $shortLink->clicks_count] : null,
        ];
    }

    /**
     * Vérifie si un Post est gelé par la modération.
     */
    protected function isPostModerated(Post $post): bool
    {
        if ($post->user && $post->user->status === 'suspended') {
            return true;
        }

        return Report::query()
            ->where('reportable_type', 'post')
            ->where('reportable_id', (string) $post->id)
            ->where('status', 'resolved')
            ->where('action_taken', 'blocked')
            ->exists();
    }

    /**
     * Vérifie si un Reel est gelé par la modération.
     */
    protected function isReelModerated(Reel $reel): bool
    {
        if ($reel->user && $reel->user->status === 'suspended') {
            return true;
        }

        return Report::query()
            ->where('reportable_type', 'reel')
            ->where('reportable_id', (string) $reel->id)
            ->where('status', 'resolved')
            ->where('action_taken', 'blocked')
            ->exists();
    }

    /**
     * Vérifie si une annonce immobilière est gelée par la modération.
     */
    protected function isListingModerated(Listing $listing): bool
    {
        if ($listing->status === 'rejected') {
            return true;
        }

        if ($listing->owner && $listing->owner->status === 'suspended') {
            return true;
        }

        return Report::query()
            ->where('reportable_type', 'listing')
            ->where('reportable_id', (string) $listing->id)
            ->where('status', 'resolved')
            ->where('action_taken', 'blocked')
            ->exists();
    }
}
