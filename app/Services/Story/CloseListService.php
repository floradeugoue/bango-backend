<?php

namespace App\Services\Story;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseListService
{
    /**
     * Récupère la liste choisie (close friends) d'un utilisateur.
     *
     * @return Collection<int, User>
     */
    public function getCloseList(User $user): Collection
    {
        return $user->closeFriends()
            ->select(['users.id', 'users.name', 'users.handle', 'users.display_name', 'users.avatar_url', 'users.is_verified'])
            ->get();
    }

    /**
     * Remplace la liste choisie de façon validée, dédupliquée et atomique.
     *
     * @param  array<int|string>  $userIds
     * @return Collection<int, User>
     */
    public function replaceCloseList(User $user, array $userIds): Collection
    {
        // 1. Déduplication et cast des identifiants
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        // 2. Exclusion de soi-même
        $cleanIds = array_values(array_filter($cleanIds, fn ($id) => $id !== (int) $user->id));

        // 3. Vérification de l'existence des utilisateurs en base
        if (! empty($cleanIds)) {
            $existingCount = User::whereIn('id', $cleanIds)->count();
            if ($existingCount !== count($cleanIds)) {
                throw ValidationException::withMessages([
                    'user_ids' => ['Un ou plusieurs utilisateurs sélectionnés sont introuvables.'],
                ]);
            }

            // 4. Exclusion des comptes bloqués ou bloquants
            $blockedByMe = DB::table('user_blocks')
                ->where('blocker_id', $user->id)
                ->whereIn('blocked_id', $cleanIds)
                ->pluck('blocked_id')
                ->toArray();

            $blockedMe = DB::table('user_blocks')
                ->where('blocked_id', $user->id)
                ->whereIn('blocker_id', $cleanIds)
                ->pluck('blocker_id')
                ->toArray();

            $blockedIds = array_unique(array_merge($blockedByMe, $blockedMe));

            if (! empty($blockedIds)) {
                throw ValidationException::withMessages([
                    'user_ids' => ['Impossible d\'ajouter des utilisateurs bloqués ou avec lesquels une restriction existe.'],
                ]);
            }
        }

        // 5. Remplacement atomique de la liste
        DB::transaction(function () use ($user, $cleanIds) {
            $user->closeFriends()->sync($cleanIds);
        });

        return $this->getCloseList($user);
    }
}
