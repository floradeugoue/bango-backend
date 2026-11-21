<?php

namespace Tests\Feature\Security;

use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\User;
use App\Notifications\Auth\UnknownLoginNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('triggers unknown_login notification on login from an unknown device or unusual location (ST-13)', function () {
    Notification::fake();

    $currency = Currency::firstOrCreate(['code' => 'XAF'], ['name' => 'Franc CFA', 'symbol' => 'FCFA']);
    $country = Country::firstOrCreate(['code' => 'CM'], ['name' => 'Cameroun', 'iso2' => 'CM', 'iso3' => 'CMR', 'currency_id' => $currency->id]);

    $user = User::factory()->create([
        'email' => 'jean@example.com',
        'password' => bcrypt('Secret123!'),
        'city' => 'Douala',
        'country_id' => $country->id,
    ]);

    // Session existante préalable (téléphone habituel à Douala)
    $habitualToken = $user->createToken('Galaxy A54::device-habitual');
    $habitualToken->token->forceFill([
        'ip_address' => '102.244.155.1',
        'city' => 'Douala',
        'country' => 'Cameroun',
    ])->save();

    // Connexion depuis un nouvel appareil inconnu à Tokyo
    $response = $this->postJson('/api/auth/signin', [
        'identifier' => 'jean@example.com',
        'password' => 'Secret123!',
        'device_name' => 'iPhone 16 Pro',
        'device_id' => 'device-tokyo-unknown',
    ], [
        'X-Device-City' => 'Tokyo',
        'X-Device-Country' => 'Japon',
    ]);

    $response->assertStatus(200);

    // Vérifier l'envoi de la notification unknown_login
    Notification::assertSentTo($user, UnknownLoginNotification::class, function ($notification) {
        $data = $notification->toArray($notification);

        return $data['type'] === 'unknown_login'
            && ! empty($data['session_id'])
            && $data['device'] === 'iPhone 16 Pro'
            && $data['city'] === 'Tokyo'
            && ! empty($data['time']);
    });
});

it('revokes the session immediately when calling "not-me" (ST-13)', function () {
    $user = User::factory()->create();

    $trustedToken = $user->createToken('Mon Téléphone');
    $suspiciousToken = $user->createToken('Session Suspecte Inconnue');

    Passport::actingAs($user);

    // Appel à la route « Ce n'était pas moi » avec l'ID dans l'URL
    $response = $this->postJson("/api/settings/sessions/{$suspiciousToken->token->id}/not-me");

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'revoked_session_id' => (string) $suspiciousToken->token->id,
        ]);

    // Le jeton suspect doit être révoqué/supprimé de la base
    $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $suspiciousToken->token->id]);

    // Le jeton de confiance est conservé
    $this->assertDatabaseHas('oauth_access_tokens', ['id' => $trustedToken->token->id]);
});

it('revokes the session via POST /api/settings/sessions/not-me with session_id in payload (ST-13)', function () {
    $user = User::factory()->create();

    $trustedToken = $user->createToken('Mon Ordinateur');
    $suspiciousToken = $user->createToken('Session Suspecte Payload');

    Passport::actingAs($user);

    // Appel à la route « Ce n'était pas moi » avec session_id dans le payload JSON
    $response = $this->postJson('/api/settings/sessions/not-me', [
        'session_id' => (string) $suspiciousToken->token->id,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'revoked_session_id' => (string) $suspiciousToken->token->id,
        ]);

    $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $suspiciousToken->token->id]);
    $this->assertDatabaseHas('oauth_access_tokens', ['id' => $trustedToken->token->id]);
});
