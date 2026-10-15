<?php

namespace App\Services\Admin;

use App\Models\Reel\Sound;
use App\Services\Media\MediaStorageService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminSoundService
{
    public function __construct(
        protected MediaStorageService $storageService
    ) {}

    /**
     * Liste complète des sons pour le back-office avec filtres avancés.
     *
     * @param  array{
     *     search?: ?string,
     *     genre?: ?string,
     *     rights_status?: ?string,
     *     is_published?: ?bool,
     *     is_removed?: ?bool,
     *     is_original?: ?bool,
     *     sort_by?: ?string
     * }  $filters
     */
    public function listSounds(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return Sound::query()
            ->when(! empty($filters['search']), function (Builder $q) use ($filters) {
                $term = strtolower(trim((string) $filters['search']));
                $q->where(function (Builder $sub) use ($term) {
                    $sub->whereRaw('LOWER(title) LIKE ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(artist) LIKE ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(provenance) LIKE ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(license_name) LIKE ?', ["%{$term}%"]);
                });
            })
            ->when(! empty($filters['genre']), fn (Builder $q) => $q->where('genre', $filters['genre']))
            ->when(! empty($filters['rights_status']), fn (Builder $q) => $q->where('rights_status', $filters['rights_status']))
            ->when(isset($filters['is_published']), fn (Builder $q) => $q->where('is_published', (bool) $filters['is_published']))
            ->when(isset($filters['is_removed']), fn (Builder $q) => $q->where('is_removed', (bool) $filters['is_removed']))
            ->when(isset($filters['is_original']), fn (Builder $q) => $q->where('is_original', (bool) $filters['is_original']))
            ->when(($filters['sort_by'] ?? 'latest') === 'popular', function (Builder $q) {
                $q->orderByRaw('(base_uses + uses_count) DESC');
            }, function (Builder $q) {
                $q->orderByDesc('created_at');
            })
            ->paginate($perPage);
    }

    /**
     * Récupère un son spécifique avec ses statistiques et détails complets.
     */
    public function getSound(string $id): Sound
    {
        $sound = Sound::with(['user', 'reels' => fn ($q) => $q->latest()->limit(10)])
            ->withCount('reels')
            ->find($id);

        if (! $sound) {
            throw ValidationException::withMessages([
                'id' => ['Son introuvable dans le catalogue.'],
            ]);
        }

        return $sound;
    }

    /**
     * Importe et enregistre un nouveau son dans le catalogue audio réservé (sounds/catalogue).
     *
     * @param  array<string, mixed>  $data
     */
    public function createSound(array $data, UploadedFile $audioFile): Sound
    {
        // 1. Enregistrement physique dans sounds/catalogue via MediaStorageService
        $stored = $this->storageService->storeAudio($audioFile, null, false);

        // 2. Génération de l'identifiant unique
        $baseSlug = Str::slug($data['title']);
        $id = $baseSlug ?: (string) Str::uuid();
        if (Sound::where('id', $id)->exists()) {
            $id = "{$id}-".strtolower(Str::random(5));
        }

        // 3. Durée estimée ou déclarée
        $duration = ! empty($data['duration_seconds']) ? (int) $data['duration_seconds'] : 30;

        // 4. Droits et publication
        $rightsStatus = $data['rights_status'] ?? 'verified';
        $shouldPublish = filter_var($data['is_published'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Règle d'intégrité : un son ne peut pas être publié si les droits ne sont pas vérifiés
        if ($shouldPublish && $rightsStatus !== 'verified') {
            throw ValidationException::withMessages([
                'is_published' => ['Impossible de publier un son dont les droits et autorisations ne sont pas validés (statut actuel : '.$rightsStatus.').'],
            ]);
        }

        return Sound::create([
            'id' => $id,
            'title' => trim($data['title']),
            'artist' => trim($data['artist']),
            'genre' => $data['genre'] ?? 'afro',
            'audio_url' => $stored['path'],
            'duration_seconds' => $duration,
            'provenance' => $data['provenance'] ?? null,
            'license_type' => $data['license_type'] ?? null,
            'license_name' => $data['license_name'] ?? null,
            'license_url' => $data['license_url'] ?? null,
            'license_terms' => $data['license_terms'] ?? null,
            'usage_restrictions' => $data['usage_restrictions'] ?? null,
            'rights_status' => $rightsStatus,
            'is_published' => $shouldPublish,
            'published_at' => $shouldPublish ? now() : null,
            'base_uses' => isset($data['base_uses']) ? (int) $data['base_uses'] : 0,
            'uses_count' => 0,
            'is_original' => false,
            'is_locked' => false,
            'is_removed' => false,
            'territories' => $data['territories'] ?? null,
        ]);
    }

    /**
     * Met à jour les métadonnées d'un son et optionnellement remplace le fichier audio.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSound(Sound $sound, array $data, ?UploadedFile $newAudioFile = null): Sound
    {
        $updates = [];

        // Champs de métadonnées éditables
        $fields = [
            'title',
            'artist',
            'genre',
            'duration_seconds',
            'provenance',
            'license_type',
            'license_name',
            'license_url',
            'license_terms',
            'usage_restrictions',
            'base_uses',
            'territories',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        // Remplacement du fichier audio si fourni
        if ($newAudioFile) {
            $stored = $this->storageService->storeAudio($newAudioFile, null, false);
            $updates['audio_url'] = $stored['path'];
        }

        // Mise à jour des droits
        if (isset($data['rights_status'])) {
            $updates['rights_status'] = $data['rights_status'];
            // Si les droits sont déclassés, dépublier automatiquement
            if ($data['rights_status'] !== 'verified') {
                $updates['is_published'] = false;
            }
        }

        // Mise à jour de l'état de publication
        if (isset($data['is_published'])) {
            $targetPublish = filter_var($data['is_published'], FILTER_VALIDATE_BOOLEAN);
            $targetRights = $updates['rights_status'] ?? $sound->rights_status;

            if ($targetPublish && $targetRights !== 'verified') {
                throw ValidationException::withMessages([
                    'is_published' => ['Impossible de publier un son dont les autorisations et droits ne sont pas vérifiés.'],
                ]);
            }

            $updates['is_published'] = $targetPublish;
            if ($targetPublish && ! $sound->published_at) {
                $updates['published_at'] = now();
            }
        }

        $sound->update($updates);

        return $sound->fresh();
    }

    /**
     * Publie ou désactive un son dans le catalogue.
     */
    public function setPublication(Sound $sound, bool $publish): Sound
    {
        if ($publish && $sound->rights_status !== 'verified') {
            throw ValidationException::withMessages([
                'is_published' => ['Impossible de publier un son dont les autorisations et droits ne sont pas vérifiés (statut actuel : '.$sound->rights_status.').'],
            ]);
        }

        $sound->update([
            'is_published' => $publish,
            'published_at' => $publish ? ($sound->published_at ?? now()) : $sound->published_at,
            'is_removed' => false,
        ]);

        return $sound;
    }

    /**
     * Met à jour le statut de validation des droits d'un son.
     */
    public function setRightsStatus(Sound $sound, string $status): Sound
    {
        if (! in_array($status, ['pending', 'verified', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'rights_status' => ['Statut de droits invalide. Valeurs acceptées : pending, verified, rejected.'],
            ]);
        }

        $updates = ['rights_status' => $status];
        if ($status !== 'verified') {
            $updates['is_published'] = false;
        }

        $sound->update($updates);

        return $sound;
    }

    /**
     * Retire un son du catalogue public (suppression logique sans perte des Reels existants).
     */
    public function removeFromCatalog(Sound $sound): Sound
    {
        $sound->update([
            'is_removed' => true,
            'is_published' => false,
        ]);

        return $sound;
    }

    /**
     * Restaure un son précédemment retiré du catalogue.
     */
    public function restoreToCatalog(Sound $sound): Sound
    {
        $sound->update([
            'is_removed' => false,
        ]);

        return $sound;
    }

    /**
     * Supprime définitivement un son uniquement s'il n'est utilisé par AUCUN Reel.
     */
    public function deletePermanently(Sound $sound): bool
    {
        $reelsCount = $sound->reels()->count();
        if ($reelsCount > 0) {
            throw ValidationException::withMessages([
                'sound' => ["Ce son est utilisé par {$reelsCount} Reel(s) existant(s) et ne peut pas être supprimé physiquement. Utilisez le retrait du catalogue."],
            ]);
        }

        // Suppression physique du fichier
        if ($sound->audio_url) {
            $this->storageService->delete($sound->audio_url);
        }

        return (bool) $sound->delete();
    }
}
