<?php

namespace App\Services\Boost;

use App\Enums\Boost\CreditTransactionType;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Exceptions\Boost\InsufficientCreditException;
use App\Models\Boost\CreditTransaction;
use App\Models\Boost\CreditWallet;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreditWalletService
{
    /**
     * Récupère ou initialise de manière atomique le portefeuille de crédits de l'utilisateur.
     */
    public function getOrCreateWallet(User|int $user, string $currency = 'XAF'): CreditWallet
    {
        $userId = $user instanceof User ? $user->id : (int) $user;

        return CreditWallet::firstOrCreate(
            ['user_id' => $userId],
            [
                'balance' => 0,
                'currency' => strtoupper($currency),
            ]
        );
    }

    /**
     * Récupère le solde disponible en crédits de l'utilisateur.
     */
    public function getBalance(User|int $user): float
    {
        $userId = $user instanceof User ? $user->id : (int) $user;
        $wallet = CreditWallet::where('user_id', $userId)->first();

        return $wallet ? (float) $wallet->balance : 0.0;
    }

    /**
     * Crédite le portefeuille après confirmation définitive d'un paiement PAY.
     * Règle absolue : 1 FCFA payé = 1 Crédit BANGO.
     * Protégé contre les doublons, les webhooks rejoués et la concurrence.
     */
    public function creditFromPaymentTransaction(PaymentTransaction $transaction): CreditTransaction
    {
        if ($transaction->status !== PaymentStatus::Confirmed) {
            throw new InvalidArgumentException("La transaction de paiement {$transaction->reference} n'est pas confirmée.");
        }

        if ($transaction->service_type !== PaymentServiceType::Credit) {
            throw new InvalidArgumentException("La transaction {$transaction->reference} n'est pas de type Crédit BANGO.");
        }

        // Règle d'or immuable : 1 FCFA payé = 1 Crédit BANGO
        $credits = (int) round((float) $transaction->amount);
        if ($credits <= 0) {
            throw new InvalidArgumentException('Le montant de crédits calculé doit être strictement positif.');
        }

        $idempotencyKey = "credit_purchase_{$transaction->reference}";

        return DB::transaction(function () use ($transaction, $credits, $idempotencyKey) {
            // 1. Idempotence absolue : vérifier si l'écriture existe déjà
            $existingTx = CreditTransaction::where('payment_transaction_id', $transaction->id)
                ->orWhere('idempotency_key', $idempotencyKey)
                ->orWhere(function ($q) use ($transaction) {
                    $q->where('source', 'pay')->where('reference', $transaction->reference);
                })
                ->first();

            if ($existingTx !== null) {
                return $existingTx;
            }

            // 2. Acquisition atomique du verrou sur le portefeuille utilisateur
            $wallet = $this->getOrCreateWallet($transaction->user_id);

            /** @var CreditWallet $lockedWallet */
            $lockedWallet = CreditWallet::where('id', $wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Double vérification sous verrou
            $existingUnderLock = CreditTransaction::where('payment_transaction_id', $transaction->id)
                ->orWhere('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingUnderLock !== null) {
                return $existingUnderLock;
            }

            $balanceBefore = (int) $lockedWallet->balance;
            $balanceAfter = $balanceBefore + $credits;

            $lockedWallet->balance = $balanceAfter;
            $lockedWallet->save();

            return CreditTransaction::create([
                'wallet_id' => $lockedWallet->id,
                'user_id' => $lockedWallet->user_id,
                'type' => CreditTransactionType::Purchase,
                'amount' => $credits,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'source' => 'pay',
                'reference' => $transaction->reference,
                'payment_transaction_id' => $transaction->id,
                'reference_type' => PaymentTransaction::class,
                'reference_id' => (string) $transaction->id,
                'idempotency_key' => $idempotencyKey,
                'description' => sprintf('Achat de %s Crédits BANGO (1 FCFA = 1 Crédit)', number_format($credits, 0, ',', ' ')),
                'metadata' => [
                    'payment_reference' => $transaction->reference,
                    'provider' => $transaction->provider?->value,
                    'channel' => $transaction->channel?->value,
                    'currency' => $transaction->currency,
                    'amount_fcfa' => $credits,
                    'conversion_rate' => '1 FCFA = 1 Crédit BANGO',
                ],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Crédite le portefeuille de manière atomique, idempotente et auditée.
     *
     * @param  string|float|int  $amount  Montant strictement positif
     * @param  CreditTransactionType  $type  Nature du crédit
     * @param  string  $description  Libellé de l'opération
     * @param  string|null  $idempotencyKey  Clé d'idempotence unique
     * @param  Model|null  $reference  Entité liée
     * @param  array<string, mixed>|null  $metadata  Données contextuelles
     */
    public function credit(
        User|CreditWallet $account,
        string|float|int $amount,
        CreditTransactionType $type = CreditTransactionType::Deposit,
        string $description = 'Recharge de crédits BANGO',
        ?string $idempotencyKey = null,
        ?Model $reference = null,
        ?array $metadata = null,
        string $source = 'pay',
        ?string $refCode = null,
        ?int $paymentTransactionId = null
    ): CreditTransaction {
        $intAmount = (int) round((float) $amount);
        if ($intAmount <= 0) {
            throw new InvalidArgumentException('Le montant à créditer doit être strictement positif.');
        }

        return DB::transaction(function () use ($account, $intAmount, $type, $description, $idempotencyKey, $reference, $metadata, $source, $refCode, $paymentTransactionId) {
            // Vérification stricte d'idempotence
            if ($idempotencyKey !== null) {
                $existingTx = CreditTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existingTx !== null) {
                    return $existingTx;
                }
            }

            $wallet = $account instanceof CreditWallet
                ? $account
                : $this->getOrCreateWallet($account);

            /** @var CreditWallet $lockedWallet */
            $lockedWallet = CreditWallet::where('id', $wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($idempotencyKey !== null) {
                $existingTx = CreditTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existingTx !== null) {
                    return $existingTx;
                }
            }

            $balanceBefore = (int) $lockedWallet->balance;
            $balanceAfter = $balanceBefore + $intAmount;

            $lockedWallet->balance = $balanceAfter;
            $lockedWallet->save();

            return CreditTransaction::create([
                'wallet_id' => $lockedWallet->id,
                'user_id' => $lockedWallet->user_id,
                'type' => $type,
                'amount' => $intAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'source' => $source,
                'reference' => $refCode ?? ($reference ? (string) $reference->getKey() : ($idempotencyKey ?? Str::uuid()->toString())),
                'payment_transaction_id' => $paymentTransactionId,
                'reference_type' => $reference ? $reference->getMorphClass() : null,
                'reference_id' => $reference ? (string) $reference->getKey() : null,
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Débite le portefeuille de manière atomique, protégée contre la concurrence et sans solde négatif.
     *
     * @param  string|float|int  $amount  Montant strictement positif
     * @param  CreditTransactionType|string  $source  Origine métier (boost, visit, etc.) ou type
     * @param  string|Model|null  $reference  Référence unique de l'opération
     * @param  string|null  $description  Libellé de l'opération
     * @param  string|null  $idempotencyKey  Clé d'idempotence unique
     * @param  Model|null  $referenceModel  Entité liée
     * @param  array<string, mixed>|null  $metadata  Données contextuelles
     * @param  CreditTransactionType|null  $type  Type d'écriture
     *
     * @throws InsufficientCreditException
     */
    public function debit(
        User|CreditWallet $account,
        string|float|int $amount,
        CreditTransactionType|string $source = 'boost',
        string|Model|null $reference = null,
        ?string $description = null,
        ?string $idempotencyKey = null,
        ?Model $referenceModel = null,
        ?array $metadata = null,
        ?CreditTransactionType $type = null
    ): CreditTransaction {
        $floatAmount = (float) $amount;
        if ($floatAmount <= 0) {
            throw new InvalidArgumentException('Le montant à débiter doit être strictement positif.');
        }

        $intAmount = (int) round($floatAmount);
        if ($intAmount <= 0) {
            throw new InvalidArgumentException('Le montant à débiter doit être strictement positif.');
        }

        // Support rétrocompatible pour les anciens appels (CreditTransactionType passé en 3e argument)
        if ($source instanceof CreditTransactionType) {
            $type = $source;
            $source = match ($type) {
                CreditTransactionType::CampaignDebit => 'boost',
                CreditTransactionType::Debit => 'debit',
                default => 'cb',
            };
            if (is_string($reference) && $description === null) {
                $description = $reference;
                $reference = $idempotencyKey ?? Str::uuid()->toString();
            }
        }

        if ($reference instanceof Model) {
            $referenceModel = $reference;
            $reference = (string) $reference->getKey();
        }

        $type ??= CreditTransactionType::Debit;
        $reference ??= ($idempotencyKey ?? Str::uuid()->toString());
        $description ??= 'Consommation de crédits BANGO';

        return DB::transaction(function () use ($account, $floatAmount, $intAmount, $source, $reference, $description, $idempotencyKey, $referenceModel, $metadata, $type) {
            // Vérification stricte d'idempotence
            if ($idempotencyKey !== null) {
                $existingTx = CreditTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existingTx !== null) {
                    return $existingTx;
                }
            }

            if ($source && $reference) {
                $existingRef = CreditTransaction::where('source', $source)
                    ->where('reference', $reference)
                    ->first();
                if ($existingRef !== null) {
                    return $existingRef;
                }
            }

            $wallet = $account instanceof CreditWallet
                ? $account
                : $this->getOrCreateWallet($account);

            /** @var CreditWallet $lockedWallet */
            $lockedWallet = CreditWallet::where('id', $wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Double vérification sous verrou
            if ($idempotencyKey !== null) {
                $existingTx = CreditTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existingTx !== null) {
                    return $existingTx;
                }
            }
            if ($source && $reference) {
                $existingRef = CreditTransaction::where('source', $source)
                    ->where('reference', $reference)
                    ->first();
                if ($existingRef !== null) {
                    return $existingRef;
                }
            }

            $balanceBefore = (int) $lockedWallet->balance;

            // Règle d'or : le solde ne doit jamais devenir négatif
            if ($balanceBefore < $floatAmount || $balanceBefore < $intAmount) {
                throw new InsufficientCreditException(
                    currentBalance: $balanceBefore,
                    requiredAmount: $floatAmount,
                    message: sprintf(
                        'Solde insuffisant (%s crédits disponibles, %s requis).',
                        number_format($balanceBefore, 0, ',', ' '),
                        number_format($floatAmount, 2, ',', ' ')
                    )
                );
            }

            $balanceAfter = $balanceBefore - $intAmount;
            $lockedWallet->balance = $balanceAfter;
            $lockedWallet->save();

            return CreditTransaction::create([
                'wallet_id' => $lockedWallet->id,
                'user_id' => $lockedWallet->user_id,
                'type' => $type,
                'amount' => $intAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'source' => $source,
                'reference' => $reference,
                'payment_transaction_id' => null,
                'reference_type' => $referenceModel ? $referenceModel->getMorphClass() : null,
                'reference_id' => $referenceModel ? (string) $referenceModel->getKey() : null,
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Liste paginée des mouvements du grand livre pour un utilisateur avec filtrage par type, source et dates.
     */
    public function getTransactions(
        User $user,
        int $perPage = 15,
        ?string $type = null,
        ?string $source = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): LengthAwarePaginator {
        $query = CreditTransaction::where('user_id', $user->id);

        if ($type) {
            $query->where('type', $type);
        }

        if ($source) {
            $query->where('source', $source);
        }

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        return $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
