<?php

namespace Tests\Feature\Kyc;

use App\Enums\Kyc\KycDocKind;
use App\Enums\Kyc\KycStatus;
use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Kyc\KycDocument;
use App\Models\Kyc\KycDocumentAccessLog;
use App\Models\Kyc\KycSubmission;
use App\Models\User;
use App\Services\Kyc\FaceVerificationService;
use App\Services\Kyc\KycService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Mockery;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
});

function createKycAdminSession(): array
{
    $role = Role::where('slug', 'verification')->firstOrFail();
    $operator = User::where('email', 'solange.bikoi@bango.app')->first();

    if (! $operator || $operator->role_id !== $role->id) {
        $operator = User::factory()->create([
            'email' => 'op.verification.'.uniqid().'@bango.app',
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $sessionId,
        'user_id' => $operator->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    return [$operator, $sessionId, $csrfToken];
}

test('liveness frames are deleted upon approval decision while selfie de face is kept', function () {
    [$operator, $sessionId, $csrfToken] = createKycAdminSession();

    $user = User::factory()->create(['name' => 'Alice Test', 'city' => 'Douala']);

    $submission = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::Pending,
        'profile_category' => 'landlord',
        'full_name' => 'Alice Test',
    ]);

    $privateDisk = config('media.private_disk', 'local');

    // Create selfie
    $selfiePath = "kyc/{$user->id}/selfie.jpg";
    Storage::disk($privateDisk)->put($selfiePath, Crypt::encrypt('fake-selfie-data'));
    $selfieDoc = KycDocument::create([
        'kyc_submission_id' => $submission->id,
        'user_id' => $user->id,
        'kind' => KycDocKind::Selfie,
        'file_path' => $selfiePath,
        'original_name' => 'selfie.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1024,
        'status' => 'pending',
    ]);

    // Create 2 liveness frames
    $frame1Path = "kyc/{$user->id}/frame1.jpg";
    $frame2Path = "kyc/{$user->id}/frame2.jpg";
    Storage::disk($privateDisk)->put($frame1Path, Crypt::encrypt('fake-frame-1'));
    Storage::disk($privateDisk)->put($frame2Path, Crypt::encrypt('fake-frame-2'));

    $frame1 = KycDocument::create([
        'kyc_submission_id' => $submission->id,
        'user_id' => $user->id,
        'kind' => KycDocKind::LivenessFrame,
        'file_path' => $frame1Path,
        'original_name' => 'frame1.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1024,
        'status' => 'pending',
    ]);
    $frame2 = KycDocument::create([
        'kyc_submission_id' => $submission->id,
        'user_id' => $user->id,
        'kind' => KycDocKind::LivenessFrame,
        'file_path' => $frame2Path,
        'original_name' => 'frame2.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1024,
        'status' => 'pending',
    ]);

    // Decision approve
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeaders(['X-CSRF-TOKEN' => $csrfToken])
        ->postJson("/api/admin/kyc/requests/{$submission->id}/decision", [
            'decision' => 'approve',
        ]);

    $response->assertStatus(200);

    // Liveness frames should be deleted from DB and disk
    expect(KycDocument::find($frame1->id))->toBeNull();
    expect(KycDocument::find($frame2->id))->toBeNull();
    expect(Storage::disk($privateDisk)->exists($frame1Path))->toBeFalse();
    expect(Storage::disk($privateDisk)->exists($frame2Path))->toBeFalse();

    // Selfie MUST still be present in DB and disk
    expect(KycDocument::find($selfieDoc->id))->not->toBeNull();
    expect(Storage::disk($privateDisk)->exists($selfiePath))->toBeTrue();
});

test('liveness frames are deleted upon rejection decision while selfie is kept', function () {
    [$operator, $sessionId, $csrfToken] = createKycAdminSession();

    $user = User::factory()->create(['name' => 'Bob Test']);

    $submission = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::Pending,
        'profile_category' => 'landlord',
        'full_name' => 'Bob Test',
    ]);

    $privateDisk = config('media.private_disk', 'local');

    $selfiePath = "kyc/{$user->id}/selfie_bob.jpg";
    Storage::disk($privateDisk)->put($selfiePath, Crypt::encrypt('fake-selfie-data'));
    $selfieDoc = KycDocument::create([
        'kyc_submission_id' => $submission->id,
        'user_id' => $user->id,
        'kind' => KycDocKind::Selfie,
        'file_path' => $selfiePath,
        'original_name' => 'selfie.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1024,
        'status' => 'pending',
    ]);

    $framePath = "kyc/{$user->id}/frame_bob.jpg";
    Storage::disk($privateDisk)->put($framePath, Crypt::encrypt('fake-frame'));
    $frame = KycDocument::create([
        'kyc_submission_id' => $submission->id,
        'user_id' => $user->id,
        'kind' => KycDocKind::LivenessFrame,
        'file_path' => $framePath,
        'original_name' => 'frame.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1024,
        'status' => 'pending',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeaders(['X-CSRF-TOKEN' => $csrfToken])
        ->postJson("/api/admin/kyc/requests/{$submission->id}/decision", [
            'decision' => 'reject',
            'reason' => 'Pièce floue et illisible',
        ]);

    $response->assertStatus(200);

    expect(KycDocument::find($frame->id))->toBeNull();
    expect(Storage::disk($privateDisk)->exists($framePath))->toBeFalse();

    expect(KycDocument::find($selfieDoc->id))->not->toBeNull();
    expect(Storage::disk($privateDisk)->exists($selfiePath))->toBeTrue();
});

test('FaceVerificationService is not called when user requests manual verification', function () {
    $mockFaceService = Mockery::mock(FaceVerificationService::class);
    $mockFaceService->shouldReceive('verify')->never();
    app()->instance(FaceVerificationService::class, $mockFaceService);

    $user = User::factory()->create();

    $kycService = app(KycService::class);

    $selfieFile = UploadedFile::fake()->image('selfie.jpg');
    $frontFile = UploadedFile::fake()->image('id_front.jpg');

    $submission = $kycService->submitKyc(
        user: $user,
        files: [
            'selfie' => $selfieFile,
            'identity_front' => $frontFile,
        ],
        data: [
            'manual_verification' => true,
            'manual_verification_reason' => 'Refus de la biométrie faciale par l\'utilisateur',
            'full_name' => 'Jean Sans Bio',
        ]
    );

    expect($submission->isManualVerification())->toBeTrue();
    expect($submission->metadata['is_manual_verification'])->toBeTrue();
    expect($submission->metadata['face_match'])->toBeNull();
    expect($submission->metadata['liveness'])->toBeNull();
});

test('support can flag and unflag manual verification on a KYC submission via admin api', function () {
    [$operator, $sessionId, $csrfToken] = createKycAdminSession();

    $user = User::factory()->create();
    $submission = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::Pending,
        'profile_category' => 'landlord',
        'full_name' => 'Test Manual Flag',
    ]);

    expect($submission->isManualVerification())->toBeFalse();

    // Enable manual verification
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeaders(['X-CSRF-TOKEN' => $csrfToken])
        ->postJson("/api/admin/kyc/requests/{$submission->id}/manual-verification", [
            'is_manual_verification' => true,
            'reason' => 'L\'utilisateur a contacté le support WhatsApp pour refuser l\'analyse faciale automatique.',
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'is_manual_verification' => true,
            ],
        ]);

    $submission->refresh();
    expect($submission->isManualVerification())->toBeTrue();
    expect($submission->metadata['is_manual_verification'])->toBeTrue();

    // Verify audit log
    $auditLog = AdminAuditLog::where('event', 'kyc.manual_verification_enabled')->latest('id')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->user_id)->toBe($operator->id);

    // Verify detail endpoint reflects manual verification
    $detailResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/kyc/requests/{$submission->id}");

    $detailResponse->assertStatus(200)
        ->assertJsonPath('data.is_manual_verification', true);
});

test('every selfie consultation in back-office is logged in access logs and audit trail', function () {
    [$operator, $sessionId, $csrfToken] = createKycAdminSession();

    $user = User::factory()->create();
    $submission = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::Pending,
        'profile_category' => 'landlord',
        'full_name' => 'Selfie Viewer Test',
    ]);

    $privateDisk = config('media.private_disk', 'local');
    $selfiePath = "kyc/{$user->id}/selfie.jpg";
    Storage::disk($privateDisk)->put($selfiePath, Crypt::encrypt('image-binary-data'));

    $selfieDoc = KycDocument::create([
        'kyc_submission_id' => $submission->id,
        'user_id' => $user->id,
        'kind' => KycDocKind::Selfie,
        'file_path' => $selfiePath,
        'original_name' => 'selfie.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1024,
        'status' => 'pending',
    ]);

    // Open selfie document via open endpoint
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeaders(['X-CSRF-TOKEN' => $csrfToken, 'Accept' => 'application/json'])
        ->postJson("/api/admin/kyc/requests/{$submission->id}/documents/{$selfieDoc->id}/open");

    $response->assertStatus(200);

    // Verify KycDocumentAccessLog recorded action 'view_selfie'
    $log = KycDocumentAccessLog::where('kyc_document_id', $selfieDoc->id)
        ->where('operator_id', $operator->id)
        ->first();

    expect($log)->not->toBeNull();
    expect($log->action)->toBe('view_selfie');

    // Verify AdminAuditLog recorded event 'kyc.selfie_viewed'
    $audit = AdminAuditLog::where('event', 'kyc.selfie_viewed')
        ->where('user_id', $operator->id)
        ->first();

    expect($audit)->not->toBeNull();

    // Verify detail endpoint returns this access log with is_selfie = true
    $detailResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/kyc/requests/{$submission->id}");

    $detailResponse->assertStatus(200);
    $accessLogs = $detailResponse->json('data.access_logs');
    expect($accessLogs)->not->toBeEmpty();
    expect($accessLogs[0]['action'])->toBe('view_selfie');
    expect($accessLogs[0]['is_selfie'])->toBeTrue();
});
