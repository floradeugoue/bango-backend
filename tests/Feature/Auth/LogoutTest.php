<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('successfully logs out the user and revokes the token', function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Laravel Personal Access Client', '--no-interaction' => true]);

    $user = User::factory()->create();
    $tokenResult = $user->createToken('TestToken');
    $token = $tokenResult->accessToken;

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/api/auth/logout');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Déconnexion réussie.',
        ]);

    $this->assertDatabaseHas('oauth_access_tokens', [
        'id' => $tokenResult->token->id,
        'revoked' => true,
    ]);
});

it('cannot access protected routes after logout', function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Laravel Personal Access Client', '--no-interaction' => true]);

    $user = User::factory()->create();
    $tokenResult = $user->createToken('TestToken');
    $token = $tokenResult->accessToken;

    // Logout
    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/api/auth/logout');

    // Trying to access a protected route with the revoked token
    auth()->forgetGuards();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->getJson('/api/profile');

    $response->assertStatus(401);
});

it('fails to logout without authentication', function () {
    $response = $this->postJson('/api/auth/logout');

    $response->assertStatus(401);
});

it('fails to logout with invalid token', function () {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer invalid-token-string',
    ])->postJson('/api/auth/logout');

    $response->assertStatus(401);
});
