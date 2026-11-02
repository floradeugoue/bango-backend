<?php

use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

test('User can pin up to 3 threads, gets 422 beyond, and can unpin', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $conversations = [];
    for ($i = 0; $i < 4; $i++) {
        $conv = Conversation::factory()->create();
        ConversationMember::create([
            'conversation_id' => $conv->id,
            'user_id' => $user->id,
            'role' => 'member',
        ]);
        $conversations[] = $conv;
    }

    // 1. Pin first conversation
    $res1 = $this->postJson("/api/messages/threads/{$conversations[0]->id}/pin");
    $res1->assertOk()
        ->assertJson([
            'success' => true,
            'is_pinned' => true,
        ]);
    expect($res1->json('pinned_at'))->not->toBeNull();

    // 2. Pin second conversation
    $res2 = $this->postJson("/api/messages/threads/{$conversations[1]->id}/pin");
    $res2->assertOk()->assertJson(['success' => true, 'is_pinned' => true]);

    // 3. Pin third conversation
    $res3 = $this->postJson("/api/messages/threads/{$conversations[2]->id}/pin");
    $res3->assertOk()->assertJson(['success' => true, 'is_pinned' => true]);

    // 4. Pin fourth conversation -> 422 Unprocessable Entity
    $res4 = $this->postJson("/api/messages/threads/{$conversations[3]->id}/pin");
    $res4->assertStatus(422)
        ->assertJsonValidationErrors(['pinned']);

    // 5. Unpin first conversation
    $resUnpin = $this->postJson("/api/messages/threads/{$conversations[0]->id}/unpin");
    $resUnpin->assertOk()
        ->assertJson([
            'success' => true,
            'is_pinned' => false,
            'pinned_at' => null,
        ]);

    // 6. Now fourth conversation can be pinned
    $resRetry = $this->postJson("/api/messages/threads/{$conversations[3]->id}/pin");
    $resRetry->assertOk()->assertJson(['success' => true, 'is_pinned' => true]);
});

test('ConversationResource returns pinned_at and list is sorted with pinned threads first', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // Create 3 conversations with different dates
    $convOlder = Conversation::factory()->create(['created_at' => now()->subDays(3), 'last_message_at' => now()->subDays(3)]);
    $convMid = Conversation::factory()->create(['created_at' => now()->subDays(2), 'last_message_at' => now()->subDays(2)]);
    $convNewer = Conversation::factory()->create(['created_at' => now()->subHours(1), 'last_message_at' => now()->subHours(1)]);

    foreach ([$convOlder, $convMid, $convNewer] as $conv) {
        ConversationMember::create([
            'conversation_id' => $conv->id,
            'user_id' => $user->id,
            'role' => 'member',
        ]);
    }

    // Pin the older conversation
    $this->postJson("/api/messages/threads/{$convOlder->id}/pin")->assertOk();

    // Get list of threads
    $res = $this->getJson('/api/messages/threads');
    $res->assertOk();

    $data = $res->json('data');
    expect($data)->toHaveCount(3);

    // Older pinned thread must come FIRST despite its older date
    expect($data[0]['id'])->toBe($convOlder->id);
    expect($data[0]['is_pinned'])->toBeTrue();
    expect($data[0]['pinned_at'])->not->toBeNull();

    // Second is newer unpinned thread
    expect($data[1]['id'])->toBe($convNewer->id);
    expect($data[1]['is_pinned'])->toBeFalse();
    expect($data[1]['pinned_at'])->toBeNull();

    // Third is mid unpinned thread
    expect($data[2]['id'])->toBe($convMid->id);
    expect($data[2]['is_pinned'])->toBeFalse();
});
