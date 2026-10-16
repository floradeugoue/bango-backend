<?php

namespace App\Enums\Kyc;

enum KycStatus: string
{
    case Pending = 'pending';
    case SupplementRequested = 'supplement_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
