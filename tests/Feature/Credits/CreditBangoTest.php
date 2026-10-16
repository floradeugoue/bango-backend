<?php

use App\Enums\Boost\CampaignStatus;
use App\Enums\Boost\CreditTransactionType;
use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Exceptions\Boost\InsufficientCreditException;
use App\Models\Boost\Campaign;
use App\Models\Boost\CreditTransaction;
use App\Models\Boost\CreditWallet;
use App\Models\Listing\Listing;
use App\Models\Payment\PaymentTransaction;
use App\Models\Square\Post;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Models\Visit\VisitSlot;
use App\Services\Boost\CampaignService;
use App\Services\Boost\CreditWalletService;
use App\Services\Payment\PaymentEngineService;
use App\Services\Visit\VisitReservationService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->user = User::factory()->create(['name' => 'Fadila Client']);
    $this->otherUser = User::factory()->create(['name' => 'Moussa Espion']);

    $this->walletService = app(CreditWalletService::class);
    $this->paymentEngine = app(PaymentEngineService::class);
    $this->campaignService = app(CampaignService::class);
    $this->visitService = app(VisitReservationService::class);
});

/*
|--------------------------------------------------------------------------
| 1. TESTS DU PORTEFEUILLE & SOLDE (SECTION 4.1 & 15)
|--------------------------------------------------------------------------
*/

test('Un portefeuille utilisateur est initialisé à 0 crédit de manière atomique', function () {
    $wallet = $this->walletService->getOrCreateWallet($this->user);

    expect($wallet)->toBeInstanceOf(CreditWallet::class)
        ->and((int) $wallet->balance)->toBe(0)
        ->and($wallet->currency)->toBe('XAF')
        ->and($wallet->user_id)->toBe($this->user->id);

    expect($this->walletService->getBalance($this->user))->toBe(0.0);
});

test('GET /api/credits/wallet retourne le solde entier, la devise et la règle 1 FCFA = 1 Crédit BANGO', function () {
    Passport::actingAs($this->user);

    $this->walletService->credit(
        account: $this->user,
        amount: 2500,
        type: CreditTransactionType::Purchase,
        description: 'Achat de test 2 500 crédits',
        source: 'pay',
        refCode: 'PAY-INIT-001'
    );

    $response = $this->getJson('/api/credits/wallet');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'user_id',
                'balance',
                'currency',
                'conversion_rate',
                'updated_at',
            ],
        ])
        ->assertJsonPath('data.balance', 2500)
        ->assertJsonPath('data.currency', 'XAF')
        ->assertJsonPath('data.conversion_rate', '1 FCFA = 1 Crédit BANGO')
        ->assertJsonPath('data.user_id', $this->user->id);
});

test('Isolation stricte : un utilisateur ne peut pas consulter le portefeuille d\'un autre utilisateur', function () {
    // Portefeuille Fadila crédité de 10 000 crédits
    $this->walletService->credit($this->user, 10000, CreditTransactionType::Purchase, 'Dépôt Fadila');

    // Moussa consulte son propre portefeuille
    Passport::actingAs($this->otherUser);
    $response = $this->getJson('/api/credits/wallet');

    $response->assertOk()
        ->assertJsonPath('data.user_id', $this->otherUser->id)
        ->assertJsonPath('data.balance', 0);

    expect($response->json('data.balance'))->not->toBe(10000);
});

/*
|--------------------------------------------------------------------------
| 2. TESTS D'ACHAT DE CRÉDITS VIA PAY & CONVERSION (SECTION 5 & 15)
|--------------------------------------------------------------------------
*/

test('Règle de conversion immuable : 2 500 FCFA payés donnent exactement 2 500 Crédits BANGO', function () {
    // Création d'une transaction de paiement confirmée pour achat de crédits
    $tx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Credit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => '2500.00',
        'fee_amount' => '0.00',
        'tax_amount' => '0.00',
        'total_amount' => '2500.00',
        'credit_used' => '0.00',
        'amount_charged' => '2500.00',
        'status' => PaymentStatus::Confirmed,
        'idempotency_key' => 'tx_cred_2500_key',
        'metadata' => ['operator' => 'orange', 'phone' => '690000000'],
    ]);

    $creditTx = $this->walletService->creditFromPaymentTransaction($tx);

    expect($creditTx)->toBeInstanceOf(CreditTransaction::class)
        ->and($creditTx->type)->toBe(CreditTransactionType::Purchase)
        ->and($creditTx->amount)->toBe(2500)
        ->and($creditTx->balance_before)->toBe(0)
        ->and($creditTx->balance_after)->toBe(2500)
        ->and($creditTx->source)->toBe('pay')
        ->and($creditTx->reference)->toBe($tx->reference)
        ->and($creditTx->payment_transaction_id)->toBe($tx->id);

    expect((int) $this->walletService->getBalance($this->user))->toBe(2500);
});

test('Achat de 100 FCFA (montant minimal) donne exactement 100 crédits', function () {
    $tx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Credit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => '100.00',
        'fee_amount' => '0.00',
        'tax_amount' => '0.00',
        'total_amount' => '100.00',
        'credit_used' => '0.00',
        'amount_charged' => '100.00',
        'status' => PaymentStatus::Confirmed,
        'idempotency_key' => 'tx_cred_100_key',
    ]);

    $this->walletService->creditFromPaymentTransaction($tx);

    expect((int) $this->walletService->getBalance($this->user))->toBe(100);
});

test('Ne jamais créditer le portefeuille à la simple initiation d\'un paiement', function () {
    Passport::actingAs($this->user);

    $response = $this->postJson('/api/credits/purchases', [
        'amount' => 5000,
        'provider' => 'monetbil',
        'phone' => '690000000',
        'operator' => 'orange',
        'idempotency_key' => 'purchase_init_only_999',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true);

    expect(in_array($response->json('data.status'), ['pending', 'processing']))->toBeTrue();

    // Le solde doit rester STRICTEMENT à zéro
    expect((int) $this->walletService->getBalance($this->user))->toBe(0);
    expect(CreditTransaction::where('user_id', $this->user->id)->count())->toBe(0);
});

test('Aucun crédit si PAY indique un statut pending, failed ou cancelled', function () {
    $pendingTx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Credit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => '3000.00',
        'total_amount' => '3000.00',
        'credit_used' => '0.00',
        'amount_charged' => '3000.00',
        'status' => PaymentStatus::Pending,
        'idempotency_key' => 'tx_pending_3000',
    ]);

    expect(fn () => $this->walletService->creditFromPaymentTransaction($pendingTx))
        ->toThrow(InvalidArgumentException::class);

    expect((int) $this->walletService->getBalance($this->user))->toBe(0);
});

test('Aucun crédit pour un paiement d\'abonnement ou de badge', function () {
    $subTx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Subscription,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => '15000.00',
        'total_amount' => '15000.00',
        'credit_used' => '0.00',
        'amount_charged' => '15000.00',
        'status' => PaymentStatus::Confirmed,
        'idempotency_key' => 'tx_sub_not_credit',
    ]);

    expect(fn () => $this->walletService->creditFromPaymentTransaction($subTx))
        ->toThrow(InvalidArgumentException::class);

    expect((int) $this->walletService->getBalance($this->user))->toBe(0);
});

test('Protection absolue contre les doubles crédits lors de réceptions multiples de webhooks', function () {
    $tx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Credit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => '5000.00',
        'total_amount' => '5000.00',
        'credit_used' => '0.00',
        'amount_charged' => '5000.00',
        'status' => PaymentStatus::Confirmed,
        'idempotency_key' => 'tx_webhook_replay_key',
    ]);

    // Premier appel (webhook 1)
    $tx1 = $this->walletService->creditFromPaymentTransaction($tx);
    expect((int) $this->walletService->getBalance($this->user))->toBe(5000);

    // Deuxième appel identique (webhook 2 rejoué)
    $tx2 = $this->walletService->creditFromPaymentTransaction($tx);
    expect((int) $this->walletService->getBalance($this->user))->toBe(5000);
    expect($tx2->id)->toBe($tx1->id);

    // Troisième appel concurrent simulé
    $tx3 = $this->walletService->creditFromPaymentTransaction($tx);
    expect((int) $this->walletService->getBalance($this->user))->toBe(5000);

    expect(CreditTransaction::where('payment_transaction_id', $tx->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 3. TESTS DE DÉBIT & SÉCURITÉ CONCURRENTE (SECTION 6 & 15)
|--------------------------------------------------------------------------
*/

test('Débit valide de crédits diminue le solde de manière atomique', function () {
    $this->walletService->credit($this->user, 10000, CreditTransactionType::Purchase, 'Solde initial');

    $debit = $this->walletService->debit(
        account: $this->user,
        amount: 3500,
        source: 'boost',
        reference: 'CAMP-BOOST-001',
        description: 'Financement campagne de visibilité'
    );

    expect($debit->amount)->toBe(3500)
        ->and($debit->balance_before)->toBe(10000)
        ->and($debit->balance_after)->toBe(6500)
        ->and($debit->source)->toBe('boost')
        ->and($debit->reference)->toBe('CAMP-BOOST-001');

    expect((int) $this->walletService->getBalance($this->user))->toBe(6500);
});

test('Refus strict si le solde est insuffisant : aucun solde négatif permis', function () {
    $this->walletService->credit($this->user, 1000, CreditTransactionType::Purchase, 'Petit solde');

    expect(fn () => $this->walletService->debit(
        account: $this->user,
        amount: 1500,
        source: 'visit',
        reference: 'VISIT-REF-999'
    ))->toThrow(InsufficientCreditException::class);

    // Le solde doit rester intact à 1 000
    expect((int) $this->walletService->getBalance($this->user))->toBe(1000);
});

test('Idempotence des débits : une opération ne peut produire qu\'un seul débit pour la même référence', function () {
    $this->walletService->credit($this->user, 20000, CreditTransactionType::Purchase, 'Dépôt initial');

    $debit1 = $this->walletService->debit(
        account: $this->user,
        amount: 4000,
        source: 'boost',
        reference: 'CAMP-UNIQUE-REF',
        description: 'Campagne test'
    );

    // Tentative répétée avec la même source et même référence
    $debit2 = $this->walletService->debit(
        account: $this->user,
        amount: 4000,
        source: 'boost',
        reference: 'CAMP-UNIQUE-REF',
        description: 'Campagne test rejeu'
    );

    expect($debit2->id)->toBe($debit1->id)
        ->and((int) $this->walletService->getBalance($this->user))->toBe(16000);

    expect(CreditTransaction::where('source', 'boost')->where('reference', 'CAMP-UNIQUE-REF')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 4. INTÉGRATION BO & CONSOMMATION DÉFINITIVE (SECTION 8, 10 & 15)
|--------------------------------------------------------------------------
*/

test('BO : création d\'une campagne avec débit automatique du Crédit BANGO', function () {
    $this->user->update([
        'badge' => 'landlord',
        'is_verified' => true,
        'verification_lapsed' => false,
    ]);

    $landlordProduct = SubscriptionProduct::where('code', 'landlord')->first();
    $landlordPrice = SubscriptionPrice::where('product_id', $landlordProduct->id)->first();
    Subscription::create([
        'user_id' => $this->user->id,
        'product_id' => $landlordProduct->id,
        'price_id' => $landlordPrice->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $landlordPrice->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
        'auto_renew' => true,
    ]);

    Passport::actingAs($this->user);

    $this->walletService->credit($this->user, 30000, CreditTransactionType::Purchase, 'Solde Boost');

    $post = Post::factory()->create(['user_id' => $this->user->id]);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'objective' => 'reach',
        'budget' => 12000,
        'currency' => 'XAF',
        'duration_days' => 5,
        'payment_method' => 'credit',
        'targeting' => [
            'city' => 'Douala',
            'neighbourhoods' => ['Akwa'],
            'radius_km' => 5,
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status.value', CampaignStatus::Active->value)
        ->assertJsonPath('data.payment_method', 'credit');

    // Vérifier que le portefeuille a été débité de 12 000
    expect((int) $this->walletService->getBalance($this->user))->toBe(18000);

    // Vérifier la traçabilité de l'écriture
    $this->assertDatabaseHas('credit_transactions', [
        'wallet_id' => $this->user->creditWallet->id,
        'user_id' => $this->user->id,
        'source' => 'boost',
        'amount' => 12000,
    ]);
});

test('RÈGLE CB IMMUABLE : aucune restitution de crédits après annulation d\'une campagne', function () {
    Passport::actingAs($this->user);

    $this->walletService->credit($this->user, 20000, CreditTransactionType::Purchase, 'Recharge');

    $campaign = Campaign::factory()->create([
        'user_id' => $this->user->id,
        'budget_amount' => '10000.00',
        'spent_amount' => '3000.00', // 7 000 crédits non dépensés
        'status' => CampaignStatus::Active,
        'payment_method' => 'credit',
    ]);

    // Débit initial de 10 000
    $this->walletService->debit(
        account: $this->user,
        amount: 10000,
        source: 'boost',
        reference: (string) $campaign->id,
        description: 'Financement'
    );

    // Le solde est de 10 000 (20 000 - 10 000)
    expect((int) $this->walletService->getBalance($this->user))->toBe(10000);

    // Annulation de la campagne
    $response = $this->postJson("/api/boost/campaigns/{$campaign->id}/cancel", [
        'reason' => 'manual',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status.value', CampaignStatus::Cancelled->value);

    // RÈGLE CB : le solde NE DOIT PAS AUGMENTER (pas de recrédit des 7 000)
    expect((int) $this->walletService->getBalance($this->user))->toBe(10000);

    // Aucune transaction de remboursement dans la table
    $this->assertDatabaseMissing('credit_transactions', [
        'user_id' => $this->user->id,
        'type' => 'campaign_refund',
    ]);
});

/*
|--------------------------------------------------------------------------
| 5. INTÉGRATION IM - VISITES IMMOBILIÈRES (SECTION 9 & 15)
|--------------------------------------------------------------------------
*/

test('IM : règlement d\'une réservation de visite immobilière via Crédit BANGO', function () {
    $landlord = User::factory()->create();
    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'visit_fee' => 3000,
        'currency' => 'XAF',
    ]);

    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $landlord->id,
        'start_at' => Carbon::now()->addDays(2)->setHour(14)->setMinute(0),
        'end_at' => Carbon::now()->addDays(2)->setHour(15)->setMinute(0),
        'capacity' => 2,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    // Fadila a 8 000 crédits
    $this->walletService->credit($this->user, 8000, CreditTransactionType::Purchase, 'Approvisionnement');

    // Réservation avec paiement en crédits
    $result = $this->visitService->bookVisit(
        listing: $listing,
        slot: $slot,
        prospect: $this->user,
        payload: [
            'payment_method' => 'credit',
            'message' => 'Je viens avec mon garant.',
        ]
    );

    $reservation = $result['reservation'];
    expect($reservation->status)->toBe(VisitReservationStatus::Confirmed)
        ->and($reservation->paid_at)->not->toBeNull()
        ->and($slot->fresh()->booked_count)->toBe(1);

    // Solde après débit : 8 000 - 3 000 = 5 000
    expect((int) $this->walletService->getBalance($this->user))->toBe(5000);

    // L'écriture de débit est tracée avec source visit
    $this->assertDatabaseHas('credit_transactions', [
        'user_id' => $this->user->id,
        'source' => 'visit',
        'reference' => 'VISIT-'.$reservation->id,
        'amount' => 3000,
    ]);
});

test('IM : refus de réservation de visite si solde de crédits insuffisant', function () {
    $landlord = User::factory()->create();
    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'visit_fee' => 5000,
    ]);

    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $landlord->id,
        'start_at' => Carbon::now()->addDays(2)->setHour(10)->setMinute(0),
        'end_at' => Carbon::now()->addDays(2)->setHour(11)->setMinute(0),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    // Fadila n'a que 1 000 crédits
    $this->walletService->credit($this->user, 1000, CreditTransactionType::Purchase, 'Solde insuffisant');

    expect(fn () => $this->visitService->bookVisit(
        listing: $listing,
        slot: $slot,
        prospect: $this->user,
        payload: ['payment_method' => 'credit']
    ))->toThrow(InsufficientCreditException::class);

    // Le solde reste intact
    expect((int) $this->walletService->getBalance($this->user))->toBe(1000);
});

/*
|--------------------------------------------------------------------------
| 6. TESTS DE L'API HISTORIQUE & SÉCURITÉ (SECTION 7, 11 & 15)
|--------------------------------------------------------------------------
*/

test('GET /api/credits/transactions retourne l\'historique paginé avec filtres', function () {
    Passport::actingAs($this->user);

    $this->walletService->credit($this->user, 5000, CreditTransactionType::Purchase, 'Achat 1', 'idemp_p1', null, null, 'pay', 'REF-P1');
    $this->walletService->credit($this->user, 2000, CreditTransactionType::Purchase, 'Achat 2', 'idemp_p2', null, null, 'pay', 'REF-P2');
    $this->walletService->debit($this->user, 3000, 'boost', 'REF-D1', 'Débit campagne');

    $response = $this->getJson('/api/credits/transactions');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'type' => ['value', 'label', 'is_credit'],
                    'amount',
                    'balance_before',
                    'balance_after',
                    'source',
                    'reference',
                    'description',
                    'created_at',
                ],
            ],
            'links',
            'meta',
        ]);

    expect($response->json('data'))->toHaveCount(3)
        ->and($response->json('data.0.amount'))->toBe(3000)
        ->and($response->json('data.0.type.is_credit'))->toBeFalse()
        ->and($response->json('data.0.source'))->toBe('boost');
});

test('Accès non authentifié à l\'API des crédits retourne 401 Unauthorized', function () {
    $this->getJson('/api/credits/wallet')->assertStatus(401);
    $this->postJson('/api/credits/purchases', ['amount' => 1000])->assertStatus(401);
    $this->getJson('/api/credits/transactions')->assertStatus(401);
});

test('Validation stricte des montants d\'achat de crédits', function () {
    Passport::actingAs($this->user);

    // Montant inférieur au minimum (100 FCFA)
    $this->postJson('/api/credits/purchases', [
        'amount' => 50,
        'phone' => '690000000',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);

    // Montant négatif
    $this->postJson('/api/credits/purchases', [
        'amount' => -1000,
        'phone' => '690000000',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);

    // Téléphone manquant
    $this->postJson('/api/credits/purchases', [
        'amount' => 2500,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['phone']);
});
