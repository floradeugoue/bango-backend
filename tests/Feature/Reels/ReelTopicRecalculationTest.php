<?php

use App\Enums\Reel\ReelStatus;
use App\Models\Listing\Listing;
use App\Models\Profile\Interest;
use App\Models\Reel\Reel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Passport::actingAs($this->user);
});

test('la commande reels:recalculate-topics met a jour les reels lies a un bien vers realestate', function () {
    $listing = Listing::factory()->create();

    $reelWithListing = Reel::factory()->create([
        'user_id' => $this->user->id,
        'listing_id' => $listing->id,
        'topic' => 'neighbourhood',
        'caption' => 'Regardez notre vidéo',
    ]);

    $this->artisan('reels:recalculate-topics')
        ->assertSuccessful();

    expect($reelWithListing->fresh()->topic)->toBe('realestate');
});

test('la commande reels:recalculate-topics detecte le topic d apres les mots-cles de la legende', function () {
    $reelFood = Reel::factory()->create([
        'user_id' => $this->user->id,
        'topic' => 'neighbourhood',
        'caption' => 'Délicieux plat de ndolé fait maison pour le déjeuner !',
    ]);

    $reelFashion = Reel::factory()->create([
        'user_id' => $this->user->id,
        'topic' => 'neighbourhood',
        'caption' => 'Nouveau look avec une superbe robe en pagne',
    ]);

    $reelBusiness = Reel::factory()->create([
        'user_id' => $this->user->id,
        'topic' => 'neighbourhood',
        'caption' => 'Grande promo boutique disponible dès aujourd hui en boutique',
    ]);

    $reelMusic = Reel::factory()->create([
        'user_id' => $this->user->id,
        'topic' => 'neighbourhood',
        'caption' => 'Nouveau clip et single afrobeat en exclu',
    ]);

    $this->artisan('reels:recalculate-topics')
        ->assertSuccessful();

    expect($reelFood->fresh()->topic)->toBe('food')
        ->and($reelFashion->fresh()->topic)->toBe('fashion')
        ->and($reelBusiness->fresh()->topic)->toBe('business')
        ->and($reelMusic->fresh()->topic)->toBe('music');
});

test('la commande reels:recalculate-topics utilise le premier centre d interet de l auteur a defaut de legende', function () {
    $interestFashion = Interest::firstOrCreate(
        ['id' => 'fashion'],
        ['name' => 'Mode & Beauté', 'category' => 'lifestyle', 'icon' => 'sparkles']
    );

    $authorWithInterest = User::factory()->create();
    $authorWithInterest->interests()->attach($interestFashion->id);

    $reel = Reel::factory()->create([
        'user_id' => $authorWithInterest->id,
        'topic' => 'neighbourhood',
        'caption' => 'Juste une journée comme les autres',
    ]);

    $this->artisan('reels:recalculate-topics')
        ->assertSuccessful();

    expect($reel->fresh()->topic)->toBe('fashion');
});

test('la commande reels:recalculate-topics conserve neighbourhood si aucun critere n est trouve', function () {
    $reel = Reel::factory()->create([
        'user_id' => $this->user->id,
        'topic' => 'neighbourhood',
        'caption' => 'Bonjour à tous',
        'listing_id' => null,
    ]);

    $this->artisan('reels:recalculate-topics')
        ->assertSuccessful();

    expect($reel->fresh()->topic)->toBe('neighbourhood');
});

test('le feed vertical accepte les alias housing et realestate indifferemment', function () {
    Reel::factory()->create([
        'user_id' => $this->user->id,
        'status' => ReelStatus::Ready,
        'topic' => 'realestate',
        'caption' => 'Reel Immo 1',
    ]);

    Reel::factory()->create([
        'user_id' => $this->user->id,
        'status' => ReelStatus::Ready,
        'topic' => 'housing',
        'caption' => 'Reel Immo 2',
    ]);

    Reel::factory()->create([
        'user_id' => $this->user->id,
        'status' => ReelStatus::Ready,
        'topic' => 'food',
        'caption' => 'Reel Cuisine',
    ]);

    // Requête avec topic=housing
    $responseHousing = $this->getJson('/api/reels/feed?topic=housing');
    $responseHousing->assertStatus(200)
        ->assertJsonCount(2, 'data');

    // Requête avec topic=realestate
    $responseRealestate = $this->getJson('/api/reels/feed?topic=realestate');
    $responseRealestate->assertStatus(200)
        ->assertJsonCount(2, 'data');
});
