<?php

use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\User;
use App\Services\Otp\OtpEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('enforces a maximum of 5 active device sessions at signin, revoking the least recently used', function () {
    $user = User::factory()->create([
        'email' => 'marie@example.com',
        'password' => bcrypt('Secret123!'),
        'city' => 'Douala',
    ]);

    // Création de 5 sessions actives avec des dates d'utilisation échelonnées
    $token1 = $user->createToken('Galaxy A51');
    $token1->token->forceFill([
        'created_at' => now()->subDays(5),
        'updated_at' => now()->subHours(10), // Le moins récemment utilisé
    ])->save();

    $token2 = $user->createToken('Galaxy A52');
    $token2->token->forceFill([
        'created_at' => now()->subDays(4),
        'updated_at' => now()->subHours(8),
    ])->save();

    $token3 = $user->createToken('Galaxy A53');
    $token3->token->forceFill([
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subHours(6),
    ])->save();

    $token4 = $user->createToken('Galaxy A54');
    $token4->token->forceFill([
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subHours(4),
    ])->save();

    $token5 = $user->createToken('Chrome Windows');
    $token5->token->forceFill([
        'created_at' => now()->subDay(),
        'updated_at' => now()->subHours(2), // Le plus récent des 5
    ])->save();

    expect($user->tokens()->where('revoked', false)->count())->toBe(5);

    // Connexion avec un 6e appareil (Galaxy S24)
    $response = $this->postJson('/api/auth/signin', [
        'identifier' => 'marie@example.com',
        'password' => 'Secret123!',
        'device_name' => 'Galaxy S24 - Android 14',
    ], [
        'X-Device-City' => 'Douala',
        'X-Device-Country' => 'Cameroun',
    ]);

    $response->assertStatus(200);

    // Vérification : exactement 5 jetons actifs au maximum
    $activeTokens = $user->tokens()->where('revoked', false)->get();
    expect($activeTokens->count())->toBe(5);

    // Le moins récemment utilisé (token1) doit avoir été révoqué / supprimé
    $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $token1->token->id]);

    // Les autres anciens jetons (2, 3, 4, 5) doivent toujours exister
    $this->assertDatabaseHas('oauth_access_tokens', ['id' => $token2->token->id]);
    $this->assertDatabaseHas('oauth_access_tokens', ['id' => $token3->token->id]);
    $this->assertDatabaseHas('oauth_access_tokens', ['id' => $token4->token->id]);
    $this->assertDatabaseHas('oauth_access_tokens', ['id' => $token5->token->id]);
});

it('enforces maximum 5 active device sessions during second factor flow', function () {
    $user = User::factory()->create([
        'email' => 'marie-2fa@example.com',
        'password' => bcrypt('Secret123!'),
        'two_factor_method' => 'email',
        'city' => 'Douala',
    ]);

    // Création de 5 sessions préalables
    $tokens = [];
    for ($i = 1; $i <= 5; $i++) {
        $t = $user->createToken("Device {$i}");
        $t->token->forceFill([
            'updated_at' => now()->subHours(10 - $i),
        ])->save();
        $tokens[$i] = $t;
    }

    $emailService = Mockery::mock(OtpEmailService::class);
    $sentCode = null;
    $emailService->shouldReceive('send')->once()->andReturnUsing(function ($to, $code) use (&$sentCode) {
        $sentCode = $code;
    });
    $this->app->instance(OtpEmailService::class, $emailService);

    // 1. Signin initial déclenchant le second facteur
    $signinResponse = $this->postJson('/api/auth/signin', [
        'identifier' => 'marie-2fa@example.com',
        'password' => 'Secret123!',
        'device_name' => 'Nouvel iPad',
    ]);

    $signinResponse->assertStatus(200)
        ->assertJsonPath('kind', 'second-factor');

    $challengeId = $signinResponse->json('challenge');

    // 2. Validation du second facteur
    $verifyResponse = $this->postJson('/api/auth/signin/second-factor', [
        'challenge' => $challengeId,
        'code' => $sentCode,
    ]);

    $verifyResponse->assertStatus(200)
        ->assertJsonStructure(['token', 'user']);

    // Plafond maintenu à 5 au plus
    expect($user->tokens()->where('revoked', false)->count())->toBe(5);

    // Le plus ancien (Device 1) a été révoqué
    $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $tokens[1]->token->id]);
});

it('returns detailed session metadata including created_at, ip, and resolved place', function () {
    $currency = Currency::firstOrCreate(['code' => 'XAF'], ['name' => 'Franc CFA', 'symbol' => 'FCFA']);
    $country = Country::firstOrCreate(['code' => 'CM'], ['name' => 'Cameroun', 'iso2' => 'CM', 'iso3' => 'CMR', 'currency_id' => $currency->id]);
    $user = User::factory()->create([
        'city' => 'Douala',
        'country_id' => $country->id,
    ]);

    $t1 = $user->createToken('Galaxy A54::device123');
    $t1->token->forceFill([
        'ip_address' => '102.244.155.10',
        'city' => 'Douala',
        'country' => 'Cameroun',
        'created_at' => Carbon::parse('2026-11-01 10:00:00'),
        'updated_at' => Carbon::parse('2026-11-03 14:00:00'),
    ])->save();

    Passport::actingAs($user);

    $response = $this->getJson('/api/settings/sessions');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'sessions' => [
                '*' => [
                    'id',
                    'device',
                    'current',
                    'ip',
                    'city',
                    'country',
                    'place',
                    'created_at',
                    'last_used_at',
                    'unusual',
                    'acknowledged',
                ],
            ],
            'twoFactor',
            'backupCodesCount',
        ]);

    $session = collect($response->json('sessions'))->firstWhere('id', $t1->token->id);

    expect($session['device'])->toBe('Galaxy A54');
    expect($session['ip'])->toBe('102.244.155.10');
    expect($session['city'])->toBe('Douala');
    expect($session['country'])->toBe('Cameroun');
    expect($session['place'])->toBe('Douala, Cameroun');
    expect($session['place'])->not->toBe('Unknown');
    expect($session['created_at'])->toContain('2026-11-01');
    expect($session['last_used_at'])->toContain('2026-11-03');
    // Même ville et pays habituels : unusual = false même sans acquittement explicite
    expect($session['unusual'])->toBeFalse();
});

it('marks a session as unusual only when its city or country differs from habitual locations, and resets on acknowledgement', function () {
    $currency = Currency::firstOrCreate(['code' => 'XAF'], ['name' => 'Franc CFA', 'symbol' => 'FCFA']);
    $country = Country::firstOrCreate(['code' => 'CM'], ['name' => 'Cameroun', 'iso2' => 'CM', 'iso3' => 'CMR', 'currency_id' => $currency->id]);
    $user = User::factory()->create([
        'city' => 'Douala',
        'country_id' => $country->id,
    ]);

    // Session 1 : Douala, Cameroun (habituel)
    $normalToken = $user->createToken('Téléphone habituel');
    $normalToken->token->forceFill([
        'ip_address' => '102.244.155.1',
        'city' => 'Douala',
        'country' => 'Cameroun',
    ])->save();

    // Session 2 : Tokyo, Japon (inhabituel car ville et pays diffèrent de Douala/Cameroun)
    $unusualToken = $user->createToken('Connexion étrangère');
    $unusualToken->token->forceFill([
        'ip_address' => '133.242.0.1',
        'city' => 'Tokyo',
        'country' => 'Japon',
    ])->save();

    Passport::actingAs($user);

    // 1. Consultation initiale des sessions
    $response = $this->getJson('/api/settings/sessions');
    $response->assertStatus(200);

    $normalSession = collect($response->json('sessions'))->firstWhere('id', $normalToken->token->id);
    $unusualSession = collect($response->json('sessions'))->firstWhere('id', $unusualToken->token->id);

    expect($normalSession['unusual'])->toBeFalse();
    expect($unusualSession['unusual'])->toBeTrue();
    expect($unusualSession['place'])->toBe('Tokyo, Japon');

    // 2. L\'utilisateur valide/reconnaît la session Tokyo
    $ackResponse = $this->postJson("/api/settings/sessions/{$unusualToken->token->id}/acknowledge");
    $ackResponse->assertStatus(200)->assertJson(['success' => true]);

    // 3. Après validation, la session Tokyo n\'est plus inhabituelle
    $responseAfterAck = $this->getJson('/api/settings/sessions');
    $updatedSession = collect($responseAfterAck->json('sessions'))->firstWhere('id', $unusualToken->token->id);

    expect($updatedSession['unusual'])->toBeFalse();
    expect($updatedSession['acknowledged'])->toBeTrue();
});

it('allows disconnecting a specific session via DELETE /settings/sessions/{id}', function () {
    $user = User::factory()->create();
    $token1 = $user->createToken('Appareil Actuel');
    $token2 = $user->createToken('Appareil A Déconnecter');

    Passport::actingAs($user);

    $response = $this->deleteJson('/api/settings/sessions/'.$token2->token->id);
    $response->assertStatus(200)->assertJson(['success' => true]);

    $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $token2->token->id]);
    $this->assertDatabaseHas('oauth_access_tokens', ['id' => $token1->token->id]);
});
