<?php

namespace App\Enums\Kyc;

enum KycDocKind: string
{
    case Front = 'front';
    case Back = 'back';
    case Selfie = 'selfie';
    case LivenessFrame = 'liveness_frame';
    case Deed = 'deed';
    case Other = 'other';
}
