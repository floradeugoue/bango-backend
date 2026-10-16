<?php

use App\Models\Listing\Listing;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->user = User::factory()->create(['name' => 'Offline User']);
    $this->creator = User::factory()->create(['name' => 'Creator']);
});

test('POST /api/sync/flush traite avec succès un lot d\'actions hétérogènes (post, reel, listing, follow)', function () {
    Passport::actingAs($this->user);

    $post = Post::factory()->create(['user_id' => $this->creator->id]);
    $reel = Reel::factory()->create(['user_id' => $this->creator->id]);
    $listing = Listing::factory()->create(['owner_id' => $this->creator->id, 'created_by_id' => $this->creator->id]);
    $userToFollow = User::factory()->create(['is_private' => false]);

    $payload = [
        'actions' => [
            [
                'action_id' => 'act-1',
                'type' => 'like_post',
                'target_id' => (string) $post->id,
            ],
            [
                'action_id' => 'act-2',
                'type' => 'like_reel',
                'target_id' => (string) $reel->id,
            ],
            [
                'action_id' => 'act-3',
                'type' => 'view_reel',
                'target_id' => (string) $reel->id,
            ],
            [
                'action_id' => 'act-4',
                'type' => 'bookmark_listing',
                'target_id' => (string) $listing->id,
            ],
            [
                'action_id' => 'act-5',
                'type' => 'follow_user',
                'target_id' => (string) $userToFollow->id,
            ],
        ],
    ];

    $response = $this->postJson('/api/sync/flush', $payload);

    $response->assertOk()
        ->assertJsonPath('processed_count', 5)
        ->assertJsonPath('success_count', 5)
        ->assertJsonPath('failed_count', 0);

    // Vérifications en base de données
    $this->assertDatabaseHas('post_likes', [
        'post_id' => $post->id,
        'user_id' => $this->user->id,
    ]);

    $this->assertDatabaseHas('reel_reactions', [
        'reel_id' => $reel->id,
        'user_id' => $this->user->id,
        'type' => 'like',
    ]);

    $this->assertDatabaseHas('listing_saves', [
        'listing_id' => $listing->id,
        'user_id' => $this->user->id,
    ]);

    $this->assertDatabaseHas('follows', [
        'follower_id' => $this->user->id,
        'following_id' => $userToFollow->id,
    ]);
});

test('POST /api/sync/flush isole les échecs partiels sans annuler les actions valides', function () {
    Passport::actingAs($this->user);

    $validPost = Post::factory()->create(['user_id' => $this->creator->id]);

    $payload = [
        'actions' => [
            [
                'action_id' => 'act-valid',
                'type' => 'like_post',
                'target_id' => (string) $validPost->id,
            ],
            [
                'action_id' => 'act-invalid-target',
                'type' => 'like_reel',
                'target_id' => '00000000-0000-0000-0000-000000000000',
            ],
        ],
    ];

    $response = $this->postJson('/api/sync/flush', $payload);

    $response->assertOk()
        ->assertJsonPath('processed_count', 2)
        ->assertJsonPath('success_count', 1)
        ->assertJsonPath('failed_count', 1)
        ->assertJsonPath('results.0.status', 'success')
        ->assertJsonPath('results.1.status', 'failed')
        ->assertJsonPath('results.1.error', 'Reel introuvable.');

    // L'action valide a bien été enregistrée malgré l'échec de la seconde
    $this->assertDatabaseHas('post_likes', [
        'post_id' => $validPost->id,
        'user_id' => $this->user->id,
    ]);
});

test('POST /api/sync/flush gère l\'idempotence et les doublons intra-lot', function () {
    Passport::actingAs($this->user);

    $post = Post::factory()->create(['user_id' => $this->creator->id]);

    $payload = [
        'actions' => [
            [
                'action_id' => 'duplicate-id-1',
                'type' => 'like_post',
                'target_id' => (string) $post->id,
            ],
            [
                'action_id' => 'duplicate-id-1', // Même action_id dans le lot
                'type' => 'like_post',
                'target_id' => (string) $post->id,
            ],
        ],
    ];

    $response = $this->postJson('/api/sync/flush', $payload);

    $response->assertOk()
        ->assertJsonPath('processed_count', 2)
        ->assertJsonPath('results.0.status', 'success')
        ->assertJsonPath('results.1.status', 'already_applied');

    expect($post->likes()->count())->toBe(1);
});

test('POST /api/sync/flush rejette un lot vide ou supérieur à 50 actions', function () {
    Passport::actingAs($this->user);

    // Lot vide
    $emptyResponse = $this->postJson('/api/sync/flush', ['actions' => []]);
    $emptyResponse->assertStatus(422)->assertJsonValidationErrors(['actions']);

    // Lot de 51 actions
    $tooManyActions = [];
    for ($i = 0; $i < 51; $i++) {
        $tooManyActions[] = [
            'action_id' => "act-{$i}",
            'type' => 'like_post',
            'target_id' => '1',
        ];
    }

    $tooManyResponse = $this->postJson('/api/sync/flush', ['actions' => $tooManyActions]);
    $tooManyResponse->assertStatus(422)->assertJsonValidationErrors(['actions']);
});

test('POST /api/sync/flush rejette les types d\'actions non autorisés ou sensibles', function () {
    Passport::actingAs($this->user);

    $response = $this->postJson('/api/sync/flush', [
        'actions' => [
            [
                'action_id' => 'act-hack',
                'type' => 'transfer_credits', // Action financière non permise en offline sync
                'target_id' => '99',
            ],
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['actions.0.type']);
});
