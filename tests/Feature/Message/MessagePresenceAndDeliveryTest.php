<?php

use App\Enums\Message\MessageType;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Message\Message;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->alice = User::factory()->create(['name' => 'Alice']);
    $this->bob = User::factory()->create(['name' => 'Bob']);
});

test('UpdateUserLastSeen middleware met a jour last_seen_at au plus une fois par minute', function (): void {
    Carbon::setTestNow('2026-10-26 12:00:00');

    Passport::actingAs($this->alice);

    expect($this->alice->fresh()->last_seen_at)->toBeNull();

    // 1ère requête API
    $this->getJson('/api/profile')->assertStatus(200);
    $firstSeen = $this->alice->fresh()->last_seen_at;
    expect($firstSeen)->not->toBeNull();
    expect($firstSeen->toDateTimeString())->toBe('2026-10-26 12:00:00');

    // 30 secondes plus tard : pas de mise à jour (< 60 secondes)
    Carbon::setTestNow('2026-10-26 12:00:30');
    $this->getJson('/api/profile')->assertStatus(200);
    expect($this->alice->fresh()->last_seen_at->toDateTimeString())->toBe('2026-10-26 12:00:00');

    // 61 secondes plus tard (> 60 secondes) : mise à jour
    Carbon::setTestNow('2026-10-26 12:01:05');
    $this->getJson('/api/profile')->assertStatus(200);
    expect($this->alice->fresh()->last_seen_at->toDateTimeString())->toBe('2026-10-26 12:01:05');
});

test('ConversationMemberResource rend is_online et last_seen_at selon le reglage Statut en ligne', function (): void {
    Carbon::setTestNow('2026-10-26 12:00:00');

    // Alice et Bob dans une conversation
    $thread = Conversation::factory()->create(['created_by_id' => $this->alice->id]);
    ConversationMember::create(['conversation_id' => $thread->id, 'user_id' => $this->alice->id]);
    ConversationMember::create(['conversation_id' => $thread->id, 'user_id' => $this->bob->id]);

    // Bob a été vu il y a 60 secondes (donc is_online = true car < 120s) et autorise onlineStatus
    $this->bob->update([
        'last_seen_at' => Carbon::now()->subSeconds(60),
        'privacy_settings' => ['onlineStatus' => true],
    ]);

    Passport::actingAs($this->alice);
    $response = $this->getJson("/api/messages/threads/{$thread->id}");
    $response->assertStatus(200);

    $bobMember = collect($response->json('data.members'))->firstWhere('user_id', $this->bob->id);
    expect($bobMember['user']['is_online'])->toBeTrue();
    expect($bobMember['user']['last_seen_at'])->not->toBeNull();

    // Bob a été vu il y a 3 minutes (donc is_online = false car > 120s)
    $this->bob->update([
        'last_seen_at' => Carbon::now()->subSeconds(180),
    ]);

    $response2 = $this->getJson("/api/messages/threads/{$thread->id}");
    $bobMember2 = collect($response2->json('data.members'))->firstWhere('user_id', $this->bob->id);
    expect($bobMember2['user']['is_online'])->toBeFalse();
    expect($bobMember2['user']['last_seen_at'])->not->toBeNull();

    // Bob désactive son statut en ligne (onlineStatus = false)
    $this->bob->update([
        'last_seen_at' => Carbon::now()->subSeconds(30),
        'privacy_settings' => ['onlineStatus' => false],
    ]);

    $response3 = $this->getJson("/api/messages/threads/{$thread->id}");
    $bobMember3 = collect($response3->json('data.members'))->firstWhere('user_id', $this->bob->id);
    expect($bobMember3['user']['is_online'])->toBeFalse();
    expect($bobMember3['user']['last_seen_at'])->toBeNull();
});

test('la recuperation des messages met a jour last_delivered_at et expose le statut sent, delivered, read', function (): void {
    Carbon::setTestNow('2026-10-26 14:00:00');

    $thread = Conversation::factory()->create(['created_by_id' => $this->alice->id]);
    $aliceMember = ConversationMember::create(['conversation_id' => $thread->id, 'user_id' => $this->alice->id]);
    $bobMember = ConversationMember::create(['conversation_id' => $thread->id, 'user_id' => $this->bob->id]);

    // Alice envoie un message
    $message = Message::create([
        'conversation_id' => $thread->id,
        'sender_id' => $this->alice->id,
        'type' => MessageType::Text,
        'body' => 'Bonjour Bob',
        'created_at' => Carbon::now(),
    ]);

    // Au départ pour Alice : le message est 'sent' (simple coche)
    Passport::actingAs($this->alice);
    $resAlice1 = $this->getJson("/api/messages/threads/{$thread->id}/messages");
    $resAlice1->assertStatus(200);
    $msgAlice1 = $resAlice1->json('data.0');
    expect($msgAlice1['status'])->toBe('sent');
    expect($msgAlice1['is_delivered'])->toBeFalse();
    expect($msgAlice1['is_read'])->toBeFalse();

    // Bob récupère les messages (son appareil télécharge les messages)
    Carbon::setTestNow('2026-10-26 14:02:00');
    Passport::actingAs($this->bob);
    $resBob = $this->getJson("/api/messages/threads/{$thread->id}/messages");
    $resBob->assertStatus(200);

    // last_delivered_at de Bob est mis à jour
    expect($bobMember->fresh()->last_delivered_at->toDateTimeString())->toBe('2026-10-26 14:02:00');

    // Maintenant Alice regarde le fil : le message est 'delivered' (double coche grise)
    Passport::actingAs($this->alice);
    $resAlice2 = $this->getJson("/api/messages/threads/{$thread->id}/messages");
    $resAlice2->assertStatus(200);
    $msgAlice2 = $resAlice2->json('data.0');
    expect($msgAlice2['status'])->toBe('delivered');
    expect($msgAlice2['is_delivered'])->toBeTrue();
    expect($msgAlice2['is_read'])->toBeFalse();

    // Bob marque la conversation comme lue
    Carbon::setTestNow('2026-10-26 14:05:00');
    Passport::actingAs($this->bob);
    $this->postJson("/api/messages/threads/{$thread->id}/read")->assertStatus(200);

    // Alice regarde à nouveau : le message est 'read' (double coche bleue)
    Passport::actingAs($this->alice);
    $resAlice3 = $this->getJson("/api/messages/threads/{$thread->id}/messages");
    $msgAlice3 = $resAlice3->json('data.0');
    expect($msgAlice3['status'])->toBe('read');
    expect($msgAlice3['is_delivered'])->toBeTrue();
    expect($msgAlice3['is_read'])->toBeTrue();
    expect($msgAlice3['read_at'])->not->toBeNull();
});

test('endpoint explicite markAsDelivered met a jour last_delivered_at', function (): void {
    Carbon::setTestNow('2026-10-26 14:10:00');

    $thread = Conversation::factory()->create(['created_by_id' => $this->alice->id]);
    ConversationMember::create(['conversation_id' => $thread->id, 'user_id' => $this->alice->id]);
    $bobMember = ConversationMember::create(['conversation_id' => $thread->id, 'user_id' => $this->bob->id]);

    Passport::actingAs($this->bob);
    $response = $this->postJson("/api/messages/threads/{$thread->id}/delivered");
    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    expect($bobMember->fresh()->last_delivered_at->toDateTimeString())->toBe('2026-10-26 14:10:00');
});
