<?php

namespace Database\Seeders;

use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingPhotoRoom;
use App\Enums\Listing\ListingStatus;
use App\Enums\Media\MediaProcessingStatus;
use App\Enums\Message\ConversationType;
use App\Enums\Message\MessageType;
use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageSector;
use App\Enums\Page\PageStatus;
use App\Enums\Square\PostAudience;
use App\Enums\Square\PostMediaKind;
use App\Enums\Story\StoryAudience;
use App\Enums\Story\StoryMediaType;
use App\Enums\Story\StoryStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Auth\Role;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Neighbourhood;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingMedia;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Message\Message;
use App\Models\Page\Page;
use App\Models\Profile\Interest;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\Square\PostLike;
use App\Models\Square\PostMedia;
use App\Models\Story\Story;
use App\Models\Story\StorySlide;
use App\Models\User;
use App\Models\Visit\VisitSlot;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RealContentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('RealContentSeeder est strictement réservé aux environnements local et de test.');

            return;
        }

        $this->command?->info('Création des données réalistes BANGO...');

        $cameroun = Country::where('code', 'CM')->first();
        $douala = City::where('slug', 'douala')->first();
        $yaounde = City::where('slug', 'yaounde')->first();

        // Récupération des quartiers
        $bonapriso = Neighbourhood::where('name', 'Bonapriso')->first();
        $makepe = Neighbourhood::where('name', 'Makepe')->first();
        $bastos = Neighbourhood::where('name', 'Bastos')->first();
        $santaBarbara = Neighbourhood::where('name', 'Santa Barbara')->first();
        $akwa = Neighbourhood::where('name', 'Akwa')->first();
        $kotto = Neighbourhood::where('name', 'Kotto')->first();

        $defaultPassword = Hash::make('Bango2026!');
        $userRole = Role::where('slug', 'user')->first() ?? Role::first();

        // -------------------------------------------------------------
        // 1. UTILISATEURS RÉALISTES
        // -------------------------------------------------------------
        $usersData = [
            [
                'email' => 'samuel.moukoko@bangoapp.com',
                'name' => 'Samuel Moukoko',
                'display_name' => 'Samuel Moukoko',
                'handle' => 'samuel_moukoko',
                'bio' => 'Propriétaire & promoteur immobilier à Douala. Résidences de standing à Bonapriso et Denver.',
                'phone' => '+237699102030',
                'city' => 'Douala',
                'city_id' => $douala?->id,
                'neighbourhood' => 'Bonapriso',
                'neighbourhood_id' => $bonapriso?->id,
                'avatar_url' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=400&auto=format&fit=crop&q=80',
                'badge' => 'landlord',
                'is_verified' => true,
            ],
            [
                'email' => 'suzanne.ngo@bangoapp.com',
                'name' => 'Suzanne Ngo Bitjocka',
                'display_name' => 'Suzanne Ngo',
                'handle' => 'suzanne_ngo',
                'bio' => 'Gestionnaire de villas et duplex de charme à Yaoundé Bastos & Golf. Service soigné et sécurisé.',
                'phone' => '+237677203040',
                'city' => 'Yaoundé',
                'city_id' => $yaounde?->id,
                'neighbourhood' => 'Bastos',
                'neighbourhood_id' => $bastos?->id,
                'avatar_url' => 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=400&auto=format&fit=crop&q=80',
                'badge' => 'landlord',
                'is_verified' => true,
            ],
            [
                'email' => 'contact@horizon-immo.cm',
                'name' => 'Horizon Immobilier Douala',
                'display_name' => 'Horizon Immobilier',
                'handle' => 'horizon_immo',
                'bio' => 'Agence immobilière certifiée. Vente, location et gestion locative premium sur Douala et Kribi.',
                'phone' => '+237670405060',
                'city' => 'Douala',
                'city_id' => $douala?->id,
                'neighbourhood' => 'Akwa',
                'neighbourhood_id' => $akwa?->id,
                'avatar_url' => 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?w=400&auto=format&fit=crop&q=80',
                'badge' => 'agent',
                'is_verified' => true,
                'is_certified' => true,
            ],
            [
                'email' => 'paul.atangana@bangoapp.com',
                'name' => 'Paul Atangana',
                'display_name' => 'Paul Atangana · Agent',
                'handle' => 'paul_atangana',
                'bio' => 'Agent immobilier indépendant sur Yaoundé. Je vous accompagne dans toutes vos visites et démarches.',
                'phone' => '+237671607080',
                'city' => 'Yaoundé',
                'city_id' => $yaounde?->id,
                'neighbourhood' => 'Santa Barbara',
                'neighbourhood_id' => $santaBarbara?->id,
                'avatar_url' => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=400&auto=format&fit=crop&q=80',
                'badge' => 'agent',
                'is_verified' => true,
            ],
            [
                'email' => 'marcelle.kamga@bangoapp.com',
                'name' => 'Marcelle Kamga',
                'display_name' => 'Marcelle Kamga',
                'handle' => 'marcelle_kamga',
                'bio' => 'Chasseuse d\'appartements & consultante immo à Douala. Makepe, Kotto, Bonamoussadi.',
                'phone' => '+237696708090',
                'city' => 'Douala',
                'city_id' => $douala?->id,
                'neighbourhood' => 'Makepe',
                'neighbourhood_id' => $makepe?->id,
                'avatar_url' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=400&auto=format&fit=crop&q=80',
                'badge' => 'agent',
                'is_verified' => true,
            ],
            [
                'email' => 'stephane.deco@bangoapp.com',
                'name' => 'Stéphane Nguemo',
                'display_name' => 'Stéphane · Architecture & Déco',
                'handle' => 'stephane_deco',
                'bio' => 'Architecte d\'intérieur & créateur de contenu. Transformer les espaces camerounais avec style 🌿🇨🇲',
                'phone' => '+237672809010',
                'city' => 'Douala',
                'city_id' => $douala?->id,
                'neighbourhood' => 'Bonapriso',
                'neighbourhood_id' => $bonapriso?->id,
                'avatar_url' => 'https://images.unsplash.com/photo-1522075469751-3a6694fb2f61?w=400&auto=format&fit=crop&q=80',
                'badge' => 'creator',
                'is_verified' => true,
            ],
            [
                'email' => 'carine.mballa@bangoapp.com',
                'name' => 'Carine Mballa',
                'display_name' => 'Carine Mballa',
                'handle' => 'carine_mballa',
                'bio' => 'Ingénieure logiciel à Douala. Passionnée d\'urbanisme, de design et de colocations sympas.',
                'phone' => '+237697901020',
                'city' => 'Douala',
                'city_id' => $douala?->id,
                'neighbourhood' => 'Makepe',
                'neighbourhood_id' => $makepe?->id,
                'avatar_url' => 'https://images.unsplash.com/photo-1517841905240-472988babdf9?w=400&auto=format&fit=crop&q=80',
                'badge' => 'tenant',
                'is_verified' => true,
            ],
            [
                'email' => 'patrick.fopoussi@bangoapp.com',
                'name' => 'Patrick Fopoussi',
                'display_name' => 'Patrick Fopoussi',
                'handle' => 'patrick_fopoussi',
                'bio' => 'Directeur financier. À la recherche de belles propriétés familiales à Yaoundé.',
                'phone' => '+237678012030',
                'city' => 'Yaoundé',
                'city_id' => $yaounde?->id,
                'neighbourhood' => 'Bastos',
                'neighbourhood_id' => $bastos?->id,
                'avatar_url' => 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=400&auto=format&fit=crop&q=80',
                'badge' => 'tenant',
                'is_verified' => true,
            ],
        ];

        $users = [];
        $hasNeighbourhoodVerified = Schema::hasColumn('users', 'neighbourhood_verified_at');

        foreach ($usersData as $u) {
            $baseAttributes = [
                'password' => $defaultPassword,
                'role_id' => $userRole?->id,
                'country_code' => 'CM',
                'country_id' => $cameroun?->id,
                'phone_verified_at' => now(),
                'terms_accepted_at' => now(),
                'accepted_terms_version' => '1.0',
                'last_seen_at' => now(),
            ];

            if ($hasNeighbourhoodVerified) {
                $baseAttributes['neighbourhood_verified_at'] = now();
            }

            $user = User::updateOrCreate(
                ['email' => $u['email']],
                array_merge($u, $baseAttributes)
            );
            $users[$u['handle']] = $user;
        }

        // Lier des intérêts
        $allInterests = Interest::all();
        if ($allInterests->isNotEmpty()) {
            foreach ($users as $user) {
                $user->interests()->sync($allInterests->random(min(3, $allInterests->count()))->pluck('id'));
            }
        }

        // -------------------------------------------------------------
        // 2. PAGES PROFESSIONNELLES D'AGENCE
        // -------------------------------------------------------------
        $horizonPage = Page::updateOrCreate(
            ['handle' => 'horizon_immo_page'],
            [
                'owner_id' => $users['horizon_immo']->id,
                'name' => 'Horizon Immobilier Douala',
                'description' => 'Votre agence de référence pour trouver votre logement idéal à Douala, Yaoundé et Kribi. Visites garanties et baux sécurisés.',
                'sector' => PageSector::RealEstateAgency,
                'email' => 'contact@horizon-immo.cm',
                'phone' => '+237670405060',
                'city' => 'Douala',
                'neighbourhood' => 'Akwa',
                'logo_url' => 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?w=200&auto=format&fit=crop&q=80',
                'cover_url' => 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1200&auto=format&fit=crop&q=80',
                'certification_status' => PageCertificationStatus::Approved,
                'status' => PageStatus::Active,
                'followers_count' => 142,
            ]
        );

        // -------------------------------------------------------------
        // 3. ANNONCES IMMOBILIÈRES (LISTINGS)
        // -------------------------------------------------------------
        $listingsData = [
            [
                'id' => (string) Str::uuid(),
                'owner' => 'samuel_moukoko',
                'title' => 'Appartement de standing 3 chambres meublé · Bonapriso',
                'description' => "Magnifique appartement de 160 m² situé au cœur de Bonapriso dans un immeuble sécurisé avec gardiennage 24/7. Grand salon lumineux ouvrant sur un balcon filant, cuisine américaine entièrement équipée, 3 chambres climatisées avec dressings intégrés. Groupe électrogène automatique et forage d'eau avec système de filtration. Proche des commerces, cliniques et écoles internationales.",
                'mode' => ListingMode::Rent,
                'kind' => ListingKind::Flat,
                'price' => 750000,
                'currency' => 'XAF',
                'period' => 'month',
                'deposit_months' => 2,
                'advance_months' => 1,
                'visit_fee' => 10000,
                'charges' => 25000,
                'bedrooms' => 3,
                'showers' => 2,
                'area' => 160,
                'floor' => 2,
                'city' => 'Douala',
                'neighbourhood' => 'Bonapriso',
                'location' => 'Bonapriso, Douala · Rue des Palmiers',
                'lat' => 4.0285,
                'lng' => 9.6980,
                'agent_visited' => true,
                'amenities' => ['furnished', 'generator', 'borehole', 'security_guard', 'air_conditioning', 'parking', 'balcony'],
                'photos' => [
                    ['url' => 'https://images.unsplash.com/photo-1600210492486-724fe5c67fb0?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Living],
                    ['url' => 'https://images.unsplash.com/photo-1595526114035-0d45ed16cfbf?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Bedroom],
                    ['url' => 'https://images.unsplash.com/photo-1556911220-e15b29be8c8f?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Kitchen],
                    ['url' => 'https://images.unsplash.com/photo-1584622650111-993a426fbf0a?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Shower],
                    ['url' => 'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Outside],
                ],
            ],
            [
                'id' => (string) Str::uuid(),
                'owner' => 'suzanne_ngo',
                'title' => 'Villa contemporaine 5 chambres avec jardin · Bastos',
                'description' => "Superbe villa individuelle située dans le quartier diplomatique de Bastos. Vaste séjour avec belle hauteur sous plafond, salle à manger séparée, cuisine moderne avec îlot central, suite parentale avec dressing et baignoire balnéo. Jardin arboré avec terrasse couverte, guérite pour agent de sécurité, réserve d'eau 5000L et groupe de secours. Idéale pour famille ou cadre d'expatriation.",
                'mode' => ListingMode::Rent,
                'kind' => ListingKind::Villa,
                'price' => 1600000,
                'currency' => 'XAF',
                'period' => 'month',
                'deposit_months' => 3,
                'advance_months' => 3,
                'visit_fee' => 15000,
                'charges' => 0,
                'bedrooms' => 5,
                'showers' => 4,
                'area' => 380,
                'floor' => 1,
                'city' => 'Yaoundé',
                'neighbourhood' => 'Bastos',
                'location' => 'Bastos, Yaoundé · Face Résidence Ambassade',
                'lat' => 3.8820,
                'lng' => 11.5120,
                'agent_visited' => true,
                'amenities' => ['generator', 'borehole', 'security_guard', 'air_conditioning', 'parking', 'balcony'],
                'photos' => [
                    ['url' => 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Outside],
                    ['url' => 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Living],
                    ['url' => 'https://images.unsplash.com/photo-1616594039964-ae9021a400a0?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Bedroom],
                    ['url' => 'https://images.unsplash.com/photo-1556909212-d5b604d0c90d?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Kitchen],
                ],
            ],
            [
                'id' => (string) Str::uuid(),
                'owner' => 'marcelle_kamga',
                'title' => 'Studio meublé moderne et cosy · Makepe Rhônes-Poulenc',
                'description' => 'Studio entièrement meublé et équipé dans un immeuble récent et propre à Makepe. Comprenant un lit double confortable, canapé convertible, Smart TV, wifi fibre optique, kitchenette avec micro-ondes et réfrigérateur. Compteur prépayé Eneo, eau courante permanente. Idéal pour jeune cadre ou séjour professionnel.',
                'mode' => ListingMode::Rent,
                'kind' => ListingKind::Studio,
                'price' => 160000,
                'currency' => 'XAF',
                'period' => 'month',
                'deposit_months' => 2,
                'advance_months' => 1,
                'visit_fee' => 5000,
                'charges' => 10000,
                'bedrooms' => 1,
                'showers' => 1,
                'area' => 35,
                'floor' => 1,
                'city' => 'Douala',
                'neighbourhood' => 'Makepe',
                'location' => 'Makepe, Douala · Entrée Rhône-Poulenc',
                'lat' => 4.0750,
                'lng' => 9.7420,
                'agent_visited' => true,
                'amenities' => ['furnished', 'air_conditioning', 'parking', 'balcony'],
                'photos' => [
                    ['url' => 'https://images.unsplash.com/photo-1522771739844-6a9f6d5f14af?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Living],
                    ['url' => 'https://images.unsplash.com/photo-1598928506311-c55ded91a20c?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Bedroom],
                    ['url' => 'https://images.unsplash.com/photo-1584622781564-1d987f7333c1?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Shower],
                ],
            ],
            [
                'id' => (string) Str::uuid(),
                'owner' => 'paul_atangana',
                'title' => 'Duplex 4 chambres avec terrasse panoramique · Santa Barbara',
                'description' => "Magnifique duplex familial de 240 m² situé à Santa Barbara. Rez-de-chaussée comprenant un grand séjour double, une chambre d'amis avec salle d'eau, cuisine spacieuse avec cellier. À l'étage : 3 grandes chambres dont une suite avec balcon privatif. Vue imprenable sur la ville, accès facile par route bitumée. Parking clôturé 2 véhicules.",
                'mode' => ListingMode::Rent,
                'kind' => ListingKind::Villa,
                'price' => 550000,
                'currency' => 'XAF',
                'period' => 'month',
                'deposit_months' => 2,
                'advance_months' => 2,
                'visit_fee' => 10000,
                'charges' => 0,
                'bedrooms' => 4,
                'showers' => 3,
                'area' => 240,
                'floor' => 1,
                'city' => 'Yaoundé',
                'neighbourhood' => 'Santa Barbara',
                'location' => 'Santa Barbara, Yaoundé · Proche chapelle',
                'lat' => 3.8910,
                'lng' => 11.5230,
                'agent_visited' => true,
                'amenities' => ['generator', 'borehole', 'parking', 'balcony'],
                'photos' => [
                    ['url' => 'https://images.unsplash.com/photo-1613490493576-7fde63acd811?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Outside],
                    ['url' => 'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Living],
                    ['url' => 'https://images.unsplash.com/photo-1617806118233-18e1de247200?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Bedroom],
                ],
            ],
            [
                'id' => (string) Str::uuid(),
                'owner' => 'horizon_immo',
                'title' => 'Plateau de bureaux climatisé 130 m² · Akwa Centre des Affaires',
                'description' => "Espace professionnel idéal pour siège d'entreprise, cabinet d'avocats ou agence de services. Situé sur le Boulevard de la Liberté à Akwa. Composé d'un accueil, 3 bureaux individuels fermés, 1 salle de réunion, 2 toilettes et un coin cuisine. Câblage réseau déjà installé, groupe électrogène de l'immeuble.",
                'mode' => ListingMode::Rent,
                'kind' => ListingKind::Office,
                'price' => 480000,
                'currency' => 'XAF',
                'period' => 'month',
                'deposit_months' => 3,
                'advance_months' => 3,
                'visit_fee' => 10000,
                'charges' => 35000,
                'bedrooms' => 0,
                'showers' => 2,
                'area' => 130,
                'floor' => 3,
                'city' => 'Douala',
                'neighbourhood' => 'Akwa',
                'location' => 'Akwa, Douala · Boulevard de la Liberté',
                'lat' => 4.0530,
                'lng' => 9.7020,
                'agent_visited' => true,
                'amenities' => ['generator', 'security_guard', 'air_conditioning', 'parking'],
                'photos' => [
                    ['url' => 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Living],
                    ['url' => 'https://images.unsplash.com/photo-1497215728101-856f4ea42174?w=1200&auto=format&fit=crop&q=80', 'room' => ListingPhotoRoom::Living],
                ],
            ],
        ];

        $createdListings = [];
        foreach ($listingsData as $lData) {
            $owner = $users[$lData['owner']];
            $listing = Listing::updateOrCreate(
                ['id' => $lData['id']],
                [
                    'reference' => 'BG-'.strtoupper(Str::random(6)),
                    'owner_id' => $owner->id,
                    'created_by_id' => $owner->id,
                    'title' => $lData['title'],
                    'description' => $lData['description'],
                    'mode' => $lData['mode'],
                    'kind' => $lData['kind'],
                    'status' => ListingStatus::Published,
                    'uses_agenda' => true,
                    'auto_confirm' => true,
                    'price' => $lData['price'],
                    'currency' => $lData['currency'],
                    'period' => $lData['period'],
                    'deposit_months' => $lData['deposit_months'],
                    'advance_months' => $lData['advance_months'],
                    'visit_fee' => $lData['visit_fee'],
                    'charges' => $lData['charges'],
                    'bedrooms' => $lData['bedrooms'],
                    'showers' => $lData['showers'],
                    'area' => $lData['area'],
                    'floor' => $lData['floor'],
                    'city' => $lData['city'],
                    'neighbourhood' => $lData['neighbourhood'],
                    'location' => $lData['location'],
                    'lat' => $lData['lat'],
                    'lng' => $lData['lng'],
                    'agent_visited' => $lData['agent_visited'],
                    'amenities' => $lData['amenities'],
                    'views_count' => rand(45, 320),
                    'likes_count' => rand(12, 65),
                    'saves_count' => rand(5, 28),
                    'published_at' => now()->subDays(rand(1, 10)),
                ]
            );

            // Photos du bien
            ListingMedia::where('listing_id', $listing->id)->delete();
            foreach ($lData['photos'] as $idx => $photo) {
                ListingMedia::create([
                    'listing_id' => $listing->id,
                    'type' => 'image',
                    'path' => $photo['url'],
                    'url' => $photo['url'],
                    'thumbnail_url' => $photo['url'],
                    'mime_type' => 'image/jpeg',
                    'order' => $idx + 1,
                    'room' => $photo['room'],
                ]);
            }

            // Créneaux de visites (VisitSlot)
            VisitSlot::where('listing_id', $listing->id)->delete();
            for ($d = 1; $d <= 3; $d++) {
                $start = Carbon::now()->addDays($d)->setTime(10, 0);
                VisitSlot::create([
                    'listing_id' => $listing->id,
                    'owner_id' => $owner->id,
                    'start_at' => $start,
                    'end_at' => (clone $start)->addHours(2),
                    'capacity' => 4,
                    'booked_count' => rand(0, 1),
                    'status' => VisitSlotStatus::Open,
                ]);

                $afternoon = Carbon::now()->addDays($d)->setTime(15, 0);
                VisitSlot::create([
                    'listing_id' => $listing->id,
                    'owner_id' => $owner->id,
                    'start_at' => $afternoon,
                    'end_at' => (clone $afternoon)->addHours(2),
                    'capacity' => 4,
                    'booked_count' => 0,
                    'status' => VisitSlotStatus::Open,
                ]);
            }

            $createdListings[] = $listing;
        }

        // -------------------------------------------------------------
        // 4. LE SQUARE (FIL D'ACTUALITÉ & POSTS SOCIAUX)
        // -------------------------------------------------------------
        $postsData = [
            [
                'author' => 'stephane_deco',
                'body' => "💡 Conseil aménagement du jour :\nPour garder votre intérieur frais à Douala pendant les mois chauds, privilégiez les rideaux en lin clair et misez sur la ventilation traversante plutôt que de laisser la clim tourner 24h/24. Voici un petit salon qu'on vient d'aménager à Bonapriso ! Vous aimez le rendu ? 🌿✨\n\n#DesignInterieur #DoualaImmo #BangoSquare #CamerounDeco",
                'media' => 'https://images.unsplash.com/photo-1600210492486-724fe5c67fb0?w=1080&auto=format&fit=crop&q=80',
                'city' => 'Douala',
                'neighbourhood' => 'Bonapriso',
                'likes' => 38,
            ],
            [
                'author' => 'samuel_moukoko',
                'body' => "Visite de chantier terminée ce matin à Bonapriso ! Les travaux de rénovation de notre nouvelle résidence de standing touchent à leur fin. 4 appartements 3 chambres avec forage et groupe électrogène seront livrés d'ici deux semaines. Les réservations de visites sont déjà ouvertes sur BANGO 🚀🇨🇲\n\n#ImmobilierCameroun #Bonapriso #LogementDouala #Bango",
                'media' => 'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?w=1080&auto=format&fit=crop&q=80',
                'city' => 'Douala',
                'neighbourhood' => 'Bonapriso',
                'likes' => 45,
            ],
            [
                'author' => 'suzanne_ngo',
                'body' => "Le quartier Bastos sous le soleil de l'après-midi ☀️ Pourquoi ce secteur reste-t-il le plus recherché de Yaoundé ?\n1. La sécurité permanente (secteur diplomatique)\n2. La tranquillité et la verdure\n3. La proximité de tous les commerces et écoles de renom.\n\nUne nouvelle villa vient d'être mise en ligne sur mon profil !",
                'media' => 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1080&auto=format&fit=crop&q=80',
                'city' => 'Yaoundé',
                'neighbourhood' => 'Bastos',
                'likes' => 52,
            ],
            [
                'author' => 'marcelle_kamga',
                'body' => "Conseil locataire 📝 : Avant de verser une avance sur un logement à Douala, vérifiez TOUJOURS :\n- La pression de l'eau aux heures de pointe\n- La présence d'un compteur individuel Eneo\n- L'état de l'accès pendant la saison des pluies\n\nTous nos biens certifiés sur BANGO ont été visités et validés par notre équipe ! 🔑✅",
                'media' => 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?w=1080&auto=format&fit=crop&q=80',
                'city' => 'Douala',
                'neighbourhood' => 'Makepe',
                'likes' => 61,
            ],
        ];

        foreach ($postsData as $pData) {
            $author = $users[$pData['author']];
            $post = Post::create([
                'user_id' => $author->id,
                'body' => $pData['body'],
                'language' => 'fr',
                'audience' => PostAudience::Public,
                'place_city' => $pData['city'],
                'place_neighbourhood' => $pData['neighbourhood'],
                'place_label' => "{$pData['neighbourhood']}, {$pData['city']}",
                'likes_count' => $pData['likes'],
                'comments_count' => 3,
                'shares_count' => 5,
                'views_count' => $pData['likes'] * 4,
                'created_at' => now()->subHours(rand(2, 48)),
            ]);

            PostMedia::create([
                'post_id' => $post->id,
                'media_url' => $pData['media'],
                'poster_url' => $pData['media'],
                'media_type' => PostMediaKind::Photo,
                'mime_type' => 'image/jpeg',
                'status' => MediaProcessingStatus::Completed,
                'display_order' => 1,
            ]);

            // Commentaires
            PostComment::create([
                'post_id' => $post->id,
                'user_id' => $users['carine_mballa']->id,
                'body' => 'Superbe publication ! Totalement d\'accord avec ces recommandations.',
                'created_at' => now()->subHours(1),
            ]);

            PostComment::create([
                'post_id' => $post->id,
                'user_id' => $users['patrick_fopoussi']->id,
                'body' => 'Très intéressant, le cadre a l\'air très soigné.',
                'created_at' => now()->subMinutes(30),
            ]);

            // Likes
            PostLike::firstOrCreate(['post_id' => $post->id, 'user_id' => $users['carine_mballa']->id]);
            PostLike::firstOrCreate(['post_id' => $post->id, 'user_id' => $users['patrick_fopoussi']->id]);
            PostLike::firstOrCreate(['post_id' => $post->id, 'user_id' => $users['paul_atangana']->id]);
        }

        // -------------------------------------------------------------
        // 5. STORIES ACTIVES (24H)
        // -------------------------------------------------------------
        $storiesData = [
            [
                'author' => 'horizon_immo',
                'caption' => 'Visite express d\'un duplex à Bonapriso 🔑',
                'image' => 'https://images.unsplash.com/photo-1600210492486-724fe5c67fb0?w=1080&auto=format&fit=crop&q=80',
            ],
            [
                'author' => 'suzanne_ngo',
                'caption' => 'Ambiance du soir à Bastos ✨',
                'image' => 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1080&auto=format&fit=crop&q=80',
            ],
            [
                'author' => 'stephane_deco',
                'caption' => 'Nouveau projet déco livré ce matin !',
                'image' => 'https://images.unsplash.com/photo-1595526114035-0d45ed16cfbf?w=1080&auto=format&fit=crop&q=80',
            ],
        ];

        foreach ($storiesData as $sData) {
            $author = $users[$sData['author']];
            $story = Story::create([
                'user_id' => $author->id,
                'audience' => StoryAudience::Everyone,
                'allow_replies' => true,
                'status' => StoryStatus::Active,
                'published_at' => now()->subHours(rand(1, 6)),
                'expires_at' => now()->addHours(18),
                'views_count' => rand(25, 90),
                'likes_count' => rand(8, 30),
            ]);

            StorySlide::create([
                'story_id' => $story->id,
                'media_type' => StoryMediaType::Image,
                'media_path' => $sData['image'],
                'media_url' => $sData['image'],
                'thumbnail_path' => $sData['image'],
                'thumbnail_url' => $sData['image'],
                'caption' => $sData['caption'],
                'duration' => 6,
                'order' => 1,
            ]);
        }

        // -------------------------------------------------------------
        // 6. MESSAGES & CONVERSATIONS DIRECTES
        // -------------------------------------------------------------
        $firstListing = $createdListings[0];
        $conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'type' => ConversationType::Direct,
            'listing_id' => $firstListing->id,
            'created_by_id' => $users['carine_mballa']->id,
            'last_message_at' => now(),
        ]);

        ConversationMember::create([
            'conversation_id' => $conversation->id,
            'user_id' => $users['carine_mballa']->id,
            'role' => 'member',
            'joined_at' => now()->subHours(2),
        ]);

        ConversationMember::create([
            'conversation_id' => $conversation->id,
            'user_id' => $users['samuel_moukoko']->id,
            'role' => 'member',
            'joined_at' => now()->subHours(2),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $users['carine_mballa']->id,
            'type' => MessageType::Text,
            'body' => "Bonjour M. Moukoko, j'ai vu votre appartement 3 chambres à Bonapriso sur BANGO. Le groupe électrogène prend-il en charge les climatiseurs ?",
            'created_at' => now()->subHours(2),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $users['samuel_moukoko']->id,
            'type' => MessageType::Text,
            'body' => "Bonjour Carine ! Oui, absolument. C'est un groupe de 45 kVA automatique qui alimente toute la résidence, y compris les climatiseurs et chauffe-eaux. Vous souhaitez réserver un créneau de visite ce samedi ?",
            'created_at' => now()->subHour(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $users['carine_mballa']->id,
            'type' => MessageType::Text,
            'body' => "Parfait, c'est rassurant ! Je viens de réserver le créneau de 10h sur l'application. À samedi !",
            'created_at' => now()->subMinutes(15),
        ]);

        $this->command?->info('Données réalistes créées avec succès !');
    }
}
