<?php

namespace App\Enums\Delegation;

enum DelegationCapability: string
{
    case Visits = 'visits';
    case Reply = 'reply';
    case Edit = 'edit';
}
