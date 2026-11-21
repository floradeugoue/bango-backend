<?php

use App\Enums\OtpChannel;
use App\Models\Auth\Otp;
use App\Models\Notification\DeviceToken;
use App\Models\User;
use App\Notifications\Auth\AccountDeletionRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test Client']);
});

test('public account deletion page is accessible in French and English', function () {
    $this->get('/supprimer-mon-compte')
        ->assertStatus(200)
        ->assertSee('Supprimer votre compte et vos données personnelles')
        ->assertSee('Google Play');

    $this->get('/en/delete-account')
        ->assertStatus(200)
        ->assertSee('Delete your account & personal data')
        ->assertSee('Google Play');
});

test('requesting an OTP via web route sends a code for an existing user', function () {
    $user = User::factory()->create([
        'email' => 'jean.dupont@example.com',
        'phone' => '+237690000001',
    ]);

    $response = $this->post('/supprimer-mon-compte/request-otp', [
        'identifier' => 'jean.dupont@example.com',
        'locale' => 'fr',
    ]);

    $response->assertSessionHas('status', 'otp_sent');
});

test('user can schedule account deletion using their password', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'deletion.test@example.com',
        'password' => Hash::make('MySecurePassword123!'),
    ]);

    $response = $this->post('/supprimer-mon-compte', [
        'identifier' => 'deletion.test@example.com',
        'auth_method' => 'password',
        'password' => 'MySecurePassword123!',
        'confirm_delete' => '1',
        'locale' => 'fr',
    ]);

    $response->assertSessionHas('status', 'scheduled_for_deletion');

    $refreshedUser = User::withTrashed()->find($user->id);
    expect($refreshedUser->trashed())->toBeTrue();
    expect($refreshedUser->scheduled_for_deletion_at)->not->toBeNull();
    expect($refreshedUser->scheduled_for_deletion_at->isFuture())->toBeTrue();
    expect(Carbon::now()->diffInDays($refreshedUser->scheduled_for_deletion_at))->toBeGreaterThanOrEqual(29);

    Notification::assertSentTo($refreshedUser, AccountDeletionRequestedNotification::class);
});

test('user can schedule account deletion using an OTP code', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'otp.deletion@example.com',
        'phone' => '+237699112233',
    ]);

    Otp::updateOrCreate(
        ['identifier' => $user->email, 'channel' => OtpChannel::Email->value, 'context' => 'account_deletion'],
        [
            'code' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => Carbon::now()->addMinutes(10),
            'locked_until' => null,
        ]
    );

    $response = $this->post('/supprimer-mon-compte', [
        'identifier' => $user->email,
        'auth_method' => 'otp',
        'otp_code' => '123456',
        'confirm_delete' => '1',
        'locale' => 'fr',
    ]);

    $response->assertSessionHas('status', 'scheduled_for_deletion');

    $refreshedUser = User::withTrashed()->find($user->id);
    expect($refreshedUser->trashed())->toBeTrue();
    expect($refreshedUser->scheduled_for_deletion_at)->not->toBeNull();
});

test('deletion fails if password is incorrect', function () {
    $user = User::factory()->create([
        'email' => 'wrong.pass@example.com',
        'password' => Hash::make('CorrectPassword123!'),
    ]);

    $response = $this->post('/supprimer-mon-compte', [
        'identifier' => 'wrong.pass@example.com',
        'auth_method' => 'password',
        'password' => 'WrongPassword!',
        'confirm_delete' => '1',
        'locale' => 'fr',
    ]);

    $response->assertSessionHas('error');

    $refreshedUser = User::find($user->id);
    expect($refreshedUser->trashed())->toBeFalse();
    expect($refreshedUser->scheduled_for_deletion_at)->toBeNull();
});

test('deletion fails if otp code is invalid', function () {
    $user = User::factory()->create([
        'email' => 'wrong.otp@example.com',
    ]);

    $response = $this->post('/supprimer-mon-compte', [
        'identifier' => 'wrong.otp@example.com',
        'auth_method' => 'otp',
        'otp_code' => '999999',
        'confirm_delete' => '1',
        'locale' => 'fr',
    ]);

    $response->assertSessionHas('error');

    $refreshedUser = User::find($user->id);
    expect($refreshedUser->trashed())->toBeFalse();
});

test('requesting deletion for already scheduled account returns notification without error', function () {
    $scheduledAt = Carbon::now()->addDays(20);
    $user = User::factory()->create([
        'email' => 'already.scheduled@example.com',
        'password' => Hash::make('Password123!'),
        'scheduled_for_deletion_at' => $scheduledAt,
    ]);
    $user->delete();

    $response = $this->post('/supprimer-mon-compte', [
        'identifier' => 'already.scheduled@example.com',
        'auth_method' => 'password',
        'password' => 'Password123!',
        'confirm_delete' => '1',
        'locale' => 'fr',
    ]);

    $response->assertSessionHas('status', 'already_scheduled');
});

test('user can delete partial data (location history & device push tokens) without deleting account', function () {
    $user = User::factory()->create([
        'email' => 'partial.deletion@example.com',
        'password' => Hash::make('Password123!'),
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
    ]);

    DeviceToken::create([
        'user_id' => $user->id,
        'token' => 'sample_fcm_token_123',
        'platform' => 'android',
    ]);

    expect($user->deviceTokens()->count())->toBe(1);
    expect($user->city)->toBe('Douala');
    expect($user->neighbourhood)->toBe('Akwa');

    $response = $this->post('/supprimer-mon-compte/partial-data', [
        'identifier' => 'partial.deletion@example.com',
        'auth_method' => 'password',
        'password' => 'Password123!',
        'categories' => ['location_history', 'devices'],
        'locale' => 'fr',
    ]);

    $response->assertSessionHas('status', 'partial_data_deleted');

    $user->refresh();
    expect($user->trashed())->toBeFalse();
    expect($user->city)->toBeNull();
    expect($user->neighbourhood)->toBeNull();
    expect($user->deviceTokens()->count())->toBe(0);
});
