<?php

namespace Tests\Feature\Kyc;

use App\Enums\Kyc\KycDocKind;
use App\Enums\Kyc\KycStatus;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Models\Admin\AdminAuditLog;
use App\Models\Auth\Role;
use App\Models\Kyc\KycDocument;
use App\Models\Kyc\KycSubmission;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Services\Kyc\KycService;
use App\Services\Page\PageCertificationService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KycComplianceAndPurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_kyc_document_is_encrypted_at_rest_and_decrypted_on_download(): void
    {
        $user = User::factory()->create();
        $kycService = app(KycService::class);

        $fakeContent = 'SECRET_ID_CARD_BYTES_12345';
        $file = UploadedFile::fake()->createWithContent('cni.jpg', $fakeContent);

        $submission = $kycService->submitKyc($user, [
            'full_name' => 'John Doe',
            'id_type' => 'cni',
            'id_number' => '123456789',
        ], [
            'id_card_front' => $file,
        ]);

        /** @var KycDocument $document */
        $document = $submission->documents()->first();
        $this->assertNotNull($document);

        // Vérifier que le fichier est stocké chiffré au repos
        $rawStored = Storage::disk('local')->get($document->file_path);
        $this->assertNotEquals($fakeContent, $rawStored);
        $this->assertEquals($fakeContent, Crypt::decrypt($rawStored));

        // Téléchargement par le propriétaire
        $response = $kycService->downloadDocument($document, $user);
        ob_start();
        $response->sendContent();
        $downloaded = ob_get_clean();
        $this->assertEquals($fakeContent, $downloaded);
    }

    public function test_kyc_document_download_is_restricted_to_compliance_and_logged(): void
    {
        $owner = User::factory()->create();
        $standardUser = User::factory()->create();

        $kycService = app(KycService::class);
        $file = UploadedFile::fake()->createWithContent('cni.jpg', 'ID_CARD_CONTENT');
        $submission = $kycService->submitKyc($owner, [
            'full_name' => 'Jane Doe',
            'id_type' => 'cni',
            'id_number' => '987654321',
        ], [
            'id_card_front' => $file,
        ]);
        $document = $submission->documents()->first();

        // 1. Refus pour utilisateur standard
        $this->expectException(AuthorizationException::class);
        $kycService->downloadDocument($document, $standardUser);
    }

    public function test_moderator_cannot_download_kyc_but_compliance_can_and_it_is_logged(): void
    {
        $owner = User::factory()->create();

        $modRole = Role::firstOrCreate(['slug' => 'moderation'], [
            'name' => 'Modérateur',
            'permissions' => ['moderation:view'],
        ]);
        $moderator = User::factory()->create(['role_id' => $modRole->id]);

        $verifRole = Role::firstOrCreate(['slug' => 'verification'], [
            'name' => 'Conformité',
            'permissions' => ['kyc:view', 'kyc:documents'],
        ]);
        $complianceOfficer = User::factory()->create(['role_id' => $verifRole->id]);

        $kycService = app(KycService::class);
        $file = UploadedFile::fake()->createWithContent('passport.jpg', 'PASSPORT_DATA');
        $submission = $kycService->submitKyc($owner, [
            'full_name' => 'Jane Doe',
            'id_type' => 'cni',
            'id_number' => '987654321',
        ], [
            'id_card_front' => $file,
        ]);
        $document = $submission->documents()->first();

        // 1. Modérateur sans permission KYC -> exception
        try {
            $kycService->downloadDocument($document, $moderator);
            $this->fail('Le modérateur ne doit pas pouvoir télécharger la pièce KYC.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Seule l\'équipe conformité BANGO est autorisée', $e->getMessage());
        }

        // 2. Conformité -> Succès et journalisation AdminAuditLog
        $initialLogsCount = AdminAuditLog::where('event', 'kyc.document_opened')->count();
        $response = $kycService->downloadDocument($document, $complianceOfficer);

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();
        $this->assertEquals('PASSPORT_DATA', $output);

        $newLogsCount = AdminAuditLog::where('event', 'kyc.document_opened')
            ->where('user_id', $complianceOfficer->id)
            ->where('target_id', (string) $document->id)
            ->count();
        $this->assertEquals($initialLogsCount + 1, $newLogsCount);
    }

    public function test_page_certification_policy_and_encryption(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $modRole = Role::firstOrCreate(['slug' => 'moderation'], [
            'name' => 'Modération',
            'permissions' => ['moderation:view'],
        ]);
        $moderator = User::factory()->create(['role_id' => $modRole->id]);

        $verifRole = Role::firstOrCreate(['slug' => 'verification'], [
            'name' => 'Conformité',
            'permissions' => ['verification:view'],
        ]);
        $complianceOfficer = User::factory()->create(['role_id' => $verifRole->id]);

        $page = Page::factory()->create(['owner_id' => $owner->id]);
        PageMember::create([
            'page_id' => $page->id,
            'user_id' => $owner->id,
            'role' => PageRole::Owner,
            'status' => PageMemberStatus::Accepted,
        ]);

        $certService = app(PageCertificationService::class);
        $file = UploadedFile::fake()->createWithContent('rccm.pdf', 'RCCM_LEGAL_FILE');

        $cert = $certService->submit($page, $owner, [
            'rccm_number' => 'RC/DLA/2026/B/123',
        ], [
            'rccm_document' => $file,
        ]);

        // Vérifier chiffrement au repos
        $docPath = $cert->documents['rccm_document']['file_path'];
        $rawOnDisk = Storage::disk('local')->get($docPath);
        $this->assertNotEquals('RCCM_LEGAL_FILE', $rawOnDisk);
        $this->assertEquals('RCCM_LEGAL_FILE', Crypt::decrypt($rawOnDisk));

        // Policy Gate check
        $this->assertTrue(Gate::forUser($owner)->allows('downloadDocument', $cert));
        $this->assertFalse(Gate::forUser($stranger)->allows('downloadDocument', $cert));
        $this->assertFalse(Gate::forUser($moderator)->allows('downloadDocument', $cert));
        $this->assertTrue(Gate::forUser($complianceOfficer)->allows('downloadDocument', $cert));

        // Déchiffrement et audit log au téléchargement
        $response = $certService->downloadDocument($cert, 'rccm_document', $complianceOfficer);
        ob_start();
        $response->sendContent();
        $out = ob_get_clean();
        $this->assertEquals('RCCM_LEGAL_FILE', $out);

        $this->assertTrue(
            AdminAuditLog::where('event', 'page_certification.document_opened')
                ->where('user_id', $complianceOfficer->id)
                ->where('target_id', (string) $cert->id)
                ->exists()
        );
    }

    public function test_purge_command_destroys_rejected_documents_after_30_days(): void
    {
        $user = User::factory()->create();
        $submission = KycSubmission::create([
            'user_id' => $user->id,
            'status' => KycStatus::Rejected,
            'full_name' => $user->name,
            'decided_at' => now()->subDays(31),
        ]);

        $path = "kyc/{$user->id}/rejected_doc.jpg";
        Storage::disk('local')->put($path, 'DUMMY_DATA');

        $doc = KycDocument::create([
            'kyc_submission_id' => $submission->id,
            'user_id' => $user->id,
            'kind' => KycDocKind::Front,
            'file_path' => $path,
            'original_name' => 'doc.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
            'status' => 'rejected',
        ]);

        $this->assertTrue(Storage::disk('local')->exists($path));

        Artisan::call('kyc:purge-documents');

        $this->assertFalse(Storage::disk('local')->exists($path));
        $doc->refresh();
        $this->assertEquals('purged', $doc->file_path);
        $this->assertEquals('purged', $doc->status);
    }

    public function test_purge_command_destroys_documents_for_users_without_subscription_after_90_days(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);
        $product = SubscriptionProduct::first();
        $price = $product->prices()->first();

        $user = User::factory()->create();

        // Ancien abonnement terminé il y a 95 jours
        Subscription::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'price_id' => $price->id,
            'badge_type' => 'landlord',
            'cadence' => 'monthly',
            'state' => 'expired',
            'amount' => 5000,
            'currency' => 'XAF',
            'current_period_start' => now()->subDays(125),
            'current_period_end' => now()->subDays(95),
        ]);

        $path = "kyc/{$user->id}/expired_sub_doc.jpg";
        Storage::disk('local')->put($path, 'DUMMY_DATA_SUB');

        $submission = KycSubmission::create([
            'user_id' => $user->id,
            'status' => KycStatus::Approved,
            'full_name' => $user->name,
            'decided_at' => now()->subDays(120),
        ]);

        $doc = KycDocument::create([
            'kyc_submission_id' => $submission->id,
            'user_id' => $user->id,
            'kind' => KycDocKind::Front,
            'file_path' => $path,
            'original_name' => 'sub_doc.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
            'status' => 'approved',
        ]);

        $this->assertTrue(Storage::disk('local')->exists($path));

        Artisan::call('kyc:purge-documents');

        $this->assertFalse(Storage::disk('local')->exists($path));
        $doc->refresh();
        $this->assertEquals('purged', $doc->file_path);
        $this->assertEquals('purged', $doc->status);
    }

    public function test_purge_command_preserves_documents_for_validated_users_or_active_subscriptions(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);
        $product = SubscriptionProduct::first();
        $price = $product->prices()->first();

        $user = User::factory()->create();

        // Abonnement actif
        Subscription::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'price_id' => $price->id,
            'badge_type' => 'landlord',
            'cadence' => 'monthly',
            'state' => 'active',
            'amount' => 5000,
            'currency' => 'XAF',
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ]);

        $path = "kyc/{$user->id}/active_doc.jpg";
        Storage::disk('local')->put($path, 'SAFE_DATA');

        $submission = KycSubmission::create([
            'user_id' => $user->id,
            'status' => KycStatus::Approved,
            'full_name' => $user->name,
            'decided_at' => now()->subDays(10),
        ]);

        $doc = KycDocument::create([
            'kyc_submission_id' => $submission->id,
            'user_id' => $user->id,
            'kind' => KycDocKind::Front,
            'file_path' => $path,
            'original_name' => 'active_doc.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
            'status' => 'approved',
        ]);

        Artisan::call('kyc:purge-documents');

        // Ne doit PAS être supprimé (conservation légale et abonnement actif)
        $this->assertTrue(Storage::disk('local')->exists($path));
        $doc->refresh();
        $this->assertEquals($path, $doc->file_path);
    }
}
