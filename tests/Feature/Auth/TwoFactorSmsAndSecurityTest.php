<?php

use App\Models\Auth\Otp;
use App\Models\User;
use App\Services\Auth\TotpService;
use App\Services\Otp\OtpEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test Client', '--no-interaction' => true]);
    RateLimiter::clear('signin.*');
});

test('1. Connexion sans 2FA : délivre directement le token Passport sans envoyer de SMS', function (): void {
    Http::fake();

    $user = User::factory()->create([
        'email' => 'direct.user@bango.africa',
        'password' => Hash::make('Password123!'),
        'phone' => '+237699000001',
        'two_factor_method' => 'off',
    ]);

    $response = $this->postJson('/api/auth/signin', [
        'identifier' => 'direct.user@bango.africa',
        'password' => 'Password123!',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'email', 'handle']])
        ->assertJsonMissing(['kind' => 'second-factor']);

    Http::assertNothingSent();
});

test('2. Connexion avec 2FA SMS : déclenche le challenge et envoie le SMS via Zomloa', function (): void {
    $sentSmsBody = null;
    $sentPhone = null;

    Http::fake([
        '*api.sms.zomloa.app*' => function ($request) use (&$sentSmsBody, &$sentPhone) {
            $sentSmsBody = $request['sms'] ?? '';
            $sentPhone = $request['mobiles'] ?? '';

            return Http::response(['success' => true, 'responsecode' => '200'], 200);
        },
    ]);

    $user = User::factory()->create([
        'email' => 'sms.user@bango.africa',
        'password' => Hash::make('Password123!'),
        'phone' => '+237699112233',
        'two_factor_method' => 'sms',
    ]);

    $response = $this->postJson('/api/auth/signin', [
        'identifier' => 'sms.user@bango.africa',
        'password' => 'Password123!',
    ]);

    $response->assertOk()
        ->assertJsonPath('kind', 'second-factor')
        ->assertJsonStructure(['kind', 'destination', 'challenge'])
        ->assertJsonPath('destination', '+237 *******33');

    $challengeId = $response->json('challenge');
    expect($challengeId)->toBeString()
        ->and(strlen($challengeId))->toBe(40);

    // Vérifier l'envoi effectif via la passerelle Zomloa
    expect($sentPhone)->toBe('237699112233')
        ->and($sentSmsBody)->toContain('Votre code BANGO est :');

    // Vérifier l'enregistrement OTP en BDD
    $otpRecord = Otp::where('identifier', '+237699112233')
        ->where('channel', 'sms')
        ->where('context', 'second_factor')
        ->first();

    expect($otpRecord)->not->toBeNull();
});

test('3. Validation du code SMS réussie : délivrance du token Passport et invalidation du challenge', function (): void {
    $smsCode = null;

    Http::fake([
        '*api.sms.zomloa.app*' => function ($request) use (&$smsCode) {
            if (preg_match('/(\d{6})/', $request['sms'] ?? '', $matches)) {
                $smsCode = $matches[1];
            }

            return Http::response(['success' => true, 'responsecode' => '200'], 200);
        },
    ]);

    $user = User::factory()->create([
        'email' => 'sms.val@bango.africa',
        'password' => Hash::make('Password123!'),
        'phone' => '+237699223344',
        'two_factor_method' => 'sms',
    ]);

    $signinResponse = $this->postJson('/api/auth/signin', [
        'identifier' => 'sms.val@bango.africa',
        'password' => 'Password123!',
    ]);

    $challengeId = $signinResponse->json('challenge');
    expect($smsCode)->not->toBeNull();

    // Soumission du second facteur valide
    $verifyResponse = $this->postJson('/api/auth/signin/second-factor', [
        'challenge' => $challengeId,
        'code' => $smsCode,
    ]);

    $verifyResponse->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'email', 'handle']])
        ->assertJsonPath('user.id', $user->id);

    // Tentative de réutilisation du même challenge (doit être rejetée immédiatement)
    $replayResponse = $this->postJson('/api/auth/signin/second-factor', [
        'challenge' => $challengeId,
        'code' => $smsCode,
    ]);

    $replayResponse->assertStatus(422)
        ->assertJsonPath('kind', 'challenge-expired');
});

test('4. Rejet d\'un OTP SMS incorrect avec décompte des tentatives', function (): void {
    Http::fake([
        '*api.sms.zomloa.app*' => Http::response(['success' => true, 'responsecode' => '200'], 200),
    ]);

    $user = User::factory()->create([
        'email' => 'sms.bad@bango.africa',
        'password' => Hash::make('Password123!'),
        'phone' => '+237699334455',
        'two_factor_method' => 'sms',
    ]);

    $signinResponse = $this->postJson('/api/auth/signin', [
        'identifier' => 'sms.bad@bango.africa',
        'password' => 'Password123!',
    ]);

    $challengeId = $signinResponse->json('challenge');

    $badVerifyResponse = $this->postJson('/api/auth/signin/second-factor', [
        'challenge' => $challengeId,
        'code' => '000000',
    ]);

    $badVerifyResponse->assertStatus(422)
        ->assertJsonPath('kind', 'invalid');
});

test('5. Rejet d\'un challenge expiré ou inexistant', function (): void {
    $response = $this->postJson('/api/auth/signin/second-factor', [
        'challenge' => 'challenge-inexistant-ou-expire',
        'code' => '123456',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('kind', 'challenge-expired');
});

test('6. Limitation des tentatives sur SecondFactorController (blocage HTTP 429 après 5 échecs)', function (): void {
    Http::fake([
        '*api.sms.zomloa.app*' => Http::response(['success' => true, 'responsecode' => '200'], 200),
    ]);

    $user = User::factory()->create([
        'email' => 'sms.lock@bango.africa',
        'password' => Hash::make('Password123!'),
        'phone' => '+237699445566',
        'two_factor_method' => 'sms',
    ]);

    $signinResponse = $this->postJson('/api/auth/signin', [
        'identifier' => 'sms.lock@bango.africa',
        'password' => 'Password123!',
    ]);

    $challengeId = $signinResponse->json('challenge');

    // 4 tentatives erronées (422)
    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/api/auth/signin/second-factor', [
            'challenge' => $challengeId,
            'code' => '00000'.$i,
        ])->assertStatus(422);
    }

    // 5ème tentative : dépassement du seuil -> HTTP 429 locked
    $fifthResponse = $this->postJson('/api/auth/signin/second-factor', [
        'challenge' => $challengeId,
        'code' => '999999',
    ]);

    $fifthResponse->assertStatus(429)
        ->assertJsonPath('kind', 'locked');

    // Le challenge a été détruit
    expect(Cache::has('2fa.challenge.'.$challengeId))->toBeFalse();
});

test('7. Prévention du contournement des limites en recréant des challenges consécutivement', function (): void {
    Http::fake([
        '*api.sms.zomloa.app*' => Http::response(['success' => true, 'responsecode' => '200'], 200),
    ]);

    $user = User::factory()->create([
        'email' => 'sms.bypass@bango.africa',
        'password' => Hash::make('Password123!'),
        'phone' => '+237699556677',
        'two_factor_method' => 'sms',
    ]);

    // Simule 10 tentatives échouées réparties sur plusieurs challenges pour le même compte
    $userRateKey = '2fa.attempts.user:'.$user->id;
    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit($userRateKey, 900);
    }

    // Un nouveau challenge est créé
    $challengeId = 'bypass-test-challenge-'.uniqid();
    Cache::put('2fa.challenge.'.$challengeId, [
        'user_id' => $user->id,
        'channel' => 'sms',
        'destination' => $user->phone,
        'persistent' => false,
    ], now()->addMinutes(10));

    // Toute nouvelle soumission doit être verrouillée en 429 par le limiteur de compte
    $response = $this->postJson('/api/auth/signin/second-factor', [
        'challenge' => $challengeId,
        'code' => '123456',
    ]);

    $response->assertStatus(429)
        ->assertJsonPath('kind', 'locked');
});

test('8. Gestion de panne du fournisseur SMS : retour 503 sans bloquer le système', function (): void {
    Http::fake([
        '*api.sms.zomloa.app*' => Http::response(['error' => 'Gateway timeout'], 503),
    ]);

    $user = User::factory()->create([
        'email' => 'sms.down@bango.africa',
        'password' => Hash::make('Password123!'),
        'phone' => '+237699667788',
        'two_factor_method' => 'sms',
    ]);

    $response = $this->postJson('/api/auth/signin', [
        'identifier' => 'sms.down@bango.africa',
        'password' => 'Password123!',
    ]);

    $response->assertStatus(503)
        ->assertJsonPath('message', 'Service indisponible. Veuillez réessayer plus tard.');
});

test('9. Non-régression TOTP : connexion avec méthode app ne déclenche aucun SMS et accepte le code TOTP', function (): void {
    Http::fake();

    $totpService = app(TotpService::class);
    $secret = $totpService->generateSecret();

    $user = User::factory()->create([
        'email' => 'totp.user@bango.africa',
        'password' => Hash::make('Password123!'),
        'phone' => '+237699778899',
        'two_factor_method' => 'app',
        'two_factor_secret' => Crypt::encryptString($secret),
    ]);

    // 1. Signin
    $response = $this->postJson('/api/auth/signin', [
        'identifier' => 'totp.user@bango.africa',
        'password' => 'Password123!',
    ]);

    $response->assertOk()
        ->assertJsonPath('kind', 'second-factor')
        ->assertJsonPath('destination', 'Application d\'authentification');

    // Aucun SMS n'a été envoyé
    Http::assertNothingSent();

    $challengeId = $response->json('challenge');

    // 2. Validation avec code TOTP calculé
    $step = (int) floor(time() / 30);
    $validTotp = $totpService->calculateTotpForStep($secret, $step);

    $verifyResponse = $this->postJson('/api/auth/signin/second-factor', [
        'challenge' => $challengeId,
        'code' => $validTotp,
    ]);

    $verifyResponse->assertOk()
        ->assertJsonStructure(['token', 'user'])
        ->assertJsonPath('user.id', $user->id);
});

test('10. Non-régression Email 2FA : envoi OTP par email et validation réussie', function (): void {
    $sentEmailCode = null;
    $emailMock = Mockery::mock(OtpEmailService::class);
    $emailMock->shouldReceive('send')->once()->andReturnUsing(function ($to, $code) use (&$sentEmailCode) {
        $sentEmailCode = $code;
    });
    app()->instance(OtpEmailService::class, $emailMock);

    $user = User::factory()->create([
        'email' => 'email.2fa@bango.africa',
        'password' => Hash::make('Password123!'),
        'two_factor_method' => 'email',
    ]);

    $response = $this->postJson('/api/auth/signin', [
        'identifier' => 'email.2fa@bango.africa',
        'password' => 'Password123!',
    ]);

    $response->assertOk()
        ->assertJsonPath('kind', 'second-factor')
        ->assertJsonPath('destination', 'e*******a@bango.africa');

    $challengeId = $response->json('challenge');
    expect($sentEmailCode)->not->toBeNull();

    $verifyResponse = $this->postJson('/api/auth/signin/second-factor', [
        'challenge' => $challengeId,
        'code' => $sentEmailCode,
    ]);

    $verifyResponse->assertOk()
        ->assertJsonStructure(['token', 'user']);
});

test('11. Limitation des tentatives sur TwoFactorTotpController (confirmation d\'activation)', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $totpService = app(TotpService::class);
    $secret = $totpService->generateSecret();
    Cache::put("2fa_pending_secret:{$user->id}", $secret, now()->addMinutes(15));

    // 4 essais infructueux
    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/api/settings/2fa/totp/verify', [
            'code' => '00000'.$i,
        ])->assertStatus(422)
            ->assertJsonPath('kind', 'invalid-code');
    }

    // 5ème échec : seuil atteint -> 429 locked et annulation du secret
    $lockedResponse = $this->postJson('/api/settings/2fa/totp/verify', [
        'code' => '999999',
    ]);

    $lockedResponse->assertStatus(429)
        ->assertJsonPath('kind', 'locked');

    expect(Cache::has("2fa_pending_secret:{$user->id}"))->toBeFalse();
});
