<?php

namespace App\Enums;

enum OnboardingStep: string
{
    case Credentials = 'credentials';
    case Identity = 'identity';
    case Phone = 'phone';
    case Profile = 'profile';
    case Interests = 'interests';
    case Housing = 'housing';
}
