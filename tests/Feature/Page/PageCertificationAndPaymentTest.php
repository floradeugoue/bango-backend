<?php

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageRole;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Auth\Role;
use App\Models\Page\Page;
use App\Models\Page\PageCertification;
use App\Models\Page\PageMember;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Services\Page\PageCertificationService;
use App\Services\Payment\Gateways\MonetbilGateway;
use App\Services\Payment\PaymentEngineService;
use App\Services\Subscription\SubscriptionService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MonetbilGateway::resetFakes();
    config([
        'payment.default_gateway' => 'monetbil',
        'payment.gateways.monetbil.enabled' => true,
        'payment.gateways.monetbil.service_key' => 'mock_service_key_123',
        'payment.gateways.monetbil.service_secret' => 'mock_service_secret_456',
        'payment.gateways.monetbil.api_base_url' => 'https://api.monetbil.com',
    ]);
    Http::fake([
        'https://api.monetbil.com/payment/v1/placePayment' => Http::response([
            'status' => 'REQUEST_ACCEPTED',
            'paymentId' => 'mb_cert_pay_123',
            'channel_ussd' => '#150*50#',
        ], 200),
    ]);

    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);
    Storage::fake('local');

    $this->adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrateur']);

    $this->owner = User::factory()->create(['name' => 'Claire Propriétaire']);
    $this->editor = User::factory()->create(['name' => 'Luc Éditeur']);
    $this->admin = User::factory()->create(['name' => 'Admin BANGO', 'role_id' => $this->adminRole->id]);

    $this->page = Page::factory()->create([
        'owner_id' => $this->owner->id,
        'name' => 'Société Camerounaise de Transport',
    ]);

    PageMember::create([
        'page_id' => $this->page->id,
        'user_id' => $this->owner->id,
        'role' => PageRole::Owner,
        'status' => 'accepted',
    ]);

    PageMember::create([
        'page_id' => $this->page->id,
        'user_id' => $this->editor->id,
        'role' => PageRole::Editor,
        'status' => 'accepted',
    ]);
});

test('POST /api/pages/{page}/certification permet au propriétaire de soumettre un dossier avec pièces privées', function () {
    Passport::actingAs($this->owner);

    $rccmDoc = UploadedFile::fake()->create('rccm.pdf', 500, 'application/pdf');
    $nifDoc = UploadedFile::fake()->create('nif.pdf', 300, 'application/pdf');
    $idDoc = UploadedFile::fake()->image('cni_gerant.jpg', 800, 600);

    $response = $this->postJson("/api/pages/{$this->page->id}/certification", [
        'rccm_number' => 'RC/DLA/2026/B/1234',
        'nif_number' => 'M0123456789A',
        'legal_representative_name' => 'Claire Représentante',
        'legal_representative_id_type' => 'cni',
        'legal_representative_id_number' => '1122334455',
        'rccm_doc' => $rccmDoc,
        'nif_doc' => $nifDoc,
        'id_doc' => $idDoc,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.rccm_number', 'RC/DLA/2026/B/1234')
        ->assertJsonPath('data.corrections_count', 0)
        ->assertJsonPath('data.max_allowed_corrections', 2);

    $this->assertDatabaseHas('page_certifications', [
        'page_id' => $this->page->id,
        'rccm_number' => 'RC/DLA/2026/B/1234',
        'status' => 'pending',
    ]);

    expect($this->page->fresh()->certification_status)->toBe(PageCertificationStatus::Pending);
});

test('un éditeur ne peut pas soumettre le dossier de certification de la page', function () {
    Passport::actingAs($this->editor);

    $rccmDoc = UploadedFile::fake()->create('rccm.pdf', 500, 'application/pdf');

    $response = $this->postJson("/api/pages/{$this->page->id}/certification", [
        'rccm_number' => 'RC/DLA/2026/B/9999',
        'rccm_doc' => $rccmDoc,
    ]);

    $response->assertForbidden();
});

test('POST /api/pages/{page}/certification/payment initie le règlement des frais via PAY', function () {
    Passport::actingAs($this->owner);

    // 1. Soumission préalable du dossier
    $cert = PageCertification::factory()->create([
        'page_id' => $this->page->id,
        'submitted_by_id' => $this->owner->id,
        'status' => PageCertificationStatus::Pending,
    ]);

    // 2. Paiement via PAY
    $response = $this->postJson("/api/pages/{$this->page->id}/certification/payment", [
        'provider' => 'orange',
        'phone_number' => '+237699001122',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.service_type', 'certification')
        ->assertJsonPath('data.provider', 'orange');

    $this->assertDatabaseHas('payment_transactions', [
        'user_id' => $this->owner->id,
        'service_type' => 'certification',
        'payable_type' => PageCertification::class,
        'payable_id' => $cert->id,
    ]);
});

test('la confirmation d\'un paiement rattache la transaction sans certifier automatiquement la page', function () {
    $cert = PageCertification::factory()->create([
        'page_id' => $this->page->id,
        'submitted_by_id' => $this->owner->id,
        'status' => PageCertificationStatus::Pending,
    ]);

    $tx = PaymentTransaction::factory()->create([
        'user_id' => $this->owner->id,
        'service_type' => PaymentServiceType::Certification,
        'payable_type' => PageCertification::class,
        'payable_id' => $cert->id,
        'status' => PaymentStatus::Confirmed,
    ]);

    // Invocation du fulfiller
    app(PageCertificationService::class)->confirmPaymentFromTransaction($tx);

    $refreshedCert = $cert->fresh();
    expect($refreshedCert->payment_transaction_id)->toBe($tx->id)
        ->and($refreshedCert->status)->toBe(PageCertificationStatus::Pending)
        ->and($this->page->fresh()->isCertified())->toBeFalse();
});

test('la règle PG-07 limite rigoureusement les corrections gratuites à 2 resoumissions', function () {
    Passport::actingAs($this->owner);

    $cert = PageCertification::factory()->create([
        'page_id' => $this->page->id,
        'submitted_by_id' => $this->owner->id,
        'status' => PageCertificationStatus::SupplementRequested,
        'corrections_count' => 0,
    ]);

    $newDoc = UploadedFile::fake()->create('quittance.pdf', 200, 'application/pdf');

    // Correction 1
    $res1 = $this->postJson("/api/pages/certifications/{$cert->id}/resubmit", [
        'rccm_doc' => $newDoc,
    ]);
    $res1->assertOk()->assertJsonPath('data.corrections_count', 1);

    // Correction 2
    $cert->update(['status' => PageCertificationStatus::SupplementRequested]);
    $res2 = $this->postJson("/api/pages/certifications/{$cert->id}/resubmit", [
        'rccm_doc' => $newDoc,
    ]);
    $res2->assertOk()->assertJsonPath('data.corrections_count', 2);

    // Tentative de Correction 3 (Doit être bloquée selon PG-07)
    $cert->update(['status' => PageCertificationStatus::SupplementRequested]);
    $res3 = $this->postJson("/api/pages/certifications/{$cert->id}/resubmit", [
        'rccm_doc' => $newDoc,
    ]);
    $res3->assertStatus(422)
        ->assertJsonValidationErrors(['corrections']);
});

test('l\'administrateur peut examiner, approuver ou refuser un dossier de certification', function () {
    $cert = PageCertification::factory()->create([
        'page_id' => $this->page->id,
        'submitted_by_id' => $this->owner->id,
        'status' => PageCertificationStatus::Pending,
    ]);

    Passport::actingAs($this->admin);

    // Approbation par l'admin
    $response = $this->postJson("/api/admin/pages/certifications/{$cert->id}/decide", [
        'decision' => 'approve',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'approved');

    expect($this->page->fresh()->isCertified())->toBeTrue()
        ->and($this->page->fresh()->certification_status)->toBe(PageCertificationStatus::Approved);
});

test('DELETE /pages/{page}/certification permet d\'annuler une demande en attente sans remboursement', function () {
    $cert = PageCertification::factory()->create([
        'page_id' => $this->page->id,
        'submitted_by_id' => $this->owner->id,
        'status' => PageCertificationStatus::Pending,
    ]);
    $this->page->update(['certification_status' => PageCertificationStatus::Pending]);

    Passport::actingAs($this->owner);

    $response = $this->deleteJson("/api/pages/{$this->page->id}/certification");
    $response->assertOk()
        ->assertJsonFragment(['message' => 'Votre demande de certification a été annulée avec succès (frais de traitement non remboursés).']);

    expect($cert->fresh()->trashed())->toBeTrue();
    expect($this->page->fresh()->certification_status)->toBe(PageCertificationStatus::None);

    // Une deuxième tentative d'annulation échoue car aucune demande n'est pending
    $res2 = $this->deleteJson("/api/pages/{$this->page->id}/certification");
    $res2->assertStatus(422)
        ->assertJsonValidationErrors(['certification']);
});

test('la certification fonctionne comme un abonnement avec cadence mensuelle ou annuelle, renews_at et retrait de la coche en cas de péremption', function () {
    Passport::actingAs($this->owner);

    // 1. Soumission préalable du dossier
    $cert = PageCertification::factory()->create([
        'page_id' => $this->page->id,
        'submitted_by_id' => $this->owner->id,
        'status' => PageCertificationStatus::Pending,
    ]);

    // Récupérer le prix annuel de page_pro
    $product = SubscriptionProduct::where('code', 'page_pro')->first();
    $yearlyPrice = $product->prices()->where('cadence', 'yearly')->first();
    expect($yearlyPrice)->not->toBeNull()
        ->and((int) $yearlyPrice->price_amount)->toBe(150000);

    // 2. Paiement avec choix du price_id (cadence annuelle)
    $response = $this->postJson("/api/pages/{$this->page->id}/certification/payment", [
        'provider' => 'mtn',
        'phone_number' => '+237677112233',
        'price_id' => $yearlyPrice->id,
    ]);

    $response->assertCreated();
    $txId = $response->json('data.id');

    $tx = PaymentTransaction::find($txId);
    expect($tx->price_id)->toBe($yearlyPrice->id);

    // Confirmer le paiement via le moteur de paiement
    app(PaymentEngineService::class)->confirmAndFulfill(
        $tx,
        'EXT-REF-12345'
    );

    // Vérifier que la souscription a été activée pour la page
    $this->assertDatabaseHas('subscriptions', [
        'user_id' => $this->owner->id,
        'page_id' => $this->page->id,
        'product_id' => $product->id,
        'price_id' => $yearlyPrice->id,
        'cadence' => 'yearly',
        'state' => 'active',
    ]);

    // GET /pages/{page}/certification renvoie renews_at
    $certRes = $this->getJson("/api/pages/{$this->page->id}/certification");
    $certRes->assertOk()
        ->assertJsonPath('data.is_paid', true)
        ->assertJsonStructure(['data' => ['renews_at']]);
    expect($certRes->json('data.renews_at'))->not->toBeNull();

    // GET /subscriptions contient la ligne de l'abonnement rattaché à la page
    $subRes = $this->getJson('/api/subscriptions');
    $subRes->assertOk();
    $pageSubItem = collect($subRes->json('data'))->firstWhere('pageId', $this->page->id);
    expect($pageSubItem)->not->toBeNull()
        ->and($pageSubItem['cadence'])->toBe('yearly')
        ->and($pageSubItem['page']['id'])->toBe($this->page->id);

    // 3. Valider la certification par l'admin
    Passport::actingAs($this->admin);
    $this->postJson("/api/admin/pages/certifications/{$cert->id}/decide", [
        'decision' => 'approve',
    ])->assertOk();

    expect($this->page->fresh()->isCertified())->toBeTrue()
        ->and($this->page->fresh()->certification_status)->toBe(PageCertificationStatus::Approved);

    // 4. Test du cycle de vie : expiration de l'abonnement
    $sub = Subscription::where('page_id', $this->page->id)->first();
    app(SubscriptionService::class)->expireSubscription(
        $sub,
        'Période de grâce échue sans régularisation'
    );

    $freshPage = $this->page->fresh();
    expect($freshPage->certification_status)->toBe(PageCertificationStatus::Lapsed)
        ->and($freshPage->isCertified())->toBeFalse();
});

test('POST /api/admin/pages/{page}/restore-certification rétablit la certification en approved avec motif dans l\'historique', function () {
    Passport::actingAs($this->admin);

    $cert = PageCertification::factory()->create([
        'page_id' => $this->page->id,
        'submitted_by_id' => $this->owner->id,
        'status' => PageCertificationStatus::Suspended,
    ]);
    $this->page->update(['certification_status' => PageCertificationStatus::Suspended]);

    $response = $this->postJson("/api/admin/pages/{$this->page->id}/restore-certification", [
        'motif' => 'Vérification complémentaire validée avec succès',
    ]);

    $response->assertOk()
        ->assertJsonPath('page.certification_status', 'approved');

    expect($this->page->fresh()->certification_status)->toBe(PageCertificationStatus::Approved)
        ->and($this->page->fresh()->isCertified())->toBeTrue();

    $refreshedCert = $cert->fresh();
    expect($refreshedCert->status)->toBe(PageCertificationStatus::Approved);
    $history = $refreshedCert->history;
    $lastHistory = end($history);
    expect($lastHistory['action'])->toBe('restored')
        ->and($lastHistory['reason'])->toBe('Vérification complémentaire validée avec succès');
});

test('GET /api/admin/pages/certifications rend page { id, name, handle, avatar_url }, submitter { id, name } et les download_url admin', function () {
    Passport::actingAs($this->admin);

    $encryptedContent = Crypt::encrypt('CONTENU_PDF_TEST');
    Storage::disk('local')->put("page_certifications/{$this->page->id}/rccm_test.pdf", $encryptedContent);

    PageCertification::factory()->create([
        'page_id' => $this->page->id,
        'submitted_by_id' => $this->owner->id,
        'status' => PageCertificationStatus::Pending,
        'documents' => [
            'rccm' => [
                'original_name' => 'rccm_officiel.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 1024,
                'file_path' => "page_certifications/{$this->page->id}/rccm_test.pdf",
            ],
        ],
    ]);

    $response = $this->getJson('/api/admin/pages/certifications');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'page_id',
                    'page' => ['id', 'name', 'handle', 'avatar_url'],
                    'submitter' => ['id', 'name'],
                    'documents' => [
                        'rccm' => ['original_name', 'mime_type', 'file_size', 'download_url'],
                    ],
                ],
            ],
        ]);

    $firstItem = $response->json('data.0');
    expect($firstItem['page']['id'])->toBe($this->page->id)
        ->and($firstItem['page']['name'])->toBe('Société Camerounaise de Transport')
        ->and($firstItem['submitter']['id'])->toBe($this->owner->id)
        ->and($firstItem['submitter']['name'])->toBe('Claire Propriétaire')
        ->and($firstItem['documents']['rccm']['download_url'])->toContain('/admin/pages/certifications/');
});

test('GET /api/admin/pages/certifications/{certification}/documents/{key} télécharge la pièce sous session administrative', function () {
    Passport::actingAs($this->admin);

    $rawContent = 'FICHIER_DECISION_ADMIN_SECRET';
    $encrypted = Crypt::encrypt($rawContent);
    $path = "page_certifications/{$this->page->id}/piece_admin.pdf";
    Storage::disk('local')->put($path, $encrypted);

    $cert = PageCertification::factory()->create([
        'page_id' => $this->page->id,
        'submitted_by_id' => $this->owner->id,
        'status' => PageCertificationStatus::Pending,
        'documents' => [
            'piece_admin' => [
                'original_name' => 'justificatif_officiel.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => strlen($rawContent),
                'file_path' => $path,
            ],
        ],
    ]);

    $response = $this->get("/api/admin/pages/certifications/{$cert->id}/documents/piece_admin");

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->streamedContent())->toBe($rawContent);
});

test('POST /api/admin/pages/certifications/{certification}/decide refuse proprement avec un 422 si la page est supprimée', function () {
    Passport::actingAs($this->admin);

    $cert = PageCertification::factory()->create([
        'page_id' => $this->page->id,
        'submitted_by_id' => $this->owner->id,
        'status' => PageCertificationStatus::Pending,
    ]);

    // Suppression de la page (SoftDelete)
    $this->page->delete();
    expect($this->page->trashed())->toBeTrue();
    expect(Page::find($this->page->id))->toBeNull();

    $response = $this->postJson("/api/admin/pages/certifications/{$cert->id}/decide", [
        'decision' => 'approve',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['certification']);
});

test('POST /api/pages/{page}/certification/payment lance la demande Mobile Money, rattache la transaction au dossier, et active payment_transaction_id au webhook', function () {
    Passport::actingAs($this->owner);

    $cert = PageCertification::factory()->create([
        'page_id' => $this->page->id,
        'submitted_by_id' => $this->owner->id,
        'status' => PageCertificationStatus::Pending,
    ]);

    expect($cert->payment_transaction_id)->toBeNull();

    // 1. Appel de la route unique de paiement de certification
    $response = $this->postJson("/api/pages/{$this->page->id}/certification/payment", [
        'provider' => 'orange',
        'phone_number' => '+237699001122',
        'idempotency_key' => 'idemp_cert_full_flow',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.service_type', 'certification')
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('prompt.ussd', '#150*50#')
        ->assertJsonPath('prompt.ussd_code', '#150*50#');

    $txId = $response->json('data.id');
    $tx = PaymentTransaction::find($txId);

    expect($tx)->not->toBeNull()
        ->and($tx->payable_type)->toBe(PageCertification::class)
        ->and($tx->payable_id)->toBe((string) $cert->id)
        ->and($tx->external_reference)->toBe('mb_cert_pay_123');

    // Le dossier n'a pas encore payment_transaction_id tant que le webhook n'a pas confirmé
    expect($cert->fresh()->payment_transaction_id)->toBeNull();

    // 2. Simulation de la confirmation par le webhook Monetbil
    app(PaymentEngineService::class)->confirmAndFulfill($tx, 'mb_cert_pay_123');

    // 3. Après confirmation du webhook, payment_transaction_id est renseigné sur le dossier
    $refreshedCert = $cert->fresh();
    expect($refreshedCert->payment_transaction_id)->toBe($tx->id);

    // 4. Consultation du dossier : is_paid est à true
    $showResponse = $this->getJson("/api/pages/{$this->page->id}/certification");
    $showResponse->assertOk()
        ->assertJsonPath('data.is_paid', true)
        ->assertJsonPath('data.payment_transaction_id', $tx->id);

    // 5. L'administrateur peut maintenant approuver le dossier payé
    Passport::actingAs($this->admin);
    $decideResponse = $this->postJson("/api/admin/pages/certifications/{$cert->id}/decide", [
        'decision' => 'approve',
    ]);

    $decideResponse->assertOk()
        ->assertJsonPath('data.status', 'approved');

    expect($this->page->fresh()->certification_status)->toBe(PageCertificationStatus::Approved);
});

test('POST /api/payments/initiate avec service_type=certification rattache également la transaction au dossier', function () {
    Passport::actingAs($this->owner);

    $cert = PageCertification::factory()->create([
        'page_id' => $this->page->id,
        'submitted_by_id' => $this->owner->id,
        'status' => PageCertificationStatus::Pending,
    ]);

    $response = $this->postJson('/api/payments/initiate', [
        'service_type' => 'certification',
        'page_id' => $this->page->id,
        'phone' => '699001122',
        'operator' => 'orange',
        'idempotency_key' => 'idemp_payments_initiate_cert',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.service_type', 'certification')
        ->assertJsonPath('data.status', 'processing');

    $txId = $response->json('data.id');
    $tx = PaymentTransaction::find($txId);

    expect($tx)->not->toBeNull()
        ->and($tx->payable_type)->toBe(PageCertification::class)
        ->and($tx->payable_id)->toBe((string) $cert->id);

    // Confirmation webhook
    app(PaymentEngineService::class)->confirmAndFulfill($tx, 'mb_cert_pay_123');

    expect($cert->fresh()->payment_transaction_id)->toBe($tx->id);
});
