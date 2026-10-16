<?php

use App\Models\Auth\OAuthAccount;
use App\Models\User;
use App\Services\Auth\AppleTokenVerifier;
use App\Services\Auth\GoogleTokenVerifier;
use App\Services\Auth\JwtVerifierHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

function generateTestRsaKeyPair(): array
{
    $options = [
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];

    if (file_exists('C:/tools/php84/extras/ssl/openssl.cnf')) {
        $options['config'] = 'C:/tools/php84/extras/ssl/openssl.cnf';
    }

    $res = openssl_pkey_new($options);
    openssl_pkey_export($res, $privateKey, null, $options);
    $details = openssl_pkey_get_details($res);

    return [
        'private' => $privateKey,
        'public' => $details['key'],
        'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
        'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
    ];
}

function forgeTestJwt(array $payload, string $privateKey, array $header = ['alg' => 'RS256', 'kid' => 'test-key-1']): string
{
    $headerB64 = JwtVerifierHelper::base64UrlEncode(json_encode($header));
    $payloadB64 = JwtVerifierHelper::base64UrlEncode(json_encode($payload));
    $dataToSign = "{$headerB64}.{$payloadB64}";

    openssl_sign($dataToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $signatureB64 = JwtVerifierHelper::base64UrlEncode($signature);

    return "{$headerB64}.{$payloadB64}.{$signatureB64}";
}

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);

    $this->keys = generateTestRsaKeyPair();

    GoogleTokenVerifier::setKeysProvider(fn () => $this->keys['public']);
    AppleTokenVerifier::setKeysProvider(fn () => $this->keys['public']);

    Config::set('services.google.client_id', 'test-google-client-id');
    Config::set('services.google.client_ids', ['test-google-client-id']);

    Config::set('services.apple.client_id', 'com.bango.app');
    Config::set('services.apple.client_ids', ['com.bango.app']);
});

afterEach(function () {
    GoogleTokenVerifier::setKeysProvider(null);
    AppleTokenVerifier::setKeysProvider(null);
});

test('it creates a new user on first valid Google Sign-In', function () {
    $token = forgeTestJwt([
        'iss' => 'https://accounts.google.com',
        'aud' => 'test-google-client-id',
        'sub' => 'google-user-12345',
        'email' => 'newuser@gmail.com',
        'email_verified' => true,
        'name' => 'Kofi Annan',
        'picture' => 'https://lh3.googleusercontent.com/photo.jpg',
        'exp' => time() + 3600,
    ], $this->keys['private']);

    $response = $this->postJson('/api/auth/provider', [
        'provider' => 'google',
        'id_token' => $token,
    ]);

    $response->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonPath('user.email', 'newuser@gmail.com')
        ->assertJsonPath('user.display_name', 'Kofi Annan')
        ->assertJsonStructure(['token', 'user', 'created', 'requires_profile_completion']);

    $this->assertDatabaseHas('users', ['email' => 'newuser@gmail.com']);
    $this->assertDatabaseHas('oauth_accounts', [
        'provider' => 'google',
        'provider_user_id' => 'google-user-12345',
    ]);
});

test('it logs in an existing user already linked to Google without re-creating', function () {
    $user = User::factory()->create([
        'email' => 'kofi@gmail.com',
        'handle' => 'kofi.annan',
        'phone_verified_at' => now(),
    ]);

    OAuthAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-user-12345',
        'email' => 'kofi@gmail.com',
    ]);

    $token = forgeTestJwt([
        'iss' => 'https://accounts.google.com',
        'aud' => 'test-google-client-id',
        'sub' => 'google-user-12345',
        'email' => 'kofi@gmail.com',
        'exp' => time() + 3600,
    ], $this->keys['private']);

    $response = $this->postJson('/api/auth/provider', [
        'provider' => 'google',
        'id_token' => $token,
    ]);

    $response->assertOk()
        ->assertJsonPath('created', false)
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('requires_profile_completion', false);
});

test('it creates a new user with Apple Sign In and handles apple relay email', function () {
    $token = forgeTestJwt([
        'iss' => 'https://appleid.apple.com',
        'aud' => 'com.bango.app',
        'sub' => 'apple-sub-998877',
        'email' => 'anon123@privaterelay.appleid.com',
        'email_verified' => 'true',
        'exp' => time() + 3600,
    ], $this->keys['private']);

    $response = $this->postJson('/api/auth/provider', [
        'provider' => 'apple',
        'id_token' => $token,
        'name' => 'Jeanne Moreau',
    ]);

    $response->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonPath('user.email', 'anon123@privaterelay.appleid.com')
        ->assertJsonPath('user.display_name', 'Jeanne Moreau');

    $this->assertDatabaseHas('oauth_accounts', [
        'provider' => 'apple',
        'provider_user_id' => 'apple-sub-998877',
    ]);
});

test('it rejects an invalid token signature', function () {
    $otherKeys = generateTestRsaKeyPair();

    // Signed with otherKeys private key, while verifier checks with this->keys public key
    $token = forgeTestJwt([
        'iss' => 'https://accounts.google.com',
        'aud' => 'test-google-client-id',
        'sub' => 'google-user-123',
        'exp' => time() + 3600,
    ], $otherKeys['private']);

    $response = $this->postJson('/api/auth/provider', [
        'provider' => 'google',
        'id_token' => $token,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('kind', 'invalid_signature');
});

test('it rejects an expired token', function () {
    $token = forgeTestJwt([
        'iss' => 'https://accounts.google.com',
        'aud' => 'test-google-client-id',
        'sub' => 'google-user-123',
        'exp' => time() - 300, // 5 minutes ago
    ], $this->keys['private']);

    $response = $this->postJson('/api/auth/provider', [
        'provider' => 'google',
        'id_token' => $token,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('kind', 'expired_token');
});

test('it rejects an incorrect audience', function () {
    $token = forgeTestJwt([
        'iss' => 'https://accounts.google.com',
        'aud' => 'wrong-client-id.apps.googleusercontent.com',
        'sub' => 'google-user-123',
        'exp' => time() + 3600,
    ], $this->keys['private']);

    $response = $this->postJson('/api/auth/provider', [
        'provider' => 'google',
        'id_token' => $token,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('kind', 'invalid_audience');
});

test('it rejects when expected nonce does not match token claim', function () {
    $token = forgeTestJwt([
        'iss' => 'https://accounts.google.com',
        'aud' => 'test-google-client-id',
        'sub' => 'google-user-123',
        'nonce' => 'token-nonce-abc',
        'exp' => time() + 3600,
    ], $this->keys['private']);

    $response = $this->postJson('/api/auth/provider', [
        'provider' => 'google',
        'id_token' => $token,
        'nonce' => 'different-nonce-xyz',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('kind', 'invalid_nonce');
});

test('it rejects an unknown provider with 422', function () {
    $response = $this->postJson('/api/auth/provider', [
        'provider' => 'facebook',
        'id_token' => 'dummy',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['provider']);
});

test('it refuses to silently auto-merge when email belongs to an existing unlinked account', function () {
    User::factory()->create(['email' => 'existing@bango.app']);

    $token = forgeTestJwt([
        'iss' => 'https://accounts.google.com',
        'aud' => 'test-google-client-id',
        'sub' => 'google-user-conflict',
        'email' => 'existing@bango.app',
        'exp' => time() + 3600,
    ], $this->keys['private']);

    $response = $this->postJson('/api/auth/provider', [
        'provider' => 'google',
        'id_token' => $token,
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('kind', 'account_exists_unlinked');
});

test('it allows an authenticated user to explicitly link a social provider', function () {
    $user = User::factory()->create();

    $token = forgeTestJwt([
        'iss' => 'https://accounts.google.com',
        'aud' => 'test-google-client-id',
        'sub' => 'google-sub-to-link',
        'email' => 'linked@gmail.com',
        'exp' => time() + 3600,
    ], $this->keys['private']);

    $response = $this->actingAs($user, 'api')->postJson('/api/auth/provider', [
        'provider' => 'google',
        'id_token' => $token,
        'action' => 'link',
    ]);

    $response->assertOk()
        ->assertJsonPath('linked', true);

    $this->assertDatabaseHas('oauth_accounts', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-sub-to-link',
    ]);
});

test('it rejects linking when provider account is already linked to someone else', function () {
    $otherUser = User::factory()->create();
    OAuthAccount::create([
        'user_id' => $otherUser->id,
        'provider' => 'google',
        'provider_user_id' => 'google-taken-sub',
    ]);

    $currentUser = User::factory()->create();

    $token = forgeTestJwt([
        'iss' => 'https://accounts.google.com',
        'aud' => 'test-google-client-id',
        'sub' => 'google-taken-sub',
        'exp' => time() + 3600,
    ], $this->keys['private']);

    $response = $this->actingAs($currentUser, 'api')->postJson('/api/auth/provider', [
        'provider' => 'google',
        'id_token' => $token,
        'action' => 'link',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('kind', 'account_already_linked');
});

test('it handles account with 2FA by issuing second-factor challenge instead of direct token', function () {
    $user = User::factory()->create([
        'email' => 'twofactor@bango.app',
        'two_factor_method' => 'email',
    ]);

    OAuthAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-2fa-user',
        'email' => 'twofactor@bango.app',
    ]);

    $token = forgeTestJwt([
        'iss' => 'https://accounts.google.com',
        'aud' => 'test-google-client-id',
        'sub' => 'google-2fa-user',
        'email' => 'twofactor@bango.app',
        'exp' => time() + 3600,
    ], $this->keys['private']);

    $response = $this->postJson('/api/auth/provider', [
        'provider' => 'google',
        'id_token' => $token,
    ]);

    $response->assertOk()
        ->assertJsonPath('kind', 'second-factor')
        ->assertJsonStructure(['kind', 'destination', 'challenge'])
        ->assertJsonMissing(['token']);
});
