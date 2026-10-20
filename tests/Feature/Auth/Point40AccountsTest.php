<?php

use App\Enums\Legal\DataExportStatus;
use App\Models\Legal\DataExportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

test('Point 40 : Configuration et activation de la 2FA par SMS', function () {
    $user = User::factory()->create([
        'phone' => '+237699001122',
    ]);
    Passport::actingAs($user);

    // 1. Envoi du code OTP par SMS
    $sendResponse = $this->postJson('/api/settings/2fa/sms/send');
    $sendResponse->assertOk()
        ->assertJsonPath('success', true);

    $cached = Cache::get("2fa_sms:{$user->id}");
    expect($cached)->not->toBeNull()
        ->and($cached['code'])->toBeString();

    // 2. Vérification avec code erroné
    $badVerifyResponse = $this->postJson('/api/settings/2fa/sms/verify', [
        'code' => '000000',
    ]);
    $badVerifyResponse->assertStatus(422)
        ->assertJsonPath('kind', 'invalid_code');

    // 3. Vérification avec le code correct
    $verifyResponse = $this->postJson('/api/settings/2fa/sms/verify', [
        'code' => $cached['code'],
    ]);

    $verifyResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('two_factor_method', 'sms')
        ->assertJsonCount(8, 'backup_codes');

    expect($user->fresh()->two_factor_method)->toBe('sms')
        ->and($user->fresh()->backup_codes)->toBeArray()
        ->and(count($user->fresh()->backup_codes))->toBe(8);
});

test('Point 40 : Régénération des codes de secours exige le mot de passe actuel', function () {
    $user = User::factory()->create([
        'password' => Hash::make('SecretPass123!'),
        'two_factor_method' => 'app',
        'backup_codes' => [hash('sha256', 'OLD-CODE-1')],
    ]);
    Passport::actingAs($user);

    // 1. Tentative sans mot de passe -> 422
    $responseNoPassword = $this->postJson('/api/settings/2fa/backup-codes/regenerate');
    $responseNoPassword->assertStatus(422)
        ->assertJsonValidationErrors(['password']);

    // 2. Tentative avec mauvais mot de passe -> 422
    $responseBadPassword = $this->postJson('/api/settings/2fa/backup-codes/regenerate', [
        'password' => 'WrongPassword!',
    ]);
    $responseBadPassword->assertStatus(422)
        ->assertJsonValidationErrors(['password']);

    // 3. Tentative avec bon mot de passe -> 200 et nouveaux codes
    $responseSuccess = $this->postJson('/api/settings/2fa/backup-codes/regenerate', [
        'password' => 'SecretPass123!',
    ]);

    $responseSuccess->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(8, 'backup_codes');
});

test('Point 40 : Export RGPD avec choix des sections et annulation', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // 1. Demande d'export avec sélection de sections
    $requestResponse = $this->postJson('/api/user/data-export', [
        'sections' => ['profile', 'listings', 'transactions'],
    ]);

    $requestResponse->assertStatus(202)
        ->assertJsonPath('export.sections', ['profile', 'listings', 'transactions']);

    $exportId = $requestResponse->json('export.id');
    $export = DataExportRequest::find($exportId);
    expect($export->sections)->toBe(['profile', 'listings', 'transactions']);

    // 2. Annulation de la demande d'export
    $cancelResponse = $this->postJson("/api/user/data-export/{$exportId}/cancel");
    $cancelResponse->assertOk()
        ->assertJsonPath('export.status.value', DataExportStatus::Cancelled->value);

    expect($export->fresh()->status)->toBe(DataExportStatus::Cancelled);
});
