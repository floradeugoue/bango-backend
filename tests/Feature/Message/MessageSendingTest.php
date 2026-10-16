<?php

use App\Enums\Delegation\DelegationStatus;
use App\Enums\Message\ConversationType;
use App\Enums\Message\MessageType;
use App\Events\Message\MessageSent;
use App\Models\Delegation\Delegation;
use App\Models\Listing\Listing;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Message\Message;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Notifications\Message\NewMessageNotification;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->alice = User::factory()->create(['name' => 'Alice']);
    $this->bob = User::factory()->create(['name' => 'Bob']);

    $this->thread = Conversation::factory()->create(['created_by_id' => $this->alice->id]);
    ConversationMember::create(['conversation_id' => $this->thread->id, 'user_id' => $this->alice->id]);
    ConversationMember::create(['conversation_id' => $this->thread->id, 'user_id' => $this->bob->id]);

    $this->agentProduct = SubscriptionProduct::where('code', 'agent')->first();
    $this->agentPrice = SubscriptionPrice::where('product_id', $this->agentProduct->id)->where('cadence', 'monthly')->first();

    $this->createAgent = function (array $attributes = []): User {
        $user = User::factory()->create(array_merge([
            'badge' => 'agent',
            'is_verified' => true,
            'verification_lapsed' => false,
        ], $attributes));

        Subscription::create([
            'user_id' => $user->id,
            'product_id' => $this->agentProduct->id,
            'price_id' => $this->agentPrice->id,
            'badge_type' => 'agent',
            'cadence' => 'monthly',
            'amount' => $this->agentPrice->price_amount,
            'currency' => 'XAF',
            'state' => 'active',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
            'auto_renew' => true,
        ]);

        return $user;
    };
});

test('un membre peut envoyer un message texte dans un fil de discussion', function (): void {
    Event::fake([MessageSent::class]);
    Notification::fake();

    Passport::actingAs($this->alice);

    $response = $this->postJson("/api/messages/threads/{$this->thread->id}/messages", [
        'body' => 'Bonjour Bob, comment vas-tu ?',
        'client_message_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.body', 'Bonjour Bob, comment vas-tu ?')
        ->assertJsonPath('data.sender_id', $this->alice->id)
        ->assertJsonPath('data.type', 'text');

    $messageId = $response->json('data.id');
    expect(Message::where('id', $messageId)->exists())->toBeTrue();

    Event::assertDispatched(MessageSent::class);
    Notification::assertSentTo($this->bob, NewMessageNotification::class);
});

test('la soumission d un meme client_message_id est idempotente et ne duplique pas le message', function (): void {
    Event::fake([MessageSent::class]);
    Notification::fake();

    Passport::actingAs($this->alice);

    $clientUuid = (string) Str::uuid();

    $res1 = $this->postJson("/api/messages/threads/{$this->thread->id}/messages", [
        'body' => 'Message idempotent test',
        'client_message_id' => $clientUuid,
    ]);
    $res1->assertStatus(201);
    $id1 = $res1->json('data.id');

    // Seconde tentative (ex: retry réseau mobile)
    $res2 = $this->postJson("/api/messages/threads/{$this->thread->id}/messages", [
        'body' => 'Message idempotent test',
        'client_message_id' => $clientUuid,
    ]);
    $res2->assertStatus(201);
    $id2 = $res2->json('data.id');

    expect($id1)->toBe($id2);
    expect(Message::where('client_message_id', $clientUuid)->count())->toBe(1);

    // L'événement et la notification ne doivent être émis qu'une seule fois
    Event::assertDispatchedTimes(MessageSent::class, 1);
    Notification::assertSentTimes(NewMessageNotification::class, 1);
});

test('un message vide sans piece jointe est refuse avec une erreur 422', function (): void {
    Passport::actingAs($this->alice);

    $response = $this->postJson("/api/messages/threads/{$this->thread->id}/messages", [
        'body' => '',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['body']);
});

test('un utilisateur non membre ne peut pas poster dans la conversation', function (): void {
    $stranger = User::factory()->create();

    Passport::actingAs($stranger);

    $response = $this->postJson("/api/messages/threads/{$this->thread->id}/messages", [
        'body' => 'Intrusion test',
    ]);

    $response->assertStatus(403);
});

test('l auteur peut retracter son message', function (): void {
    Passport::actingAs($this->alice);

    $message = Message::factory()->create([
        'conversation_id' => $this->thread->id,
        'sender_id' => $this->alice->id,
        'body' => 'Message secret avant rétractation',
    ]);

    $response = $this->postJson("/api/messages/threads/{$this->thread->id}/messages/{$message->id}/retract");

    $response->assertStatus(200)
        ->assertJsonPath('data.is_retracted', true)
        ->assertJsonPath('data.body', null);

    $message->refresh();
    expect($message->is_retracted)->toBeTrue();
    expect($message->body)->toBeNull();

    // Bob ne peut pas rétracter le message d'Alice
    Passport::actingAs($this->bob);
    $forbidden = $this->postJson("/api/messages/threads/{$this->thread->id}/messages/{$message->id}/retract");
    $forbidden->assertStatus(403);
});

test('un agent avec mandat actif et capacite reply peut repondre dans le fil de l annonce', function (): void {
    $landlord = User::factory()->create();
    $agent = ($this->createAgent)();
    $prospect = User::factory()->create();

    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'agent_id' => $agent->id,
    ]);

    Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Active->value,
        'capabilities' => ['reply', 'visits'],
        'invited_at' => now()->subDay(),
        'answered_at' => now(),
    ]);

    $immoThread = Conversation::factory()->create([
        'type' => ConversationType::Direct,
        'listing_id' => $listing->id,
        'created_by_id' => $prospect->id,
    ]);
    ConversationMember::create(['conversation_id' => $immoThread->id, 'user_id' => $prospect->id]);
    ConversationMember::create(['conversation_id' => $immoThread->id, 'user_id' => $agent->id]);

    Passport::actingAs($agent);

    $response = $this->postJson("/api/messages/threads/{$immoThread->id}/messages", [
        'body' => 'Bonjour, je suis l\'agent mandaté pour ce bien.',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.body', 'Bonjour, je suis l\'agent mandaté pour ce bien.');
});

test('un agent sans capacite reply ne peut pas repondre dans le fil de l annonce', function (): void {
    $landlord = User::factory()->create();
    $agent = ($this->createAgent)();
    $prospect = User::factory()->create();

    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'agent_id' => $agent->id,
    ]);

    // Mandat sans la capacité reply
    Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Active->value,
        'capabilities' => ['visits'], // Pas de 'reply'
        'invited_at' => now()->subDay(),
        'answered_at' => now(),
    ]);

    $immoThread = Conversation::factory()->create([
        'type' => ConversationType::Direct,
        'listing_id' => $listing->id,
        'created_by_id' => $prospect->id,
    ]);
    ConversationMember::create(['conversation_id' => $immoThread->id, 'user_id' => $prospect->id]);
    ConversationMember::create(['conversation_id' => $immoThread->id, 'user_id' => $agent->id]);

    Passport::actingAs($agent);

    $response = $this->postJson("/api/messages/threads/{$immoThread->id}/messages", [
        'body' => 'Tentative de réponse non autorisée',
    ]);

    $response->assertStatus(403);
});

test('un agent revoque ne peut plus repondre dans le fil de l annonce', function (): void {
    $landlord = User::factory()->create();
    $agent = ($this->createAgent)();
    $prospect = User::factory()->create();

    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'agent_id' => $agent->id,
    ]);

    Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Revoked->value,
        'capabilities' => ['reply', 'visits'],
        'invited_at' => now()->subDays(10),
        'answered_at' => now()->subDays(9),
        'revoked_at' => now()->subDay(),
    ]);

    $immoThread = Conversation::factory()->create([
        'type' => ConversationType::Direct,
        'listing_id' => $listing->id,
        'created_by_id' => $prospect->id,
    ]);
    ConversationMember::create(['conversation_id' => $immoThread->id, 'user_id' => $prospect->id]);
    ConversationMember::create(['conversation_id' => $immoThread->id, 'user_id' => $agent->id]);

    Passport::actingAs($agent);

    $response = $this->postJson("/api/messages/threads/{$immoThread->id}/messages", [
        'body' => 'Message après révocation du mandat',
    ]);

    $response->assertStatus(403);
});

test('un membre peut envoyer une note vocale audio valide', function (): void {
    Storage::fake('public');
    Event::fake([MessageSent::class]);

    Passport::actingAs($this->alice);

    $audioFile = UploadedFile::fake()->create('voicenote.m4a', 500, 'audio/x-m4a');

    $response = $this->postJson("/api/messages/threads/{$this->thread->id}/voice", [
        'voice' => $audioFile,
        'duration_ms' => 4200,
        'client_message_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'voice')
        ->assertJsonPath('data.attachment_duration_ms', 4200)
        ->assertJsonPath('data.attachment_mime', 'audio/x-m4a');

    $messageId = $response->json('data.id');
    $message = Message::findOrFail($messageId);
    expect($message->type)->toBe(MessageType::Voice);
    expect($message->attachment_path)->not->toBeNull();
    Storage::disk('public')->assertExists($message->attachment_path);

    Event::assertDispatched(MessageSent::class);
});

test('un fichier audio non supporte ou trop volumineux est rejete', function (): void {
    Storage::fake('public');

    Passport::actingAs($this->alice);

    // Format non autorisé (PDF au lieu d'audio)
    $fakePdf = UploadedFile::fake()->create('document.pdf', 200, 'application/pdf');

    $res1 = $this->postJson("/api/messages/threads/{$this->thread->id}/voice", [
        'voice' => $fakePdf,
    ]);
    $res1->assertStatus(422)
        ->assertJsonValidationErrors(['voice']);

    // Fichier audio dépassant 25 Mo
    $hugeAudio = UploadedFile::fake()->create('huge.mp3', 30000, 'audio/mpeg'); // 30 Mo
    $res2 = $this->postJson("/api/messages/threads/{$this->thread->id}/voice", [
        'voice' => $hugeAudio,
    ]);
    $res2->assertStatus(422)
        ->assertJsonValidationErrors(['voice']);
});
