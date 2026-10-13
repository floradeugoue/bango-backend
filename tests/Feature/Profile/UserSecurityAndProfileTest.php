<?php

use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Geo\Neighbourhood;
use App\Models\User;
use App\Services\Otp\OtpEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('GET /api/user returns UserResource and hides sensitive security fields', function () {
    $user = User::factory()->create([
        'two_factor_secret' => 'secret123',
        'backup_codes' => ['code1', 'code2'],
        'pending_email_code' => 'hashed_code',
        'bio' => 'Développeur mobile',
        'phone' => '+237699001122',
    ]);

    Passport::actingAs($user);

    $response = getJson('/api/user');

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.bio', 'Développeur mobile')
        ->assertJsonPath('data.phone', '+237699001122')
        ->assertJsonMissing(['two_factor_secret', 'backup_codes', 'pending_email_code', 'pending_email_attempts', 'locked_until']);
});

it('can update interests from the seeded list and fetch public interests', function () {
    $this->artisan('db:seed', ['--class' => 'InterestSeeder']);

    $user = User::factory()->create();
    Passport::actingAs($user);

    $response = putJson('/api/profile/interests', [
        'interests' => ['realestate', 'tech', 'music'],
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);

    $this->assertDatabaseHas('interest_user', [
        'user_id' => $user->id,
        'interest_id' => 'tech',
    ]);

    // Public GET /api/interests
    $publicResponse = getJson('/api/interests');
    $publicResponse->assertStatus(200)->assertJsonCount(10);
});

it('resumes a paused account upon successful signin', function () {
    $user = User::factory()->create([
        'email' => 'paused@example.com',
        'password' => bcrypt('Password123'),
        'status' => 'paused',
    ]);

    $response = postJson('/api/auth/signin', [
        'identifier' => 'paused@example.com',
        'password' => 'Password123',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['token', 'user']);

    $user->refresh();
    expect($user->status)->toBe('active');
});

it('generates a unique handle on signup and returns it', function () {
    $response = postJson('/api/auth/signup', [
        'email' => 'flora.deugoue@example.com',
        'password' => 'Password123',
    ]);

    $response->assertStatus(201);
    $handle = $response->json('user.handle');
    expect($handle)->not->toBeNull();
    expect($handle)->toContain('flora.deugoue');

    // Duplicate email returns existing handle
    $duplicateResponse = postJson('/api/auth/signup', [
        'email' => 'flora.deugoue@example.com',
        'password' => 'Password123',
    ]);

    $duplicateResponse->assertStatus(409)
        ->assertJsonPath('existingHandle', $handle);
});

it('sends an email when requesting email change', function () {
    $emailService = Mockery::mock(OtpEmailService::class);
    $emailService->shouldReceive('send')->once()->with('newmail@example.com', Mockery::type('string'));
    $this->app->instance(OtpEmailService::class, $emailService);

    $user = User::factory()->create([
        'email' => 'oldmail@example.com',
        'password' => bcrypt('Password123'),
    ]);
    Passport::actingAs($user);

    $response = postJson('/api/settings/email/request', [
        'address' => 'newmail@example.com',
        'password' => 'Password123',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('address', 'newmail@example.com');
});

it('allows updating bio and detailed location with city and neighbourhood', function () {
    $currency = Currency::create(['name' => 'Franc CFA', 'code' => 'XAF']);
    $country = Country::create(['name' => 'Cameroun', 'code' => 'CM', 'iso2' => 'CM', 'iso3' => 'CMR', 'currency_id' => $currency->id]);
    $city = City::create(['name' => 'Douala', 'slug' => 'douala', 'country_id' => $country->id]);
    $neighbourhood = Neighbourhood::create(['name' => 'Akwa', 'slug' => 'akwa', 'city_id' => $city->id]);

    $user = User::factory()->create();
    Passport::actingAs($user);

    // Update bio
    $bioResponse = putJson('/api/profile/bio', ['bio' => 'Nouvelle bio utilisateur']);
    $bioResponse->assertStatus(200)->assertJson(['success' => true]);

    // Update location
    $locResponse = putJson('/api/profile/location', [
        'countryCode' => 'CM',
        'cityId' => $city->id,
        'neighbourhoodId' => $neighbourhood->id,
    ]);
    $locResponse->assertStatus(200)->assertJson(['success' => true]);

    $user->refresh();
    expect($user->bio)->toBe('Nouvelle bio utilisateur');
    expect($user->city_id)->toBe($city->id);
    expect($user->neighbourhood_id)->toBe($neighbourhood->id);
});

it('allows user to disconnect a specific session device', function () {
    $user = User::factory()->create();
    $token1 = $user->createToken('iPhone 15');
    $token2 = $user->createToken('Android Pixel');

    Passport::actingAs($user);

    $response = deleteJson('/api/settings/sessions/'.$token2->token->id);
    $response->assertStatus(200)->assertJson(['success' => true]);

    $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $token2->token->id]);
    $this->assertDatabaseHas('oauth_access_tokens', ['id' => $token1->token->id]);
});

it('returns suspended and deletion details via POST /auth/account-state', function () {
    $user = User::factory()->create([
        'email' => 'suspended@example.com',
        'password' => bcrypt('Password123'),
        'handle' => 'suspended_user',
        'display_name' => 'Suspended User',
        'status' => 'suspended',
        'suspension_reason' => 'Violation des règles communautaires',
        'suspended_from' => now()->subDays(2),
        'suspended_until' => now()->addDays(5),
    ]);

    $response = postJson('/api/auth/account-state', [
        'identifier' => 'suspended@example.com',
        'password' => 'Password123',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('handle', 'suspended_user')
        ->assertJsonPath('suspension.reason', 'Violation des règles communautaires')
        ->assertJsonPath('deletion', null);
});

it('handles two-factor authentication flow during signin', function () {
    $user = User::factory()->create([
        'email' => '2fa@example.com',
        'password' => bcrypt('Password123'),
        'two_factor_method' => 'email',
    ]);

    $emailService = Mockery::mock(OtpEmailService::class);
    $sentCode = null;
    $emailService->shouldReceive('send')->once()->andReturnUsing(function ($to, $code) use (&$sentCode) {
        $sentCode = $code;
    });
    $this->app->instance(OtpEmailService::class, $emailService);

    // 1. Signin triggers 2FA
    $response = postJson('/api/auth/signin', [
        'identifier' => '2fa@example.com',
        'password' => 'Password123',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('kind', 'second-factor')
        ->assertJsonStructure(['kind', 'destination', 'challenge']);

    $challenge = $response->json('challenge');
    expect($sentCode)->not->toBeNull();

    // 2. Validate second factor
    $secondFactorResponse = postJson('/api/auth/signin/second-factor', [
        'challenge' => $challenge,
        'code' => $sentCode,
    ]);

    $secondFactorResponse->assertStatus(200)
        ->assertJsonStructure(['token', 'user' => ['id', 'email']]);
});
