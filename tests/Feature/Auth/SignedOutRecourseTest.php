<?php

use App\Models\Support\ModerationAppeal;
use App\Models\Support\SupportTicket;
use App\Models\Support\UserAccountSanction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

function suspendedAccountWithSanction(): array
{
    $operator = User::factory()->create();
    $user = User::factory()->create([
        'email' => 'suspendu@example.com',
        'password' => bcrypt('Password123'),
        'status' => 'suspended',
        'suspension_reason' => 'Annonces trompeuses',
        'suspended_from' => now()->subDay(),
        'suspended_until' => now()->addDays(6),
    ]);

    $sanction = UserAccountSanction::create([
        'user_id' => $user->id,
        'operator_id' => $operator->id,
        'type' => 'suspension',
        'duration_days' => 7,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(6),
        'reason' => 'Annonces trompeuses',
        'is_active' => true,
    ]);

    return [$user, $sanction, $operator];
}

it('exposes the active suspension id in account-state', function () {
    [, $sanction] = suspendedAccountWithSanction();

    postJson('/api/auth/account-state', [
        'identifier' => 'suspendu@example.com',
        'password' => 'Password123',
    ])->assertOk()->assertJsonPath('suspension.id', $sanction->id);
});

it('lets a suspended account file an appeal without a session', function () {
    [$user, $sanction, $operator] = suspendedAccountWithSanction();

    $response = postJson('/api/auth/appeal', [
        'identifier' => 'suspendu@example.com',
        'password' => 'Password123',
        'reason' => 'Erreur de modération',
        'explanation' => 'Mes annonces décrivent fidèlement les biens proposés.',
    ]);

    $response->assertCreated()->assertJsonStructure(['id', 'reference', 'created_at']);

    $appeal = ModerationAppeal::findOrFail($response->json('id'));
    expect($appeal->user_id)->toBe($user->id)
        ->and($appeal->sanction_type)->toBe('account_suspension')
        ->and($appeal->sanction_reference)->toBe((string) $sanction->id)
        ->and($appeal->sanction_operator_id)->toBe($operator->id)
        ->and($appeal->reference)->toBe($response->json('reference'));
});

it('refuses a second pending appeal for the same suspension', function () {
    suspendedAccountWithSanction();
    $payload = [
        'identifier' => 'suspendu@example.com',
        'password' => 'Password123',
        'reason' => 'Erreur de modération',
        'explanation' => 'Mes annonces décrivent fidèlement les biens proposés.',
    ];

    $first = postJson('/api/auth/appeal', $payload)->assertCreated();

    postJson('/api/auth/appeal', $payload)
        ->assertStatus(409)
        ->assertJsonPath('kind', 'already-pending')
        ->assertJsonPath('reference', $first->json('reference'));
});

it('rejects an appeal with a wrong password', function () {
    suspendedAccountWithSanction();

    postJson('/api/auth/appeal', [
        'identifier' => 'suspendu@example.com',
        'password' => 'WrongPassword',
        'reason' => 'Erreur',
        'explanation' => 'Explication suffisamment longue.',
    ])->assertUnauthorized()->assertJsonPath('kind', 'invalid-credentials');

    expect(ModerationAppeal::count())->toBe(0);
});

it('locks the signed-out endpoints after repeated wrong passwords', function () {
    suspendedAccountWithSanction();

    foreach (range(1, 5) as $attempt) {
        postJson('/api/auth/account-state', [
            'identifier' => 'suspendu@example.com',
            'password' => 'WrongPassword',
        ])->assertUnauthorized();
    }

    postJson('/api/auth/appeal', [
        'identifier' => 'suspendu@example.com',
        'password' => 'Password123',
        'reason' => 'Erreur',
        'explanation' => 'Explication suffisamment longue.',
    ])->assertStatus(429)->assertJsonPath('kind', 'locked');
});

it('refuses an appeal from an account that is not suspended', function () {
    User::factory()->create([
        'email' => 'actif@example.com',
        'password' => bcrypt('Password123'),
        'status' => 'active',
    ]);

    postJson('/api/auth/appeal', [
        'identifier' => 'actif@example.com',
        'password' => 'Password123',
        'reason' => 'Erreur',
        'explanation' => 'Explication suffisamment longue.',
    ])->assertStatus(422)->assertJsonPath('kind', 'not-suspended');
});

it('lets a deleted account contact support without a session', function () {
    $user = User::factory()->create([
        'email' => 'supprime@example.com',
        'password' => bcrypt('Password123'),
    ]);
    $user->delete();

    $response = postJson('/api/auth/support-ticket', [
        'identifier' => 'supprime@example.com',
        'password' => 'Password123',
        'topic' => 'account_auth',
        'subject' => 'Récupérer mes données',
        'body' => 'Je souhaite récupérer mes conversations avant la suppression.',
    ]);

    $response->assertCreated()->assertJsonStructure(['id', 'reference', 'created_at']);

    $ticket = SupportTicket::findOrFail($response->json('id'));
    expect($ticket->user_id)->toBe($user->id)
        ->and($ticket->category->value ?? $ticket->category)->toBe('account_auth')
        ->and($ticket->user?->id)->toBe($user->id);
});

it('lets a suspended account contact support without a session', function () {
    suspendedAccountWithSanction();

    postJson('/api/auth/support-ticket', [
        'identifier' => 'suspendu@example.com',
        'password' => 'Password123',
        'topic' => 'other',
        'subject' => 'Question sur ma suspension',
        'body' => 'Combien de temps dure l\'examen du recours ?',
    ])->assertCreated();
});

it('sends active accounts back to the authenticated support route', function () {
    User::factory()->create([
        'email' => 'actif@example.com',
        'password' => bcrypt('Password123'),
        'status' => 'active',
    ]);

    postJson('/api/auth/support-ticket', [
        'identifier' => 'actif@example.com',
        'password' => 'Password123',
        'topic' => 'other',
        'subject' => 'Bonjour',
        'body' => 'Une question quelconque.',
    ])->assertForbidden()->assertJsonPath('kind', 'active-account');

    expect(SupportTicket::count())->toBe(0);
});
