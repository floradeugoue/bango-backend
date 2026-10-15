<?php

namespace App\Enums\Listing;

enum ListingPhotoRoom: string
{
    case Living = 'living';
    case Bedroom = 'bedroom';
    case Kitchen = 'kitchen';
    case Shower = 'shower';
    case Outside = 'outside';
}
