<?php

namespace App\Services\Reel;

use App\Enums\Reel\ReelStatus;
use App\Models\Reel\Reel;
use App\Models\Reel\Sound;
use App\Models\User;
use App\Notifications\Reel\ReelAudioMutedCopyrightNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
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
            ->withCount('reels')
            ->when($genre, fn (Builder $q) => $q->where('genre', $genre))
            ->when($search, function (Builder $q) use ($search) {
                $term = strtolower(trim($search));
                $q->where(function (Builder $sub) use ($term) {
                    $sub->whereRaw('LOWER(title) LIKE ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(artist) LIKE ?', ["%{$term}%"]);
                });
            })
            ->orderByDesc('reels_count')
            ->paginate($perPage);
    }

    /**
     * Récupère un son par son identifiant (catalogue ou son original 'orig:<reel_id>').
     */
    public function getSound(string $id, ?User $user = null): ?Sound
    {
        // 1. Recherche dans la base
        $sound = Sound::with('user')->withCount('reels')->find($id);
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

    /**
     * Coupe le son original partout lorsqu'un reel ou son audio fait l'objet d'un retrait pour droits d'auteur (copyright).
     *
     * 1. Marque le sound 'orig:<reel_id>' comme retiré (is_removed = true, is_published = false, rights_status = 'disputed').
     * 2. Coupe l'audio de tous les reels dérivés (sound_id = 'orig:<reel_id>') en positionnant is_muted = true (vidéos conservées).
     * 3. Notifie les auteurs des reels dérivés impactés.
     *
     * @return array{sound: Sound, muted_reels_count: int, affected_users_count: int}
     */
    public function handleOriginalSoundCopyrightInfringement(Reel|string $reel, ?User $operator = null, ?string $reason = null): array
    {
        $reelId = $reel instanceof Reel ? $reel->id : (string) $reel;
        $reelObj = $reel instanceof Reel ? $reel : Reel::withTrashed()->with('user')->find($reelId);

        $soundId = "orig:{$reelId}";

        $sound = Sound::firstOrCreate(
            ['id' => $soundId],
            [
                'title' => 'Son original',
                'artist' => $reelObj?->user?->display_name ?? $reelObj?->user?->name ?? 'Utilisateur BANGO',
                'genre' => 'afro',
                'is_original' => true,
                'original_reel_id' => $reelId,
                'user_id' => $reelObj?->user_id,
                'audio_url' => $reelObj?->video_url,
            ]
        );

        $sound->update([
            'is_removed' => true,
            'is_published' => false,
            'is_locked' => true,
            'rights_status' => 'disputed',
        ]);

        $affectedReels = Reel::where('sound_id', $soundId)
            ->where('id', '!=', $reelId)
            ->with('user')
            ->get();

        $mutedCount = 0;
        $notifiedUsers = [];

        foreach ($affectedReels as $affectedReel) {
            $affectedReel->is_muted = true;
            $affectedReel->save();
            $mutedCount++;

            $author = $affectedReel->user;
            if ($author) {
                try {
                    $author->notify(new ReelAudioMutedCopyrightNotification($affectedReel, $sound, $reason));
                    if (! in_array($author->id, $notifiedUsers, true)) {
                        $notifiedUsers[] = $author->id;
                    }
                } catch (\Throwable $e) {
                    Log::warning("Échec envoi notification coupure son original reel {$affectedReel->id} : ".$e->getMessage());
                }
            }
        }

        Log::info("Son original {$soundId} retiré pour droits d'auteur : {$mutedCount} reel(s) dérivé(s) coupé(s), ".count($notifiedUsers).' auteur(s) notifié(s).');

        return [
            'sound' => $sound,
            'muted_reels_count' => $mutedCount,
            'affected_users_count' => count($notifiedUsers),
        ];
    }
}
