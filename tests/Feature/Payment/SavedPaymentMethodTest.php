<?php

use App\Models\Payment\SavedPaymentMethod;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('rejects unauthenticated requests on saved payment methods endpoints', function () {
    getJson('/api/payments/methods')->assertStatus(401);
    postJson('/api/payments/methods', [])->assertStatus(401);
    patchJson('/api/payments/methods/1/default')->assertStatus(401);
    deleteJson('/api/payments/methods/1')->assertStatus(401);
});

it('allows a user to store a valid mobile money payment method without triggering any transaction or debit', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $response = postJson('/api/payments/methods', [
        'operator' => 'mtn',
        'phone_number' => '+237 671 23 45 67',
        'is_default' => true,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.operator', 'mtn')
        ->assertJsonPath('data.is_default', true)
        ->assertJsonStructure([
            'data' => [
                'id',
                'operator',
                'phone_number',
                'is_default',
                'created_at',
            ],
            'message',
        ]);

    assertDatabaseHas('saved_payment_methods', [
        'user_id' => $user->id,
        'operator' => 'mtn',
        'phone_number' => '+237671234567',
        'is_default' => true,
    ]);

    // Critical check: no payment transaction or debit must be created
    assertDatabaseCount('payment_transactions', 0);
});

it('validates operator and phone number format', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // Unsupported operator
    postJson('/api/payments/methods', [
        'operator' => 'bitcoin',
        'phone_number' => '+237 671 23 45 67',
    ])->assertStatus(422)->assertJsonValidationErrors(['operator']);

    // Invalid phone number
    postJson('/api/payments/methods', [
        'operator' => 'orange',
        'phone_number' => 'abc',
    ])->assertStatus(422)->assertJsonValidationErrors(['phone_number']);
});

it('lists only the saved payment methods of the authenticated user', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    SavedPaymentMethod::create([
        'user_id' => $user1->id,
        'operator' => 'mtn',
        'phone_number' => '+237671234567',
        'is_default' => true,
    ]);

    SavedPaymentMethod::create([
        'user_id' => $user2->id,
        'operator' => 'orange',
        'phone_number' => '+237699887766',
        'is_default' => true,
    ]);

    Passport::actingAs($user1);

    $response = getJson('/api/payments/methods');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.operator', 'mtn');
});

it('maintains a single default payment method per user', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $method1 = SavedPaymentMethod::create([
        'user_id' => $user->id,
        'operator' => 'mtn',
        'phone_number' => '+237671111111',
        'is_default' => true,
    ]);

    $method2 = SavedPaymentMethod::create([
        'user_id' => $user->id,
        'operator' => 'orange',
        'phone_number' => '+237699222222',
        'is_default' => false,
    ]);

    // Set method2 as default
    $response = patchJson("/api/payments/methods/{$method2->id}/default");
    $response->assertStatus(200)
        ->assertJsonPath('data.is_default', true);

    expect($method1->fresh()->is_default)->toBeFalse();
    expect($method2->fresh()->is_default)->toBeTrue();
});

it('allows the owner to delete their saved payment method and reassigns default if necessary', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $method1 = SavedPaymentMethod::create([
        'user_id' => $user->id,
        'operator' => 'mtn',
        'phone_number' => '+237671111111',
        'is_default' => true,
    ]);

    $method2 = SavedPaymentMethod::create([
        'user_id' => $user->id,
        'operator' => 'orange',
        'phone_number' => '+237699222222',
        'is_default' => false,
    ]);

    $response = deleteJson("/api/payments/methods/{$method1->id}");
    $response->assertStatus(200);

    assertDatabaseMissing('saved_payment_methods', ['id' => $method1->id]);
    expect($method2->fresh()->is_default)->toBeTrue();
});

it('forbids deleting or updating another user payment method', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $method = SavedPaymentMethod::create([
        'user_id' => $user1->id,
        'operator' => 'mtn',
        'phone_number' => '+237671111111',
        'is_default' => true,
    ]);

    Passport::actingAs($user2);

    deleteJson("/api/payments/methods/{$method->id}")->assertStatus(403);
    patchJson("/api/payments/methods/{$method->id}/default")->assertStatus(403);
});
