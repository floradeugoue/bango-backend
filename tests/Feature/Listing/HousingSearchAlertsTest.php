<?php

use App\Enums\HousingIntent;
use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingStatus;
use App\Events\Listing\ListingPublished;
use App\Listeners\Listing\NotifyMatchingHousingSearches;
use App\Models\Housing\HousingSearch;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Notifications\Listing\NewMatchingListingNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();

    $this->owner = User::factory()->create(['name' => 'Bailleur']);
    $this->matchingSeeker = User::factory()->create(['name' => 'Chercheur Compatible']);
    $this->differentCitySeeker = User::factory()->create(['name' => 'Chercheur Autre Ville']);
    $this->outOfBudgetSeeker = User::factory()->create(['name' => 'Chercheur Petit Budget']);
    $this->differentKindSeeker = User::factory()->create(['name' => 'Chercheur Autre Type']);
    $this->inactiveSeeker = User::factory()->create(['name' => 'Chercheur Inactif']);

    // 1. Recherche parfaitement compatible
    HousingSearch::create([
        'user_id' => $this->matchingSeeker->id,
        'intent' => HousingIntent::Active,
        'city' => 'Douala',
        'neighbourhoods' => ['Akwa', 'Bonanjo'],
        'budget' => [150000, 300000],
        'types' => ['flat', 'studio'],
    ]);

    // 2. Recherche autre ville (Yaoundé)
    HousingSearch::create([
        'user_id' => $this->differentCitySeeker->id,
        'intent' => HousingIntent::Active,
        'city' => 'Yaoundé',
        'budget' => [100000, 500000],
    ]);

    // 3. Recherche hors budget (max 100 000)
    HousingSearch::create([
        'user_id' => $this->outOfBudgetSeeker->id,
        'intent' => HousingIntent::Active,
        'city' => 'Douala',
        'budget' => [50000, 100000],
    ]);

    // 4. Recherche autre type (villa uniquement)
    HousingSearch::create([
        'user_id' => $this->differentKindSeeker->id,
        'intent' => HousingIntent::Active,
        'city' => 'Douala',
        'types' => ['villa'],
    ]);

    // 5. Recherche inactive
    HousingSearch::create([
        'user_id' => $this->inactiveSeeker->id,
        'intent' => HousingIntent::No,
        'city' => 'Douala',
    ]);

    // 6. Recherche pour le bailleur lui-même (ne doit pas être notifié)
    HousingSearch::create([
        'user_id' => $this->owner->id,
        'intent' => HousingIntent::Active,
        'city' => 'Douala',
    ]);
});

test('La publication d\'une annonce notifie uniquement les utilisateurs dont la recherche concorde', function () {
    $listing = Listing::factory()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Bel appartement 3 pièces Akwa',
        'mode' => ListingMode::Rent,
        'kind' => ListingKind::Flat,
        'status' => ListingStatus::Published,
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'price' => 200000,
        'currency' => 'XAF',
        'published_at' => now(),
    ]);

    $listener = new NotifyMatchingHousingSearches;
    $listener->handle(new ListingPublished($listing));

    // L'utilisateur compatible reçoit la notification
    Notification::assertSentTo(
        $this->matchingSeeker,
        NewMatchingListingNotification::class,
        function ($notification) use ($listing) {
            return $notification->listing->id === $listing->id;
        }
    );

    // Les autres ne doivent pas être notifiés
    Notification::assertNotSentTo($this->differentCitySeeker, NewMatchingListingNotification::class);
    Notification::assertNotSentTo($this->outOfBudgetSeeker, NewMatchingListingNotification::class);
    Notification::assertNotSentTo($this->differentKindSeeker, NewMatchingListingNotification::class);
    Notification::assertNotSentTo($this->inactiveSeeker, NewMatchingListingNotification::class);
    Notification::assertNotSentTo($this->owner, NewMatchingListingNotification::class);
});

test('Une annonce non publiée ne déclenche aucune notification', function () {
    $draftListing = Listing::factory()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Brouillon annonce',
        'status' => ListingStatus::Draft,
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'price' => 200000,
    ]);

    $listener = new NotifyMatchingHousingSearches;
    $listener->handle(new ListingPublished($draftListing));

    Notification::assertNothingSent();
});

test('La déduplication empêche l\'envoi répété d\'alertes pour une même annonce', function () {
    $listing = Listing::factory()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Appartement Bonanjo',
        'status' => ListingStatus::Published,
        'city' => 'Douala',
        'neighbourhood' => 'Bonanjo',
        'price' => 250000,
        'kind' => ListingKind::Flat,
    ]);

    // Simuler qu'une notification a déjà été reçue
    $this->matchingSeeker->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => NewMatchingListingNotification::class,
        'data' => [
            'type' => 'new_matching_listing',
            'listing_id' => (string) $listing->id,
            'title' => 'Déjà notifié',
        ],
        'created_at' => now(),
    ]);

    $listener = new NotifyMatchingHousingSearches;
    $listener->handle(new ListingPublished($listing));

    // Aucune nouvelle notification ne doit être envoyée
    Notification::assertNotSentTo($this->matchingSeeker, NewMatchingListingNotification::class);
});
