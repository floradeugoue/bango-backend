<?php

namespace App\Services\Reel;

use App\Enums\Reel\ReelStatus;
use App\Models\Reel\Reel;
use App\Models\Reel\Sound;
use App\Models\User;
use App\Services\Media\MediaStorageService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReelDraftService
{
    public function __construct(
        protected MediaStorageService $storageService
    ) {}

    /**
     * Sauvegarde ou met à jour un brouillon de Reel dans Studio Reel.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(array $data, User $user, ?string $draftId = null): Reel
    {
        $id = $draftId ?? ($data['id'] ?? null);

        if ($id) {
            $reel = Reel::where('id', $id)->first();
            if ($reel) {
                // Contrôle strict de propriété côté serveur
                if ($reel->user_id !== $user->id) {
                    throw ValidationException::withMessages([
                        'draft' => ['Vous n\'êtes pas autorisé à modifier ce brouillon.'],
                    ]);
                }

                // Un Reel déjà publié ou en traitement ne peut pas être rétrogradé en brouillon
                if ($reel->status !== ReelStatus::Draft) {
                    throw ValidationException::withMessages([
                        'draft' => ['Ce Reel est déjà publié ou en cours de traitement et ne peut pas être modifié comme un brouillon.'],
                    ]);
                }
            } else {
                $reel = new Reel;
                $reel->id = $id;
                $reel->user_id = $user->id;
            }
        } else {
            $reel = new Reel;
            $reel->id = (string) Str::uuid();
            $reel->user_id = $user->id;
        }

        // Vérification de l'éligibilité du son (verrouillage / disponibilité)
        $soundId = $data['sound_id'] ?? $reel->sound_id;
        if ($soundId) {
            $this->validateSoundEligibility($soundId, $user);
        }

        $composition = $data['composition'] ?? $reel->composition;
        $reel->composition = $composition;

        $videoUrl = $data['video_url'] ?? $reel->video_url;
        if (empty($videoUrl) && ! empty($composition['clips']) && is_array($composition['clips'])) {
            $videoUrl = $composition['clips'][0]['uri'] ?? null;
        }
        $reel->video_url = $videoUrl;

        $thumbnailUrl = $data['thumbnail_url'] ?? $reel->thumbnail_url;
        if (empty($thumbnailUrl) && ! empty($composition['coverUri'])) {
            $thumbnailUrl = $composition['coverUri'];
        }
        $reel->thumbnail_url = $thumbnailUrl;

        $duration = isset($data['duration']) ? (int) $data['duration'] : $reel->duration;
        if (empty($duration) && ! empty($composition['clips']) && is_array($composition['clips'])) {
            $totalMs = 0;
            foreach ($composition['clips'] as $clip) {
                $totalMs += (int) ($clip['durationMs'] ?? 0);
            }
            if ($totalMs > 0) {
                $duration = (int) max(1, round($totalMs / 1000));
            }
        }
        $reel->duration = $duration;

        $reel->status = ReelStatus::Draft;
        $reel->caption = $data['caption'] ?? $reel->caption;
        $reel->topic = $data['topic'] ?? ($reel->topic ?: 'neighbourhood');
        $reel->sound_id = $soundId;
        $reel->allow_download = isset($data['allow_download']) ? (bool) $data['allow_download'] : ($reel->allow_download ?? true);
        $reel->comments_closed = isset($data['comments_closed']) ? (bool) $data['comments_closed'] : ($reel->comments_closed ?? false);
        $reel->subtitles = $data['subtitles'] ?? $reel->subtitles;
        $reel->subtitles_locale = $data['subtitles_locale'] ?? ($reel->subtitles_locale ?: 'fr');
        $reel->listing_id = $data['listing_id'] ?? $reel->listing_id;

        $reel->save();

        return $reel;
    }

    /**
     * Liste tous les brouillons actifs d'un utilisateur.
     */
    public function getUserDrafts(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return Reel::query()
            ->where('user_id', $user->id)
            ->where('status', ReelStatus::Draft)
            ->orderByDesc('updated_at')
            ->paginate($perPage);
    }

    /**
     * Récupère un brouillon spécifique.
     */
    public function getDraft(string $id, User $user): ?Reel
    {
        return Reel::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->where('status', ReelStatus::Draft)
            ->first();
    }

    /**
     * Supprime un brouillon et nettoie ses médias associés non partagés.
     */
    public function deleteDraft(string $id, User $user): bool
    {
        $reel = Reel::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->where('status', ReelStatus::Draft)
            ->first();

        if (! $reel) {
            return false;
        }

        // Nettoyer les fichiers de brouillon physiques dans storage
        if ($reel->video_url && str_contains($reel->video_url, '/drafts/')) {
            $this->storageService->delete($reel->video_url);
        }

        if (! empty($reel->composition['clips']) && is_array($reel->composition['clips'])) {
            foreach ($reel->composition['clips'] as $clip) {
                if (! empty($clip['uri']) && str_contains($clip['uri'], '/drafts/')) {
                    $this->storageService->delete($clip['uri']);
                }
            }
        }

        return (bool) $reel->delete();
    }

    /**
     * Valide qu'un son peut être légitimement sélectionné par l'utilisateur.
     */
    public function validateSoundEligibility(string $soundId, User $user): void
    {
        $sound = Sound::find($soundId);

        if (! $sound) {
            // S'il s'agit d'un son original non encore matérialisé 'orig:<reel_id>'
            if (str_starts_with($soundId, 'orig:')) {
                $originReelId = substr($soundId, 5);
                $originReel = Reel::find($originReelId);
                // Si le reel d'origine n'existe pas ou appartient à autrui et n'est pas prêt
                if (! $originReel || ($originReel->user_id !== $user->id && $originReel->status !== ReelStatus::Ready)) {
                    throw ValidationException::withMessages([
                        'sound_id' => ['Le son spécifié est introuvable ou indisponible.'],
                    ]);
                }
            }

            return;
        }

        // 1. Son original verrouillé par un autre créateur
        if ($sound->is_original && $sound->is_locked && $sound->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'sound_id' => ['Ce son original a été verrouillé par son auteur et ne peut pas être réutilisé.'],
            ]);
        }

        // 2. Son catalogue retiré ou non publié
        if (! $sound->is_original && ! $sound->isAvailableForCatalog()) {
            throw ValidationException::withMessages([
                'sound_id' => ['Ce son n\'est plus disponible dans le catalogue BANGO.'],
            ]);
        }
    }
}
