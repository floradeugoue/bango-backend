<?php

namespace App\Events\Listing;

use App\Models\Listing\Listing;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ListingPublished
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Listing $listing
    ) {}
}
