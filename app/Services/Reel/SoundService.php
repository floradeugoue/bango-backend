<?php

namespace App\Services\Reel;

use App\Enums\Reel\ReelStatus;
use App\Models\Reel\Reel;
use App\Models\Reel\Sound;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class SoundService
{
    /**
     * Liste les sons du catalogue avec filtres (genre, recherche).
     */
    public function listSounds(?string $genre = null, ?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        return Sound::query()
            ->availableForUsers()
            ->when($genre, fn (Builder $q) => $q->where('genre', $genre))
            ->when($search, function (Builder $q) use ($search) {
                $term = strtolower(trim($search));
                $q->where(function (Builder $sub) use ($term) {
                    $sub->whereRaw('LOWER(title) LIKE ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(artist) LIKE ?', ["%{$term}%"]);
                });
            })
            ->orderByRaw('(base_uses + uses_count) DESC')
            ->paginate($perPage);
    }

    /**
     * Récupère un son par son identifiant (catalogue ou son original 'orig:<reel_id>').
     */
    public function getSound(string $id, ?User $user = null): ?Sound
    {
        // 1. Recherche dans la base
        $sound = Sound::with('user')->find($id);
        if ($sound) {
            return $sound;
        }

        // 2. Si c'est un son original 'orig:<reel_id>' qui n'a pas encore de fiche Sound en base
        if (str_starts_with($id, 'orig:')) {
            $reelId = substr($id, 5);
            $reel = Reel::with('user')->find($reelId);

            if ($reel) {
                return Sound::create([
                    'id' => $id,
                    'title' => 'Son original',
                    'artist' => $reel->user?->display_name ?? $reel->user?->name ?? 'Utilisateur BANGO',
                    'genre' => 'afro',
                    'base_uses' => 0,
                    'uses_count' => 1,
                    'duration_seconds' => $reel->duration ?? 30,
                    'is_original' => true,
                    'original_reel_id' => $reel->id,
                    'user_id' => $reel->user_id,
                    'audio_url' => $reel->video_url,
                ]);
            }
        }

        return null;
    }

    /**
     * Liste les reels utilisant un son donné.
     * La vidéo originale (si son original) est placée en tête, suivie des plus aimés ou plus récents.
     */
    public function getReelsForSound(string $soundId, string $order = 'popular', ?User $user = null, int $perPage = 20): LengthAwarePaginator
    {
        $originReelId = null;
        if (str_starts_with($soundId, 'orig:')) {
            $originReelId = substr($soundId, 5);
        } else {
            $sound = Sound::find($soundId);
            $originReelId = $sound?->original_reel_id;
        }

        $query = Reel::query()
            ->with(['user', 'sound'])
            ->where('status', ReelStatus::Ready)
            ->where(function (Builder $q) use ($soundId, $originReelId) {
                $q->where('sound_id', $soundId);
                if ($originReelId) {
                    $q->orWhere('id', $originReelId);
                }
            });

        // Ordonnancement : la vidéo d'origine en tête, puis selon l'ordre choisi
        if ($originReelId) {
            $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$originReelId]);
        }

        if ($order === 'popular') {
            $query->orderByDesc('likes_count')->orderByDesc('views_count');
        } else {
            $query->orderByDesc('published_at')->orderByDesc('created_at');
        }

        return $query->paginate($perPage);
    }

    /**
     * Bascule l'état de favori d'un son pour un utilisateur.
     *
     * @return array{is_favourite: bool, favourite_ids: array<string>}
     */
    public function toggleFavourite(string $soundId, User $user): array
    {
        $sound = $this->getSound($soundId, $user);
        if (! $sound) {
            throw ValidationException::withMessages([
                'sound_id' => ['Le son spécifié est introuvable.'],
            ]);
        }

        $exists = $user->favouriteSounds()->where('sounds.id', $sound->id)->exists();

        if ($exists) {
            $user->favouriteSounds()->detach($sound->id);
            $isFav = false;
        } else {
            $user->favouriteSounds()->attach($sound->id);
            $isFav = true;
        }

        return [
            'is_favourite' => $isFav,
            'favourite_ids' => $this->getUserFavouriteSoundIds($user),
        ];
    }

    /**
     * Liste des identifiants des sons favoris de l'utilisateur.
     *
     * @return array<string>
     */
    public function getUserFavouriteSoundIds(User $user): array
    {
        return $user->favouriteSounds()->pluck('sounds.id')->toArray();
    }

    /**
     * Verrouille ou déverrouille l'utilisation d'un son original.
     */
    public function setOriginalSoundLocked(string $reelId, bool $locked, User $user): Sound
    {
        $reel = Reel::where('id', $reelId)->where('user_id', $user->id)->first();
        if (! $reel) {
            throw ValidationException::withMessages([
                'reel_id' => ['Reel introuvable ou vous n\'en êtes pas l\'auteur.'],
            ]);
        }

        $soundId = "orig:{$reel->id}";
        $sound = Sound::firstOrCreate(
            ['id' => $soundId],
            [
                'title' => 'Son original',
                'artist' => $user->display_name ?? $user->name,
                'genre' => 'afro',
                'is_original' => true,
                'original_reel_id' => $reel->id,
                'user_id' => $user->id,
            ]
        );

        $sound->update(['is_locked' => $locked]);

        return $sound;
    }
}
