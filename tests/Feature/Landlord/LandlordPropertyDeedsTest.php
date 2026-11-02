<?php

use App\Enums\Kyc\KycDocKind;
use App\Models\Kyc\KycDocument;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Services\Kyc\FaceVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    // Fake FaceVerificationService to avoid external API calls
    $this->mock(FaceVerificationService::class, function ($mock) {
        $mock->shouldReceive('verify')->andReturn([
            'face_match' => 95.0,
            'liveness' => true,
        ]);
    });
});

test('submitting KYC with multiple property_deeds[] creates multiple deed documents', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $deed1 = UploadedFile::fake()->create('titre_propriete_1.pdf', 500, 'application/pdf');
    $deed2 = UploadedFile::fake()->create('titre_propriete_2.pdf', 600, 'application/pdf');

    $response = $this->postJson('/api/landlord/kyc/submit', [
        'full_name' => 'Amadou Diallo',
        'requested_badge' => 'landlord',
        'property_right' => 'owner',
        'deed_type' => 'titre_foncier',
        'property_deeds' => [$deed1, $deed2],
    ]);

    $response->assertStatus(201);

    $submissionId = $response->json('data.id');
    expect($submissionId)->not->toBeNull();

    $deeds = KycDocument::where('kyc_submission_id', $submissionId)
        ->where('kind', KycDocKind::Deed)
        ->get();

    expect($deeds)->toHaveCount(2)
        ->and($deeds->pluck('original_name')->all())->toContain('titre_propriete_1.pdf', 'titre_propriete_2.pdf')
        ->and($deeds->first()->deed_type)->toBe('titre_foncier');
});

test('landlord can add a new property deed later on POST /landlord/kyc/property-deeds', function () {
    $user = User::factory()->create(['is_verified' => true]);
    $listing = Listing::factory()->create(['owner_id' => $user->id]);

    Passport::actingAs($user);

    $file = UploadedFile::fake()->create('nouveau_titre.pdf', 800, 'application/pdf');

    $response = $this->postJson('/api/landlord/kyc/property-deeds', [
        'property_deed' => $file,
        'deed_type' => 'bail_emphytéotique',
        'listing_id' => $listing->id,
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'message' => 'Justificatif de propriété ajouté avec succès. Il sera examiné par la conformité.',
            'data' => [
                'kind' => 'deed',
                'deed_type' => 'bail_emphytéotique',
                'listing_id' => $listing->id,
                'status' => 'pending',
                'original_name' => 'nouveau_titre.pdf',
            ],
        ]);

    $this->assertDatabaseHas('kyc_documents', [
        'user_id' => $user->id,
        'listing_id' => $listing->id,
        'kind' => 'deed',
        'deed_type' => 'bail_emphytéotique',
        'status' => 'pending',
    ]);
});

test('landlord can list their property deeds on GET /landlord/kyc/property-deeds with their status', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    KycDocument::create([
        'user_id' => $user->id,
        'kind' => KycDocKind::Deed,
        'deed_type' => 'titre_foncier',
        'file_path' => 'kyc/test/1.pdf',
        'original_name' => 'deed_almadies.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'status' => 'approved',
    ]);

    KycDocument::create([
        'user_id' => $user->id,
        'kind' => KycDocKind::Deed,
        'deed_type' => 'bail',
        'file_path' => 'kyc/test/2.pdf',
        'original_name' => 'deed_plateau.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 2048,
        'status' => 'pending',
    ]);

    $response = $this->getJson('/api/landlord/kyc/property-deeds');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'kind', 'deed_type', 'status', 'original_name'],
            ],
        ]);

    expect($response->json('data'))->toHaveCount(2)
        ->and(collect($response->json('data'))->pluck('status')->all())->toContain('approved', 'pending');
});
