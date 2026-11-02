<?php

namespace Database\Seeders;

use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\VisitSlot;
use App\Services\Visit\VisitSlotService;
use Illuminate\Database\Seeder;

class DuplexLogpomSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::where('email', 'jeanpaul@bango.app')
            ->orWhere('name', 'jeanpaul')
            ->first();

        if (! $owner) {
            $owner = User::firstOrCreate(
                ['email' => 'jeanpaul@bango.app'],
                [
                    'name' => 'Jean-Paul',
                    'handle' => 'jeanpaul',
                    'password' => bcrypt('Password123!'),
                    'badge' => 'landlord',
                    'is_verified' => true,
                ]
            );
        }

        $duplex = Listing::find('01a15f56-3622-7332-884d-5f41428fc6f8');

        if (! $duplex) {
            $duplex = new Listing;
            $duplex->id = '01a15f56-3622-7332-884d-5f41428fc6f8';
            $duplex->title = 'Duplex 4 chambres à Logpom';
            $duplex->description = 'Magnifique duplex 4 chambres situé à Logpom, spacieux et sécurisé.';
            $duplex->mode = ListingMode::Rent;
            $duplex->kind = ListingKind::Villa;
            $duplex->status = ListingStatus::Published;
            $duplex->owner_id = $owner->id;
            $duplex->created_by_id = $owner->id;
            $duplex->price = 350000;
            $duplex->currency = 'XAF';
            $duplex->city = 'Douala';
            $duplex->bedrooms = 4;
            $duplex->showers = 3;
            $duplex->uses_agenda = true;
            $duplex->auto_confirm = true;
            $duplex->published_at = now();
            $duplex->save();
        } else {
            $duplex->status = ListingStatus::Published;
            $duplex->save();
        }

        // Rouvrir tous les créneaux futurs fermés
        VisitSlot::where('listing_id', $duplex->id)
            ->where('start_at', '>', now())
            ->where('status', VisitSlotStatus::Closed->value)
            ->update(['status' => VisitSlotStatus::Open->value]);

        // Régénérer les créneaux par défaut si nécessaire
        app(VisitSlotService::class)->ensureDefaultSlotsExist($duplex, now(), 14);
    }
}
