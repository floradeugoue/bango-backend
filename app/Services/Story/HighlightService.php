<?php

namespace App\Services\Story;

use App\Models\Story\Story;
use App\Models\Story\StoryHighlight;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HighlightService
{
    /**
     * Récupère les collections À la une visibles pour un utilisateur.
     *
     * @return Collection<int, StoryHighlight>
     */
    public function getHighlights(User $viewer, ?int $authorId = null): Collection
    {
        $targetUserId = $authorId ?? $viewer->id;
        $targetUser = User::find($targetUserId);

        if (! $targetUser) {
            return new Collection;
        }

        // Vérification des blocages
        if ($viewer->id !== $targetUser->id && ($viewer->hasBlocked($targetUser) || $viewer->isBlockedBy($targetUser))) {
            return new Collection;
        }

        $highlights = StoryHighlight::where('user_id', $targetUserId)
            ->with(['coverStory', 'stories.slides.stickers', 'user'])
            ->orderBy('order', 'asc')
            ->get();

        // Règle de sécurité stricte : Filtrer les Stories selon l'audience originale
        // Une Story en À la une ne devient JAMAIS silencieusement publique si son audience était restreinte.
        return $highlights->map(function (StoryHighlight $highlight) use ($viewer) {
            $filteredStories = $highlight->stories->filter(function (Story $story) use ($viewer) {
                return $story->isAccessibleBy($viewer);
            });
            $highlight->setRelation('stories', $filteredStories->values());

            return $highlight;
        })->filter(function (StoryHighlight $highlight) use ($viewer) {
            // Le propriétaire voit toujours toutes ses collections ; un tiers ne voit que celles ayant au moins un contenu accessible
            return $highlight->user_id === $viewer->id || $highlight->stories->isNotEmpty();
        })->values();
    }

    /**
     * Crée une collection À la une pour l'utilisateur connecté.
     *
     * @param  array{
     *     title: string,
     *     story_ids: array<string>,
     *     cover_story_id?: ?string,
     *     cover_media_url?: ?string
     * }  $data
     */
    public function createHighlight(User $user, array $data): StoryHighlight
    {
        $title = trim($data['title'] ?? '');
        if (empty($title) || mb_strlen($title) > 20) {
            throw ValidationException::withMessages([
                'title' => ['Le titre de la collection est requis et ne peut pas dépasser 20 caractères.'],
            ]);
        }

        $storyIds = $data['story_ids'] ?? [];
        if (empty($storyIds)) {
            throw ValidationException::withMessages([
                'story_ids' => ['Au moins une Story doit être sélectionnée pour créer une collection À la une.'],
            ]);
        }

        // Vérifier que toutes les stories sélectionnées appartiennent bien à l'utilisateur
        $validStories = Story::where('user_id', $user->id)
            ->whereIn('id', $storyIds)
            ->get();

        if ($validStories->count() !== count($storyIds)) {
            throw ValidationException::withMessages([
                'story_ids' => ['Une ou plusieurs Stories sélectionnées n\'appartiennent pas à votre compte.'],
            ]);
        }

        $coverStoryId = $data['cover_story_id'] ?? null;
        if ($coverStoryId && ! $validStories->contains('id', $coverStoryId)) {
            throw ValidationException::withMessages([
                'cover_story_id' => ['La Story de couverture doit faire partie des Stories de la collection.'],
            ]);
        }

        return DB::transaction(function () use ($user, $title, $coverStoryId, $data, $storyIds) {
            $maxOrder = (int) StoryHighlight::where('user_id', $user->id)->max('order');

            $highlight = StoryHighlight::create([
                'user_id' => $user->id,
                'title' => $title,
                'cover_story_id' => $coverStoryId ?? $storyIds[0],
                'cover_media_url' => $data['cover_media_url'] ?? null,
                'order' => $maxOrder + 1,
            ]);

            $syncData = [];
            foreach ($storyIds as $index => $sId) {
                $syncData[$sId] = ['order' => $index];
            }
            $highlight->stories()->sync($syncData);

            return $highlight->load(['coverStory', 'stories.slides']);
        });
    }

    /**
     * Consulte les détails d'une collection spécifique.
     */
    public function getHighlight(string $id, User $viewer): ?StoryHighlight
    {
        $highlight = StoryHighlight::with(['coverStory', 'stories.slides.stickers', 'user'])->find($id);

        if (! $highlight) {
            return null;
        }

        if ($viewer->id !== $highlight->user_id && ($viewer->hasBlocked($highlight->user_id) || $viewer->isBlockedBy($highlight->user_id))) {
            return null;
        }

        $filteredStories = $highlight->stories->filter(function (Story $story) use ($viewer) {
            return $story->isAccessibleBy($viewer);
        });

        if ($highlight->user_id !== $viewer->id && $filteredStories->isEmpty()) {
            return null;
        }

        $highlight->setRelation('stories', $filteredStories->values());

        return $highlight;
    }

    /**
     * Supprime une collection À la une sans supprimer les Stories sous-jacentes.
     */
    public function deleteHighlight(string $id, User $user): bool
    {
        $highlight = StoryHighlight::where('user_id', $user->id)->find($id);

        if (! $highlight) {
            throw ValidationException::withMessages([
                'highlight' => ['Collection introuvable ou non autorisée.'],
            ]);
        }

        // Détachement des stories et suppression du conteneur de collection
        return DB::transaction(function () use ($highlight) {
            $highlight->stories()->detach();

            return $highlight->delete();
        });
    }
}
