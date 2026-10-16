<?php

namespace App\Policies;

use App\Models\Delegation\Delegation;
use App\Models\User;

class DelegationPolicy
{
    public function view(User $user, Delegation $delegation): bool
    {
        return $user->isAdmin() || $user->id === $delegation->bailleur_id || $user->id === $delegation->agent_id;
    }

    public function accept(User $user, Delegation $delegation): bool
    {
        return $user->id === $delegation->agent_id;
    }

    public function reject(User $user, Delegation $delegation): bool
    {
        return $user->id === $delegation->agent_id;
    }

    public function delete(User $user, Delegation $delegation): bool
    {
        return $user->isAdmin() || $user->id === $delegation->bailleur_id;
    }
}
