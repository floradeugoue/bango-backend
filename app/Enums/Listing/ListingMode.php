<?php

namespace App\Enums\Listing;

enum ListingMode: string
{
    case Rent = 'rent';
    case Sale = 'sale';
    case Flatshare = 'flatshare';
}
