<?php

namespace Database\Seeders;

use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingPhotoRoom;
use App\Enums\Listing\ListingStatus;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingMedia;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ListingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $landlord = User::firstOrCreate(
            ['email' => 'landlord@bango.app'],
            [
                'name' => 'Aïcha Bâ',
                'handle' => 'aichaba',
                'display_name' => 'Aïcha Bâ',
                'password' => bcrypt('password'),
                'is_verified' => true,
                'badge' => 'landlord',
                'phone' => '+221770000001',
            ]
        );

        $listings = [
            [
                'id' => (string) Str::uuid(),
                'title' => 'Studio meublé Almadies · 32 m²',
                'description' => 'Studio meublé au 2e étage, calme, à 5 min du carrefour des Almadies. Compteur individuel, forage pour l\'eau. Disponible immédiatement.',
                'mode' => ListingMode::Rent,
                'kind' => ListingKind::Studio,
                'status' => ListingStatus::Published,
                'price' => 150000,
                'currency' => 'XOF',
                'period' => 'month',
                'deposit_months' => 2,
                'advance_months' => 1,
                'visit_fee' => 10000,
                'charges' => 10000,
                'bedrooms' => 1,
                'showers' => 1,
                'area' => 32,
                'city' => 'Dakar',
                'neighbourhood' => 'Almadies',
                'location' => 'Almadies, Dakar · près du carrefour Ngor',
                'lat' => 14.7432,
                'lng' => -17.5186,
                'agent_visited' => true,
                'amenities' => ['furnished', 'meter', 'borehole', 'parking'],
                'features' => [
                    ['id' => 'meter', 'label' => 'Compteur individuel'],
                    ['id' => 'water', 'label' => 'Forage · eau permanente'],
                    ['id' => 'parking', 'label' => 'Parking dans la cour'],
                ],
                'attributes' => ['1 chambre', '1 douche', 'Meublé'],
            ],
            [
                'id' => (string) Str::uuid(),
                'title' => 'Appartement F3 standing Ngor · 85 m²',
                'description' => 'Superbe appartement 3 pièces avec vue dégagée sur l\'océan, salon spacieux, cuisine équipée et balcon.',
                'mode' => ListingMode::Rent,
                'kind' => ListingKind::Flat,
                'status' => ListingStatus::Published,
                'price' => 350000,
                'currency' => 'XOF',
                'period' => 'month',
                'deposit_months' => 2,
                'advance_months' => 1,
                'visit_fee' => 15000,
                'charges' => 25000,
                'bedrooms' => 2,
                'showers' => 2,
                'area' => 85,
                'city' => 'Dakar',
                'neighbourhood' => 'Ngor',
                'location' => 'Ngor Virage, Dakar · face mer',
                'lat' => 14.7520,
                'lng' => -17.5140,
                'agent_visited' => true,
                'amenities' => ['furnished', 'parking', 'generator', 'security'],
                'features' => [
                    ['id' => 'parking', 'label' => 'Place de parking sous-sol'],
                    ['id' => 'generator', 'label' => 'Groupe électrogène de secours'],
                ],
                'attributes' => ['2 chambres', '2 douches', 'Meublé'],
            ],
            [
                'id' => (string) Str::uuid(),
                'title' => 'Villa contemporaine Fann Résidence',
                'description' => 'Grande villa de 5 chambres avec jardin arboré et piscine privée dans le quartier résidentiel et sécurisé de Fann.',
                'mode' => ListingMode::Sale,
                'kind' => ListingKind::Villa,
                'status' => ListingStatus::Published,
                'price' => 180000000,
                'currency' => 'XOF',
                'period' => null,
                'deposit_months' => 0,
                'advance_months' => 0,
                'visit_fee' => 25000,
                'charges' => 0,
                'bedrooms' => 5,
                'showers' => 4,
                'area' => 420,
                'city' => 'Dakar',
                'neighbourhood' => 'Fann',
                'location' => 'Fann Résidence · à 2 min de la Corniche',
                'lat' => 14.6931,
                'lng' => -17.4725,
                'agent_visited' => true,
                'amenities' => ['parking', 'pool', 'garden', 'security'],
                'features' => [
                    ['id' => 'pool', 'label' => 'Piscine privée'],
                    ['id' => 'garden', 'label' => 'Jardin arboré'],
                ],
                'attributes' => ['5 chambres', '4 douches', 'Piscine'],
            ],
        ];

        foreach ($listings as $item) {
            $listing = Listing::firstOrCreate(
                ['id' => $item['id']],
                array_merge($item, [
                    'owner_id' => $landlord->id,
                    'created_by_id' => $landlord->id,
                    'published_at' => now()->subDays(2),
                ])
            );

            ListingMedia::firstOrCreate(
                ['listing_id' => $listing->id, 'type' => 'image', 'order' => 0],
                [
                    'id' => (string) Str::uuid(),
                    'path' => "listings/images/{$landlord->id}/seed_photo_1.jpg",
                    'url' => 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=1080',
                    'mime_type' => 'image/jpeg',
                    'file_size' => 1024 * 1024,
                    'width' => 1080,
                    'height' => 720,
                    'room' => ListingPhotoRoom::Living,
                ]
            );

            ListingMedia::firstOrCreate(
                ['listing_id' => $listing->id, 'type' => 'video', 'order' => 0],
                [
                    'id' => (string) Str::uuid(),
                    'path' => "listings/videos/{$landlord->id}/seed_video_1.mp4",
                    'url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',
                    'mime_type' => 'video/mp4',
                    'file_size' => 1024 * 1024 * 15,
                    'width' => 1080,
                    'height' => 1920,
                    'duration' => 65,
                ]
            );
        }
    }
}
