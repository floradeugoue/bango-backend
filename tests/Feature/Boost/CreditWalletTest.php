<?php

use App\Enums\Boost\CreditTransactionType;
use App\Exceptions\Boost\InsufficientCreditException;
use App\Models\Auth\Role;
use App\Models\Boost\Campaign;
use App\Models\Boost\CreditTransaction;
use App\Models\User;
use App\Services\Boost\CreditWalletService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->user = User::factory()->create(['name' => 'Kouamé Portefeuille']);
    $this->walletService = app(CreditWalletService::class);
});

test('GET /api/boost/wallet retourne le solde et les informations du portefeuille', function () {
    Passport::actingAs($this->user);

    $this->walletService->credit(
        account: $this->user,
        amount: 25000,
        type: CreditTransactionType::Deposit,
        description: 'Recharge initiale'
    );

    $response = $this->getJson('/api/boost/wallet');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'user_id',
                'balance',
                'currency',
                'recent_transactions',
                'updated_at',
            ],
        ])
        ->assertJsonPath('data.balance', 25000)
        ->assertJsonPath('data.currency', 'XAF')
        ->assertJsonPath('data.user_id', $this->user->id);
});

test('GET /api/boost/wallet/transactions retourne l\'historique paginé du grand livre', function () {
    Passport::actingAs($this->user);

    $this->walletService->credit($this->user, 10000, CreditTransactionType::Deposit, 'Dépôt 1');
    $this->walletService->credit($this->user, 5000, CreditTransactionType::Deposit, 'Dépôt 2');
    $this->walletService->debit($this->user, 3000, CreditTransactionType::CampaignDebit, 'Campagne 1');

    $response = $this->getJson('/api/boost/wallet/transactions');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'type' => ['value', 'label', 'is_credit'],
                    'amount',
                    'balance_before',
                    'balance_after',
                    'description',
                    'created_at',
                ],
            ],
            'links',
            'meta',
        ]);

    expect($response->json('data'))->toHaveCount(3)
        ->and($response->json('data.0.type.value'))->toBe(CreditTransactionType::CampaignDebit->value)
        ->and((float) $response->json('data.0.amount'))->toBe(-3000.0)
        ->and((float) $response->json('data.0.balance_after'))->toBe(12000.0);
});

test('POST /api/boost/wallet/deposit est strictement interdit aux utilisateurs standards', function () {
    Passport::actingAs($this->user);

    $response = $this->postJson('/api/boost/wallet/deposit', [
        'amount' => 15000,
        'description' => 'Tentative recharge gratuite',
        'idempotency_key' => 'dep_unique_12345',
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'code' => 'DIRECT_DEPOSIT_FORBIDDEN',
        ]);

    expect($this->walletService->getBalance($this->user))->toBe(0.0);
});

test('POST /api/boost/wallet/deposit autorise uniquement un opérateur avec motif valide et journalise', function () {
    $operatorRole = Role::firstOrCreate(
        ['slug' => 'admin'],
        ['name' => 'Admin', 'permissions' => []]
    );
    $operator = User::factory()->create([
        'role_id' => $operatorRole->id,
        'status' => 'active',
    ]);

    Passport::actingAs($operator);

    // 1. Rejet si motif absent ou trop court
    $invalidResponse = $this->postJson('/api/boost/wallet/deposit', [
        'amount' => 15000,
        'user_id' => $this->user->id,
        'reason' => 'abc',
    ]);
    $invalidResponse->assertStatus(422);

    // 2. Dépôt réussi avec motif par l'opérateur
    $validResponse = $this->postJson('/api/boost/wallet/deposit', [
        'amount' => 15000,
        'user_id' => $this->user->id,
        'reason' => 'Geste commercial suite à un incident réseau',
        'idempotency_key' => 'dep_unique_12345',
    ]);

    $validResponse->assertStatus(201)
        ->assertJsonPath('data.amount', 15000)
        ->assertJsonPath('data.balance_before', 0)
        ->assertJsonPath('data.balance_after', 15000);

    expect($this->walletService->getBalance($this->user))->toBe(15000.0);
});

test('L\'idempotence empêche strictement un double crédit en cas de rejeu', function () {
    $idempotencyKey = 'dep_idemp_key_999';

    // Premier crédit
    $tx1 = $this->walletService->credit(
        account: $this->user,
        amount: 8000,
        type: CreditTransactionType::Deposit,
        description: 'Paiement carte',
        idempotencyKey: $idempotencyKey
    );

    expect($this->walletService->getBalance($this->user))->toBe(8000.0);

    // Deuxième tentative avec la même clé d'idempotence
    $tx2 = $this->walletService->credit(
        account: $this->user,
        amount: 8000,
        type: CreditTransactionType::Deposit,
        description: 'Paiement carte rejoué',
        idempotencyKey: $idempotencyKey
    );

    // Doit retourner la transaction existante sans ré-incrémenter le solde
    expect($tx2->id)->toBe($tx1->id)
        ->and($this->walletService->getBalance($this->user))->toBe(8000.0);

    // Il ne doit y avoir qu'une seule ligne dans le grand livre
    expect(CreditTransaction::where('idempotency_key', $idempotencyKey)->count())->toBe(1);
});

test('L\'idempotence empêche strictement un double débit en cas de requête réseau répétée', function () {
    $this->walletService->credit($this->user, 20000, CreditTransactionType::Deposit, 'Approvisionnement');

    $debitKey = 'debit_idemp_key_444';

    // Premier débit
    $tx1 = $this->walletService->debit(
        account: $this->user,
        amount: 7000,
        type: CreditTransactionType::CampaignDebit,
        description: 'Lancement campagne',
        idempotencyKey: $debitKey
    );

    expect($this->walletService->getBalance($this->user))->toBe(13000.0);

    // Rejeu du même débit
    $tx2 = $this->walletService->debit(
        account: $this->user,
        amount: 7000,
        type: CreditTransactionType::CampaignDebit,
        description: 'Lancement campagne (retry)',
        idempotencyKey: $debitKey
    );

    expect($tx2->id)->toBe($tx1->id)
        ->and($this->walletService->getBalance($this->user))->toBe(13000.0);

    expect(CreditTransaction::where('idempotency_key', $debitKey)->count())->toBe(1);
});

test('Un débit supérieur au solde disponible est strictement refusé et ne produit aucun solde négatif', function () {
    $this->walletService->credit($this->user, 5000, CreditTransactionType::Deposit, 'Solde initial');

    expect(fn () => $this->walletService->debit(
        account: $this->user,
        amount: 5000.01,
        type: CreditTransactionType::CampaignDebit,
        description: 'Débit excessif'
    ))->toThrow(InsufficientCreditException::class);

    // Le solde doit rester intact
    expect($this->walletService->getBalance($this->user))->toBe(5000.0);
    expect(CreditTransaction::where('type', CreditTransactionType::CampaignDebit)->count())->toBe(0);
});

test('Un montant négatif ou nul est rejeté par le service de portefeuille', function () {
    expect(fn () => $this->walletService->credit(
        account: $this->user,
        amount: -500,
        type: CreditTransactionType::Deposit,
        description: 'Montant négatif'
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->walletService->debit(
        account: $this->user,
        amount: 0,
        type: CreditTransactionType::CampaignDebit,
        description: 'Montant nul'
    ))->toThrow(InvalidArgumentException::class);
});

test('Le remboursement d\'un reliquat de campagne crédite le portefeuille avec traçabilité', function () {
    $campaign = Campaign::factory()->create([
        'user_id' => $this->user->id,
        'budget_amount' => '10000.00',
        'spent_amount' => '4000.00',
    ]);

    $tx = $this->walletService->credit(
        account: $this->user,
        amount: 6000.00,
        type: CreditTransactionType::CampaignRefund,
        description: "Remboursement campagne #{$campaign->id}",
        idempotencyKey: "refund_{$campaign->id}",
        reference: $campaign
    );

    expect($this->walletService->getBalance($this->user))->toBe(6000.0)
        ->and($tx->type)->toBe(CreditTransactionType::CampaignRefund)
        ->and($tx->reference_id)->toBe((string) $campaign->id)
        ->and($tx->reference_type)->toBe($campaign->getMorphClass());
});

test('Une exception survenue durant une transaction DB annule l\'intégralité des modifications', function () {
    $wallet = $this->walletService->getOrCreateWallet($this->user);
    $wallet->update(['balance' => '10000.00']);

    try {
        DB::transaction(function () {
            $this->walletService->debit($this->user, 4000, CreditTransactionType::CampaignDebit, 'Débit');
            throw new RuntimeException('Crash simulateur après débit');
        });
    } catch (RuntimeException $e) {
        // Exception capturée
    }

    // Le solde doit avoir rollbacké à 10 000.00
    expect($this->walletService->getBalance($this->user))->toBe(10000.0);
    expect(CreditTransaction::count())->toBe(0);
});
