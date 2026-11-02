<?php

use App\Jobs\Notification\SendPushNotificationJob;
use App\Models\Message\Conversation;
use App\Models\Message\Message;
use App\Models\Notification\DeviceToken;
use App\Models\User;
use App\Notifications\Message\NewMessageNotification;
use App\Services\Notification\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->sender = User::factory()->create(['name' => 'Alice']);
});

test('Expo push est envoye par lot avec les donnees attendues par l app', function () {
    Http::fake([
        PushNotificationService::EXPO_PUSH_URL => Http::response([
            'data' => [
                ['status' => 'ok', 'id' => 'ticket-1'],
            ],
        ], 200),
    ]);

    $deviceToken = DeviceToken::create([
        'user_id' => $this->user->id,
        'token' => 'ExponentPushToken[device_abc_123]',
        'platform' => 'android',
        'is_active' => true,
    ]);

    $service = app(PushNotificationService::class);
    $result = $service->sendToUser(
        $this->user,
        'Nouveau message',
        'Salut comment tu vas ?',
        [
            'type' => 'new_message',
            'conversation_id' => 'conv-999',
            'listing_id' => 'listing-456',
        ]
    );

    expect($result['sent'])->toBe(1)
        ->and($result['failed'])->toBe(0);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return count($body) === 1
            && $body[0]['to'] === 'ExponentPushToken[device_abc_123]'
            && $body[0]['title'] === 'Nouveau message'
            && $body[0]['body'] === 'Salut comment tu vas ?'
            && $body[0]['sound'] === 'default'
            && $body[0]['data']['type'] === 'new_message'
            && $body[0]['data']['conversation_id'] === 'conv-999'
            && $body[0]['data']['listing_id'] === 'listing-456';
    });

    expect($deviceToken->fresh()->last_used_at)->not->toBeNull();
});

test('un token invalide avec DeviceNotRegistered est automatiquement desactive', function () {
    Http::fake([
        PushNotificationService::EXPO_PUSH_URL => Http::response([
            'data' => [
                [
                    'status' => 'error',
                    'message' => 'Device not registered',
                    'details' => ['error' => 'DeviceNotRegistered'],
                ],
            ],
        ], 200),
    ]);

    $deviceToken = DeviceToken::create([
        'user_id' => $this->user->id,
        'token' => 'ExponentPushToken[unregistered_token]',
        'platform' => 'ios',
        'is_active' => true,
    ]);

    $service = app(PushNotificationService::class);
    $result = $service->sendToUser(
        $this->user,
        'Test désactivation',
        'Contenu',
        ['type' => 'like']
    );

    expect($result['sent'])->toBe(0)
        ->and($result['failed'])->toBe(1)
        ->and($deviceToken->fresh()->is_active)->toBeFalse();
});

test('les reglages de notifications desactivent le push si non souhaite par l utilisateur', function () {
    Http::fake([
        PushNotificationService::EXPO_PUSH_URL => Http::response([
            'data' => [['status' => 'ok', 'id' => 'ticket-ok']],
        ], 200),
    ]);

    DeviceToken::create([
        'user_id' => $this->user->id,
        'token' => 'ExponentPushToken[user_token]',
        'platform' => 'android',
        'is_active' => true,
    ]);

    // Cas 1 : push_enabled = false global
    $this->user->privacy_settings = [
        'notifications' => ['push_enabled' => false],
    ];
    $this->user->save();

    $service = app(PushNotificationService::class);
    $resGlobalDisabled = $service->sendToUser($this->user, 'Titre', 'Body', ['type' => 'new_message']);
    expect($resGlobalDisabled['sent'])->toBe(0);
    Http::assertNothingSent();

    // Cas 2 : push_enabled = true mais direct_messages = false
    $this->user->privacy_settings = [
        'notifications' => [
            'push_enabled' => true,
            'direct_messages' => false,
        ],
    ];
    $this->user->save();

    $resMessageDisabled = $service->sendToUser($this->user, 'Titre', 'Body', ['type' => 'new_message']);
    expect($resMessageDisabled['sent'])->toBe(0);
    Http::assertNothingSent();

    // Cas 3 : direct_messages = true -> le push part
    $userWithPush = User::factory()->create([
        'privacy_settings' => [
            'notifications' => [
                'push_enabled' => true,
                'direct_messages' => true,
            ],
        ],
    ]);

    DeviceToken::create([
        'user_id' => $userWithPush->id,
        'token' => 'ExponentPushToken[user_token_3]',
        'platform' => 'android',
        'is_active' => true,
    ]);

    Http::fake([
        PushNotificationService::EXPO_PUSH_URL => Http::response([
            'data' => [['status' => 'ok', 'id' => 'ticket-ok']],
        ], 200),
    ]);

    $resMessageEnabled = $service->sendToUser($userWithPush, 'Titre', 'Body', ['type' => 'new_message']);
    expect($resMessageEnabled['sent'])->toBe(1);
});

test('chaque notification de la base declenche automatiquement un job push avec le bon contexte data', function () {
    Queue::fake();

    $conversation = Conversation::create([
        'type' => 'direct',
        'created_by_id' => $this->sender->id,
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $this->sender->id,
        'type' => 'text',
        'body' => 'Coucou depuis Expo !',
    ]);

    // Émettre l'événement NotificationSent comme le fait le canal 'database'
    $notification = new NewMessageNotification($message);
    event(new NotificationSent($this->user, $notification, 'database', []));

    Queue::assertPushed(SendPushNotificationJob::class, function ($job) use ($conversation) {
        return $job->user->id === $this->user->id
            && $job->data['type'] === 'new_message'
            && (string) $job->data['conversation_id'] === (string) $conversation->id;
    });
});
