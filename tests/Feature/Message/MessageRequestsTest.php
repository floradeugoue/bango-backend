<?php

use App\Models\Message\Conversation;
use App\Models\Message\Message;
use App\Models\Profile\Follow;
use App\Models\Profile\UserBlock;
use App\Models\User;
use App\Services\Message\MessageThreadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('cree une conversation en attente (demande) lorsque l initiateur est un inconnu', function () {
    $sender = User::factory()->create(['status' => 'active']);
    $recipient = User::factory()->create(['status' => 'active']);

    // Le destinataire NE suit PAS l'expéditeur -> inconnu
    Passport::actingAs($sender);

    $response = postJson('/api/messages/threads', [
        'peer_id' => $recipient->id,
    ]);

    $response->assertStatus(201);
    $threadId = $response->json('data.id');

    $conversation = Conversation::find($threadId);
    expect($conversation->status)->toBe('pending');
    expect($conversation->isPending())->toBeTrue();

    // La demande doit être visible dans la boîte de demandes du destinataire
    Passport::actingAs($recipient);

    $reqResponse = getJson('/api/messages/requests');
    $reqResponse->assertStatus(200);

    $data = $reqResponse->json('data');
    expect(count($data))->toBe(1);
    expect($data[0]['id'])->toBe($threadId);

    // Et elle ne doit PAS polluer la boîte de réception principale du destinataire
    $inboxResponse = getJson('/api/messages/threads');
    $inboxResponse->assertStatus(200);
    expect($inboxResponse->json('data'))->toBeEmpty();
});

it('cree un fil directement actif si le destinataire suit deja l expediteur', function () {
    $sender = User::factory()->create(['status' => 'active']);
    $recipient = User::factory()->create(['status' => 'active']);

    // Le destinataire suit déjà l'expéditeur
    Follow::create(['follower_id' => $recipient->id, 'following_id' => $sender->id]);

    Passport::actingAs($sender);

    $response = postJson('/api/messages/threads', [
        'peer_id' => $recipient->id,
    ]);

    $response->assertStatus(201);
    $threadId = $response->json('data.id');

    $conversation = Conversation::find($threadId);
    expect($conversation->status)->toBe('active');

    // N'apparaît pas dans les demandes du destinataire
    Passport::actingAs($recipient);
    $reqResponse = getJson('/api/messages/requests');
    expect($reqResponse->json('data'))->toBeEmpty();

    // Apparaît directement dans sa boîte principale
    $inboxResponse = getJson('/api/messages/threads');
    expect(count($inboxResponse->json('data')))->toBe(1);
});

it('isole strictement les demandes par utilisateur et empeche l expediteur de voir sa propre demande dans requests', function () {
    $sender = User::factory()->create(['status' => 'active']);
    $recipient = User::factory()->create(['status' => 'active']);
    $other = User::factory()->create(['status' => 'active']);

    /** @var MessageThreadService $threadService */
    $threadService = app(MessageThreadService::class);
    $conversation = $threadService->getOrCreateDirectConversation($sender, $recipient->id);

    // L'expéditeur ne doit pas voir cette conversation dans ses demandes reçues
    Passport::actingAs($sender);
    $senderReq = getJson('/api/messages/requests');
    $senderReq->assertStatus(200);
    expect($senderReq->json('data'))->toBeEmpty();

    // Un tiers ne doit pas la voir
    Passport::actingAs($other);
    $otherReq = getJson('/api/messages/requests');
    expect($otherReq->json('data'))->toBeEmpty();

    // Seul le destinataire la voit
    Passport::actingAs($recipient);
    $recipReq = getJson('/api/messages/requests');
    expect(count($recipReq->json('data')))->toBe(1);
    expect($recipReq->json('data.0.id'))->toBe((string) $conversation->id);
});

it('permet au destinataire d accepter une demande et la bascule en fil normal', function () {
    $sender = User::factory()->create(['status' => 'active']);
    $recipient = User::factory()->create(['status' => 'active']);

    /** @var MessageThreadService $threadService */
    $threadService = app(MessageThreadService::class);
    $conversation = $threadService->getOrCreateDirectConversation($sender, $recipient->id);

    // Ajouter un message initial
    Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $sender->id,
        'type' => 'text',
        'body' => 'Bonjour, pouvons-nous échanger ?',
    ]);

    Passport::actingAs($recipient);

    $response = postJson("/api/messages/requests/{$conversation->id}/accept");

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Demande de message acceptée avec succès.',
        ]);

    $conversation->refresh();
    expect($conversation->status)->toBe('active');

    // La conversation ne doit plus être dans les demandes
    $reqResponse = getJson('/api/messages/requests');
    expect($reqResponse->json('data'))->toBeEmpty();

    // La conversation est maintenant visible dans l'inbox principale
    $inboxResponse = getJson('/api/messages/threads');
    expect(count($inboxResponse->json('data')))->toBe(1);
    expect($inboxResponse->json('data.0.id'))->toBe((string) $conversation->id);

    // L'acceptation est idempotente
    $idempotentResponse = postJson("/api/messages/requests/{$conversation->id}/accept");
    $idempotentResponse->assertStatus(200);
});

it('permet au destinataire de refuser une demande', function () {
    $sender = User::factory()->create(['status' => 'active']);
    $recipient = User::factory()->create(['status' => 'active']);

    /** @var MessageThreadService $threadService */
    $threadService = app(MessageThreadService::class);
    $conversation = $threadService->getOrCreateDirectConversation($sender, $recipient->id);

    Passport::actingAs($recipient);

    $response = postJson("/api/messages/requests/{$conversation->id}/reject");

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Demande de message refusée avec succès.',
        ]);

    // La conversation est rejetée et soft-deleted pour traçabilité
    $conversationWithTrashed = Conversation::withTrashed()->find($conversation->id);
    expect($conversationWithTrashed->status)->toBe('rejected');
    expect($conversationWithTrashed->trashed())->toBeTrue();

    // Elle n'apparaît plus ni dans les demandes ni dans la boîte principale
    expect(getJson('/api/messages/requests')->json('data'))->toBeEmpty();
    expect(getJson('/api/messages/threads')->json('data'))->toBeEmpty();

    // Le refus est idempotent
    $idempotentResponse = postJson("/api/messages/requests/{$conversation->id}/reject");
    $idempotentResponse->assertStatus(200);
});

it('interdit a un tiers ou a l expediteur d accepter ou refuser la demande', function () {
    $sender = User::factory()->create(['status' => 'active']);
    $recipient = User::factory()->create(['status' => 'active']);
    $thirdParty = User::factory()->create(['status' => 'active']);

    /** @var MessageThreadService $threadService */
    $threadService = app(MessageThreadService::class);
    $conversation = $threadService->getOrCreateDirectConversation($sender, $recipient->id);

    // L'expéditeur ne peut pas accepter sa propre demande
    Passport::actingAs($sender);
    postJson("/api/messages/requests/{$conversation->id}/accept")->assertStatus(403);
    postJson("/api/messages/requests/{$conversation->id}/reject")->assertStatus(403);

    // Un tiers ne peut pas accepter ou refuser
    Passport::actingAs($thirdParty);
    postJson("/api/messages/requests/{$conversation->id}/accept")->assertStatus(403);
    postJson("/api/messages/requests/{$conversation->id}/reject")->assertStatus(403);
});

it('bloque la modification concurrente ou contradictoire entre accept et reject', function () {
    $sender = User::factory()->create(['status' => 'active']);
    $recipient = User::factory()->create(['status' => 'active']);

    /** @var MessageThreadService $threadService */
    $threadService = app(MessageThreadService::class);
    $conversation = $threadService->getOrCreateDirectConversation($sender, $recipient->id);

    Passport::actingAs($recipient);

    // On accepte d'abord la demande
    postJson("/api/messages/requests/{$conversation->id}/accept")->assertStatus(200);

    // Une tentative de rejet ultérieure doit être rejetée avec erreur métier
    postJson("/api/messages/requests/{$conversation->id}/reject")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['request']);
});

it('exclut des demandes les utilisateurs bloques par le destinataire', function () {
    $sender = User::factory()->create(['status' => 'active']);
    $recipient = User::factory()->create(['status' => 'active']);

    /** @var MessageThreadService $threadService */
    $threadService = app(MessageThreadService::class);
    $conversation = $threadService->getOrCreateDirectConversation($sender, $recipient->id);

    // Le destinataire bloque l'expéditeur
    UserBlock::create(['blocker_id' => $recipient->id, 'blocked_id' => $sender->id]);

    Passport::actingAs($recipient);

    $response = getJson('/api/messages/requests');
    $response->assertStatus(200);
    expect($response->json('data'))->toBeEmpty();
});
