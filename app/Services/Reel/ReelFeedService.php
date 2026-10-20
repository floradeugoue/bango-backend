<?php

namespace App\Services\Reel;

use App\Enums\Reel\ReelStatus;
use App\Models\Reel\Reel;
use App\Models\User;
use App\Services\Boost\AdDeliveryService;
use App\Services\Media\MediaStorageService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ReelFeedService
{
    public function __construct(protected MediaStorageService $storageService) {}

    /**
     * Récupère le fil vertical des Reels selon le filtre et l'audience.
     */
    public function getFeed(
        ?User $user = null,
        string $scope = 'for_you',
        ?string $topic = null,
        int $perPage = 10,
        ?int $authorId = null
    ): LengthAwarePaginator {
        $query = Reel::query()
            ->with([
                'user',
                'sound',
            ])
            ->where('status', ReelStatus::Ready);

        // Filtrage spécifique par créateur (profil / onglet Reels de l'auteur)
        if ($authorId) {
            $query->where('user_id', $authorId);
        }

        // Si l'utilisateur est connecté, optimiser le chargement des états sociaux et des exclusions
        if ($user) {
            $query->with([
                'reactions' => fn ($q) => $q->where('user_id', $user->id),
                'savedByUsers' => fn ($q) => $q->where('users.id', $user->id),
            ]);

            // Exclure les reels marqués comme "Ça ne m'intéresse pas"
            $query->whereDoesntHave('notInterestedByUsers', function (Builder $q) use ($user) {
                $q->where('users.id', $user->id);
            });

            // Exclure les créateurs bloqués
            $blockedUserIds = $user->blockedUsers()->pluck('blocked_id')
                ->merge($user->blockedByUsers()->pluck('blocker_id'))
                ->unique();

            if ($blockedUserIds->isNotEmpty()) {
                $query->whereNotIn('user_id', $blockedUserIds);
            }
        }

        // Filtre par sujet/thème
        if ($topic) {
            $query->where('topic', $topic);
        }

        // Gestion des portées du feed
        switch ($scope) {
            case 'following':
                if ($user) {
                    $followingIds = $user->following()->pluck('users.id');
                    $query->whereIn('user_id', $followingIds);
                } else {
                    $query->whereRaw('1 = 0');
                }
                $query->orderByDesc('published_at');
                break;

            case 'neighbourhood':
            case 'city':
                if ($user && $user->city) {
                    $query->whereHas('user', function (Builder $q) use ($user) {
                        $q->where('city', $user->city);
                    });
                }
                $query->orderByDesc('published_at');
                break;

            case 'for_you':
            default:
                // Algorithme "Pour toi" BANGO : engagement + popularité + fraîcheur
                $query->orderByDesc('published_at')
                    ->orderByDesc('likes_count')
                    ->orderByDesc('views_count');
                break;
        }

        $paginator = $query->paginate($perPage);

        // Injection publicitaire BO (feed vertical Pour toi ou ville, exclusion du scope following)
        if ($scope !== 'following') {
            $paginator = app(AdDeliveryService::class)->injectIntoReelFeed($paginator, $user, ['scope' => $scope, 'topic' => $topic]);
        }

        return $paginator;
    }

    /**
     * Récupère un Reel individuel avec vérification de visibilité.
     */
    public function getReel(string $id, ?User $user = null): ?Reel
    {
        $query = Reel::query()
            ->with(['user', 'sound']);

        if ($user) {
            $query->with([
                'reactions' => fn ($q) => $q->where('user_id', $user->id),
                'savedByUsers' => fn ($q) => $q->where('users.id', $user->id),
            ]);
        }

        $reel = $query->find($id);

        if (! $reel) {
            return null;
        }

        // Si le reel est un brouillon ou en échec, seul l'auteur peut le voir
        if ($reel->status !== ReelStatus::Ready) {
            if (! $user || $reel->user_id !== $user->id) {
                return null;
            }
        }

        // Si l'utilisateur a bloqué le créateur ou est bloqué par lui, le Reel est inaccessible
        if ($user && $reel->user_id !== $user->id) {
            $isBlocked = $user->blockedUsers()->where('blocked_id', $reel->user_id)->exists()
                || $user->blockedByUsers()->where('blocker_id', $reel->user_id)->exists();

            if ($isBlocked) {
                return null;
            }
        }

        return $reel;
    }

    /**
     * Supprime un Reel (réservé à l'auteur).
     */
    public function deleteReel(Reel $reel, User $user): bool
    {
        if ($reel->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'reel' => ['Vous n\'êtes pas autorisé à supprimer ce Reel.'],
            ]);
        }

        return (bool) $reel->delete();
    }
}
