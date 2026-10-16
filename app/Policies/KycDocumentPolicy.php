<?php

namespace App\Policies;

use App\Models\Kyc\KycDocument;
use App\Models\User;

class KycDocumentPolicy
{
    public function view(User $user, KycDocument $document): bool
    {
        return $user->isAdmin() || $user->id === $document->user_id;
    }

    public function download(User $user, KycDocument $document): bool
    {
        return $user->isAdmin() || $user->id === $document->user_id;
    }
}
