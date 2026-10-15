<?php

namespace App\Enums\Listing;

enum ListingKind: string
{
    case Studio = 'studio';
    case Flat = 'flat';
    case Villa = 'villa';
    case Room = 'room';
    case Office = 'office';
    case Land = 'land';
}
