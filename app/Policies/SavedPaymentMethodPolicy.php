<?php

namespace App\Policies;

use App\Models\Payment\SavedPaymentMethod;
use App\Models\User;

class SavedPaymentMethodPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SavedPaymentMethod $method): bool
    {
        return (int) $method->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SavedPaymentMethod $method): bool
    {
        return (int) $method->user_id === (int) $user->id;
    }

    public function delete(User $user, SavedPaymentMethod $method): bool
    {
        return (int) $method->user_id === (int) $user->id;
    }
}
