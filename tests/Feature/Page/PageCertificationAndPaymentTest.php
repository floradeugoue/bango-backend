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
use App\Services\Payment\PaymentEngineService;
use App\Services\Subscription\SubscriptionService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
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
