<?php

namespace Tests\Feature\Kyc;

use App\Enums\Kyc\KycDocKind;
use App\Enums\Kyc\KycStatus;
use App\Models\Kyc\KycDocument;
use App\Models\Kyc\KycSubmission;
use App\Models\User;
use App\Notifications\Kyc\KycSupplementRequestedNotification;
use App\Services\Admin\AdminKycService;
use App\Services\Kyc\KycService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class KycSupplementFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_supplement_requested_decision_notifies_user_with_reason(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'arnaud@example.com',
            'name' => 'Arnaud Test',
        ]);
        $operator = User::factory()->create();

        $kycService = app(KycService::class);
        $file = UploadedFile::fake()->create('cni_recto.jpg', 500, 'image/jpeg');

        $submission = $kycService->submitKyc($user, [
            'full_name' => 'Arnaud Test',
            'id_type' => 'cni',
            'id_number' => '1122334455',
        ], [
            'identity_front' => $file,
        ]);

        $this->assertEquals(KycStatus::Pending, $submission->status);

        $adminKycService = app(AdminKycService::class);
        $reason = 'Pièce d\'identité illisible, veuillez renvoyer un scan plus net.';

        $decidedSubmission = $adminKycService->decide(
            submission: $submission,
            operator: $operator,
            decision: 'supplement_requested',
            reason: $reason
        );

        $this->assertEquals(KycStatus::SupplementRequested, $decidedSubmission->status);
        $this->assertEquals($reason, $decidedSubmission->rejection_reason);

        // Vérifier que la notification KycSupplementRequestedNotification est envoyée avec le motif
        Notification::assertSentTo(
            $user,
            KycSupplementRequestedNotification::class,
            function (KycSupplementRequestedNotification $notification) use ($submission, $reason) {
                return $notification->submission->id === $submission->id &&
                    $notification->reason === $reason;
            }
        );
    }

    public function test_submitting_supplement_replaces_document_on_same_submission_and_resets_to_pending(): void
    {
        $user = User::factory()->create();
        $operator = User::factory()->create();

        $kycService = app(KycService::class);
        $file1 = UploadedFile::fake()->create('cni_floue.jpg', 300, 'image/jpeg');

        $submission = $kycService->submitKyc($user, [
            'full_name' => 'Jean Prospect',
        ], [
            'identity_front' => $file1,
        ]);

        $adminKycService = app(AdminKycService::class);
        $adminKycService->decide(
            submission: $submission,
            operator: $operator,
            decision: 'supplement_requested',
            reason: 'Photo trop floue'
        );

        $submission->refresh();
        $this->assertEquals(KycStatus::SupplementRequested, $submission->status);

        Passport::actingAs($user);

        // L'app renvoie uniquement la pièce corrigée via POST /api/landlord/kyc/supplement
        $file2 = UploadedFile::fake()->create('cni_nette.jpg', 600, 'image/jpeg');

        $response = $this->postJson('/api/landlord/kyc/supplement', [
            'identity_front' => $file2,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $submission->id);
        $response->assertJsonPath('data.status', 'pending');

        $submission->refresh();
        $this->assertEquals(KycStatus::Pending, $submission->status);
        $this->assertNull($submission->rejection_reason);

        // Vérifier qu'il n'y a toujours qu'un seul KycDocument de type front pour cette soumission
        $frontDocs = $submission->documents()->where('kind', KycDocKind::Front)->get();
        $this->assertCount(1, $frontDocs);
        $this->assertEquals('cni_nette.jpg', $frontDocs->first()->original_name);
    }

    public function test_submitting_new_kyc_marks_previous_supplement_requested_as_replaced(): void
    {
        $user = User::factory()->create();
        $operator = User::factory()->create();

        $kycService = app(KycService::class);
        $file1 = UploadedFile::fake()->create('cni_recto.jpg', 400, 'image/jpeg');

        $oldSubmission = $kycService->submitKyc($user, [
            'full_name' => 'Arnaud Bango',
        ], [
            'identity_front' => $file1,
        ]);

        $adminKycService = app(AdminKycService::class);
        $adminKycService->decide(
            submission: $oldSubmission,
            operator: $operator,
            decision: 'supplement_requested',
            reason: 'Manque verso'
        );

        $oldSubmission->refresh();
        $this->assertEquals(KycStatus::SupplementRequested, $oldSubmission->status);

        Passport::actingAs($user);

        // L'utilisateur renvoie un nouveau dossier complet via POST /api/landlord/kyc/submit
        $newFileFront = UploadedFile::fake()->create('cni_nouveau_recto.jpg', 400, 'image/jpeg');
        $newFileBack = UploadedFile::fake()->create('cni_nouveau_verso.jpg', 400, 'image/jpeg');

        $response = $this->postJson('/api/landlord/kyc/submit', [
            'full_name' => 'Arnaud Bango',
            'identity_front' => $newFileFront,
            'identity_back' => $newFileBack,
        ]);

        $response->assertStatus(201);
        $newSubmissionId = $response->json('data.id');

        // L'ancien dossier doit désormais être au statut 'replaced'
        $oldSubmission->refresh();
        $this->assertEquals(KycStatus::Replaced, $oldSubmission->status);
        $this->assertStringContainsString('Remplacé par le nouveau dossier', (string) $oldSubmission->rejection_reason);

        // Le nouveau dossier doit être au statut 'pending'
        $newSubmission = KycSubmission::findOrFail($newSubmissionId);
        $this->assertEquals(KycStatus::Pending, $newSubmission->status);
        $this->assertEquals($oldSubmission->id, $newSubmission->metadata['replaces_submission_id'] ?? null);

        // Vérifier que la file conformité ne compte plus l'ancien dossier dans les demandes en attente
        $pendingOrAwaiting = KycSubmission::where('user_id', $user->id)
            ->whereIn('status', [KycStatus::Pending->value, KycStatus::SupplementRequested->value])
            ->count();
        $this->assertEquals(1, $pendingOrAwaiting);
    }
}
