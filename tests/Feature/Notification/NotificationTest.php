<?php

use App\Jobs\Notification\SendPushNotificationJob;
use App\Models\Notification\DeviceToken;
use App\Models\User;
use App\Services\Notification\PushNotificationService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->user = User::factory()->create(['name' => 'Kouamé Notif']);
    $this->otherUser = User::factory()->create(['name' => 'Autre Utilisateur']);
});

test('GET /api/notifications retourne la liste paginée et le compteur unread_count', function () {
    Passport::actingAs($this->user);

    // Créer des notifications pour l'utilisateur
    DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\Message\NewMessageNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $this->user->id,
        'data' => [
            'type' => 'new_message',
            'title' => 'Nouveau message de Paul',
            'preview' => 'Bonjour, le bien est-il disponible ?',
            'conversation_id' => 'conv-123',
        ],
        'read_at' => null,
    ]);

    DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\Visit\VisitRequestedNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $this->user->id,
        'data' => [
            'type' => 'visit_requested',
            'title' => 'Demande de visite',
            'preview' => 'Une visite est demandée pour demain à 14h.',
            'slot_id' => 99,
        ],
        'read_at' => now(),
    ]);

    // Notification d'un autre utilisateur (ne doit pas apparaître)
    DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\Message\NewMessageNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $this->otherUser->id,
        'data' => ['title' => 'Secret'],
        'read_at' => null,
    ]);

    $response = $this->getJson('/api/notifications');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('unread_count', 1);

    expect($response->json('data.0.title'))->not->toBe('Secret');
});

test('GET /api/notifications filtre par catégorie (immo, social, account)', function () {
    Passport::actingAs($this->user);

    // Notification immo
    DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\Visit\VisitRequestedNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $this->user->id,
        'data' => ['type' => 'visit_requested', 'title' => 'Visite Immo'],
        'read_at' => null,
    ]);

    // Notification social
    DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\Message\NewMessageNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $this->user->id,
        'data' => ['type' => 'new_message', 'title' => 'Message Social'],
        'read_at' => null,
    ]);

    // Notification account
    DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\Boost\CampaignActivatedNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $this->user->id,
        'data' => ['type' => 'campaign_activated', 'title' => 'Campagne Boost Active'],
        'read_at' => null,
    ]);

    // Test immo
    $immoRes = $this->getJson('/api/notifications?category=immo');
    $immoRes->assertOk()->assertJsonCount(1, 'data');
    expect($immoRes->json('data.0.category'))->toBe('immo')
        ->and($immoRes->json('data.0.title'))->toBe('Visite Immo');

    // Test social
    $socialRes = $this->getJson('/api/notifications?category=social');
    $socialRes->assertOk()->assertJsonCount(1, 'data');
    expect($socialRes->json('data.0.category'))->toBe('social')
        ->and($socialRes->json('data.0.title'))->toBe('Message Social');

    // Test account
    $accountRes = $this->getJson('/api/notifications?category=account');
    $accountRes->assertOk()->assertJsonCount(1, 'data');
    expect($accountRes->json('data.0.category'))->toBe('account')
        ->and($accountRes->json('data.0.title'))->toBe('Campagne Boost Active');
});

test('POST /api/notifications/{id}/read acquitte une notification unitaire avec recalcul du compteur', function () {
    Passport::actingAs($this->user);

    $notif = DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\Message\NewMessageNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $this->user->id,
        'data' => ['title' => 'Message à lire'],
        'read_at' => null,
    ]);

    $response = $this->postJson("/api/notifications/{$notif->id}/read");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('unread_count', 0);

    expect($notif->fresh()->read_at)->not->toBeNull();

    // Idempotence : un second appel successif reste 200 et ne modifie rien
    $second = $this->postJson("/api/notifications/{$notif->id}/read");
    $second->assertOk()->assertJsonPath('unread_count', 0);
});

test('POST /api/notifications/{id}/read refuse l\'accès à une notification d\'un tiers (403)', function () {
    Passport::actingAs($this->user);

    $foreignNotif = DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\Message\NewMessageNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $this->otherUser->id,
        'data' => ['title' => 'Privé'],
        'read_at' => null,
    ]);

    $response = $this->postJson("/api/notifications/{$foreignNotif->id}/read");
    $response->assertStatus(403);
});

test('POST /api/notifications/read acquitte toutes les notifications en lot', function () {
    Passport::actingAs($this->user);

    for ($i = 0; $i < 3; $i++) {
        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'App\Notifications\Message\NewMessageNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'data' => ['title' => "Message {$i}"],
            'read_at' => null,
        ]);
    }

    expect($this->user->unreadNotifications()->count())->toBe(3);

    $response = $this->postJson('/api/notifications/read');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('unread_count', 0);

    expect($this->user->unreadNotifications()->count())->toBe(0);
});

test('POST /api/notifications/device-token enregistre et met à jour un token push APNs/FCM', function () {
    Passport::actingAs($this->user);

    $tokenString = 'fcm_fake_token_xyz_123456789';

    $response = $this->postJson('/api/notifications/device-token', [
        'token' => $tokenString,
        'platform' => 'android',
        'device_id' => 'pixel-7-device-01',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('device_token.platform', 'android')
        ->assertJsonPath('device_token.device_id', 'pixel-7-device-01');

    $this->assertDatabaseHas('device_tokens', [
        'user_id' => $this->user->id,
        'token' => $tokenString,
        'platform' => 'android',
        'is_active' => true,
    ]);
});

test('POST /api/notifications/device-token réassigne le token lors d\'un changement de compte', function () {
    Passport::actingAs($this->otherUser);

    $sharedToken = 'shared_terminal_token_abc_987';

    // Enregistrement par otherUser
    $this->postJson('/api/notifications/device-token', [
        'token' => $sharedToken,
        'platform' => 'ios',
        'device_id' => 'iphone-15-pro',
    ])->assertOk();

    expect(DeviceToken::where('token', $sharedToken)->first()->user_id)->toBe($this->otherUser->id);

    // Connexion avec un nouveau compte sur le même appareil
    Passport::actingAs($this->user);
    $response = $this->postJson('/api/notifications/device-token', [
        'token' => $sharedToken,
        'platform' => 'ios',
        'device_id' => 'iphone-15-pro',
    ]);

    $response->assertOk();

    // Le token appartient maintenant à $this->user sans duplication
    expect(DeviceToken::where('token', $sharedToken)->count())->toBe(1)
        ->and(DeviceToken::where('token', $sharedToken)->first()->user_id)->toBe($this->user->id);
});

test('POST /api/notifications/device-token rejette les plateformes invalides', function () {
    Passport::actingAs($this->user);

    $response = $this->postJson('/api/notifications/device-token', [
        'token' => 'some_token',
        'platform' => 'windows_phone',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['platform']);
});

test('DELETE /api/notifications/device-token désactive le token lors de la déconnexion', function () {
    Passport::actingAs($this->user);

    $token = DeviceToken::create([
        'user_id' => $this->user->id,
        'token' => 'active_token_to_revoke',
        'platform' => 'android',
        'is_active' => true,
    ]);

    $response = $this->deleteJson('/api/notifications/device-token', [
        'token' => 'active_token_to_revoke',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect($token->fresh()->is_active)->toBeFalse();
});

test('PushNotificationService transmet les pushs sans casser les in-app en cas d\'erreur', function () {
    DeviceToken::create([
        'user_id' => $this->user->id,
        'token' => 'valid_token_123',
        'platform' => 'android',
        'is_active' => true,
    ]);

    $pushService = app(PushNotificationService::class);

    $result = $pushService->sendToUser(
        $this->user,
        'Nouvelle alerte',
        'Votre compte a été vérifié avec succès.',
        ['context' => 'kyc']
    );

    expect($result['sent'])->toBe(1)
        ->and($result['failed'])->toBe(0);
});

test('SendPushNotificationJob est dispatchable sur la file d\'attente', function () {
    Queue::fake();

    SendPushNotificationJob::dispatch(
        $this->user,
        'Visite confirmée',
        'Votre rendez-vous est confirmé pour 15h.',
        ['visit_id' => 42]
    );

    Queue::assertPushed(SendPushNotificationJob::class, function ($job) {
        return $job->user->id === $this->user->id && $job->title === 'Visite confirmée';
    });
});
