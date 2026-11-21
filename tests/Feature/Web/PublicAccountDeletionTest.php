<?php

namespace Tests\Feature\Web;

use App\Enums\OtpChannel;
use App\Models\Auth\Otp;
use App\Models\User;
use App\Notifications\Auth\AccountDeletionRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

it('renders public account deletion pages in French and English with legal disclosures', function () {
    $frResponse = $this->get('/supprimer-mon-compte');

    $frResponse->assertStatus(200)
        ->assertSee('Supprimer votre compte et vos données personnelles')
        ->assertSee('Données effacées (dans 30 jours)')
        ->assertSee('Données conservées selon la loi')
        ->assertSee('10 ans')
        ->assertSee('Délai de rétractation de 30 jours');

    $enResponse = $this->get('/en/delete-account');

    $enResponse->assertStatus(200)
        ->assertSee('Delete your account & personal data')
        ->assertSee('Data permanently deleted (in 30 days)')
        ->assertSee('Data retained per statutory obligations')
        ->assertSee('10 yrs')
        ->assertSee('30-day grace period');
});

it('schedules account deletion in 30 days via password and sends email confirmation', function () {
    $user = User::factory()->create([
        'email' => 'arnaud@bango.app',
        'phone' => '+237690112233',
        'password' => Hash::make('SecretPass123!'),
    ]);

    $response = $this->postJson('/supprimer-mon-compte', [
        'identifier' => 'arnaud@bango.app',
        'auth_method' => 'password',
        'password' => 'SecretPass123!',
        'confirm_delete' => true,
        'locale' => 'fr',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'status' => 'scheduled_for_deletion',
        ]);

    $trashedUser = User::withTrashed()->find($user->id);
    expect($trashedUser->trashed())->toBeTrue()
        ->and($trashedUser->scheduled_for_deletion_at)->not->toBeNull()
        ->and((int) round(now()->diffInDays($trashedUser->scheduled_for_deletion_at)))->toBe(30);

    Notification::assertSentTo($user, AccountDeletionRequestedNotification::class);
});

it('schedules account deletion in 30 days via OTP code without password', function () {
    $user = User::factory()->create([
        'email' => 'mireille@bango.app',
        'phone' => '+237699887766',
        'password' => Hash::make('UnusedPassword123!'),
    ]);

    // 1. Demande d'envoi d'OTP
    $otpReq = $this->postJson('/supprimer-mon-compte/request-otp', [
        'identifier' => 'mireille@bango.app',
        'locale' => 'fr',
    ]);
    $otpReq->assertStatus(200)->assertJson(['success' => true]);

    // Fixer un code OTP connu en base pour valider la vérification
    $code = '482910';
    Otp::updateOrCreate(
        ['identifier' => 'mireille@bango.app', 'channel' => OtpChannel::Email->value, 'context' => 'account_deletion'],
        [
            'code' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'locked_until' => null,
        ]
    );

    // 2. Confirmation de la suppression avec le code OTP
    $delRes = $this->postJson('/supprimer-mon-compte', [
        'identifier' => 'mireille@bango.app',
        'auth_method' => 'otp',
        'otp_code' => $code,
        'confirm_delete' => true,
        'locale' => 'fr',
    ]);

    $delRes->assertStatus(200)
        ->assertJson([
            'success' => true,
            'status' => 'scheduled_for_deletion',
        ]);

    $trashedUser = User::withTrashed()->find($user->id);
    expect($trashedUser->trashed())->toBeTrue()
        ->and($trashedUser->scheduled_for_deletion_at)->not->toBeNull();

    Notification::assertSentTo($user, AccountDeletionRequestedNotification::class);
});

it('allows canceling scheduled deletion by restoring the account within 30 days', function () {
    $user = User::factory()->create([
        'email' => 'restore@bango.app',
        'password' => Hash::make('RestoreMe123!'),
    ]);

    // Programmation de la suppression depuis la page web
    $this->postJson('/en/delete-account', [
        'identifier' => 'restore@bango.app',
        'auth_method' => 'password',
        'password' => 'RestoreMe123!',
        'confirm_delete' => true,
    ])->assertStatus(200);

    expect(User::withTrashed()->find($user->id)->trashed())->toBeTrue();

    // Restauration en se reconnectant dans les 30 jours
    $restoreRes = $this->postJson('/api/auth/restore-account', [
        'identifier' => 'restore@bango.app',
        'password' => 'RestoreMe123!',
    ]);

    $restoreRes->assertStatus(200)->assertJson(['success' => true]);

    $restoredUser = User::withTrashed()->find($user->id);
    expect($restoredUser->trashed())->toBeFalse()
        ->and($restoredUser->scheduled_for_deletion_at)->toBeNull();
});

it('deletes partial data categories without deleting the user account', function () {
    $user = User::factory()->create([
        'email' => 'partial@bango.app',
        'password' => Hash::make('PartialPass123!'),
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'neighbourhood_verified_at' => now(),
    ]);

    $response = $this->postJson('/supprimer-mon-compte/partial-data', [
        'identifier' => 'partial@bango.app',
        'auth_method' => 'password',
        'password' => 'PartialPass123!',
        'categories' => ['location_history', 'devices'],
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'status' => 'partial_data_deleted',
        ]);

    $user->refresh();
    expect($user->trashed())->toBeFalse()
        ->and($user->city)->toBeNull()
        ->and($user->neighbourhood)->toBeNull()
        ->and($user->neighbourhood_verified_at)->toBeNull();
});
