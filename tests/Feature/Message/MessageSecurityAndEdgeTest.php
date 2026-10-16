<?php

use App\Events\Message\MessageSent;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Message\Message;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->alice = User::factory()->create(['name' => 'Alice']);
    $this->bob = User::factory()->create(['name' => 'Bob']);
    $this->charlie = User::factory()->create(['name' => 'Charlie']);

    $this->thread = Conversation::factory()->create(['created_by_id' => $this->alice->id]);
    ConversationMember::create(['conversation_id' => $this->thread->id, 'user_id' => $this->alice->id]);
    ConversationMember::create(['conversation_id' => $this->thread->id, 'user_id' => $this->bob->id]);
});

test('regle du blocage silencieux : un utilisateur bloque voit son envoi reussir sans notification au bloqueur', function (): void {
    Event::fake([MessageSent::class]);
    Notification::fake();

    // Bob bloque Alice
    $this->bob->blockedUsers()->attach($this->alice->id);
    expect($this->bob->hasBlocked($this->alice))->toBeTrue();

    // Alice envoie un message à Bob
    Passport::actingAs($this->alice);

    $response = $this->postJson("/api/messages/threads/{$this->thread->id}/messages", [
        'body' => 'Es-tu là Bob ?',
    ]);

    // Règle MS-21 / EDGE-22 : BANGO ne dit jamais à Alice qu\'elle est bloquée
    $response->assertStatus(201)
        ->assertJsonPath('data.body', 'Es-tu là Bob ?');

    // Le message est marqué comme undelivered en interne
    $messageId = $response->json('data.id');
    $message = Message::findOrFail($messageId);
    expect($message->metadata['undelivered'] ?? false)->toBeTrue();

    // AUCUN événement broadcasté et AUCUNE notification pour Bob
    Event::assertNotDispatched(MessageSent::class);
    Notification::assertNothingSent();
});

test('seuls les membres actifs peuvent s authentifier sur le canal Reverb prive de la conversation', function (): void {
    $channels = Broadcast::getChannels();
    $callback = $channels['chat.conversation.{conversationId}'] ?? null;

    expect($callback)->not->toBeNull();

    // 1. Alice (membre) est autorisée
    expect($callback($this->alice, (string) $this->thread->id))->toBeTrue();

    // 2. Bob (membre) est autorisé
    expect($callback($this->bob, (string) $this->thread->id))->toBeTrue();

    // 3. Charlie (non membre) est rejeté
    expect($callback($this->charlie, (string) $this->thread->id))->toBeFalse();
});

test('un utilisateur ne peut pas ecouter le canal prive personnel d un autre utilisateur', function (): void {
    $channels = Broadcast::getChannels();
    $callback = $channels['chat.user.{userId}'] ?? null;

    expect($callback)->not->toBeNull();

    // Alice peut écouter son propre canal
    expect($callback($this->alice, (string) $this->alice->id))->toBeTrue();

    // Alice ne peut pas écouter le canal personnel de Bob
    expect($callback($this->alice, (string) $this->bob->id))->toBeFalse();
});

test('gestion des comptes supprimes : l historique de conversation reste lisible avec anonymisation', function (): void {
    $message = Message::factory()->create([
        'conversation_id' => $this->thread->id,
        'sender_id' => $this->bob->id,
        'body' => 'Message historique avant suppression',
    ]);

    // Bob supprime son compte (soft delete)
    $this->bob->delete();

    Passport::actingAs($this->alice);

    $response = $this->getJson("/api/messages/threads/{$this->thread->id}/messages");

    $response->assertStatus(200)
        ->assertJsonFragment([
            'id' => (string) $message->id,
            'body' => 'Message historique avant suppression',
        ]);
});

test('protection IDOR : un tiers ne peut ni lire ni envoyer de message dans une conversation etrangere', function (): void {
    Passport::actingAs($this->charlie);

    // Tentative de lecture
    $readResponse = $this->getJson("/api/messages/threads/{$this->thread->id}/messages");
    $readResponse->assertStatus(403);

    // Tentative d'envoi
    $sendResponse = $this->postJson("/api/messages/threads/{$this->thread->id}/messages", [
        'body' => 'Piratage de conversation',
    ]);
    $sendResponse->assertStatus(403);

    // Tentative de consultation du fil
    $showResponse = $this->getJson("/api/messages/threads/{$this->thread->id}");
    $showResponse->assertStatus(403);
});
