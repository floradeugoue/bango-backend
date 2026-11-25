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
use App\Services\Payment\Gateways\MonetbilGateway;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    /**
     * Rembourse en argent sur le moyen de paiement d'origine le solde de crédits BANGO restant
     * avant la suppression d'un compte ou à la demande de l'utilisateur.
     *
     * @return array{
     *     refunded: bool,
     *     amount: float,
     *     currency: string,
     *     refund_channel: string,
     *     phone: string,
     *     operator: ?string,
     *     external_reference: string,
     *     credit_transaction_id: int
     * }|null
     */
    public function refundBalanceBeforeAccountDeletion(
        User $user,
        ?string $phone = null,
        ?string $operator = null,
        bool $throwOnValidation = true
    ): ?array {
        $balance = $this->getBalance($user);
        if ($balance <= 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $throwOnValidation) {
            $wallet = $this->getOrCreateWallet($user);

            /** @var CreditWallet $lockedWallet */
            $lockedWallet = CreditWallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            $currentBalance = (int) round((float) $lockedWallet->balance);

            if ($currentBalance <= 0) {
                return null;
            }

            // Ne rembourser en argent que le crédit acheté, jamais les bonus
            $totalPurchased = (int) CreditTransaction::where('wallet_id', $lockedWallet->id)
                ->where('type', CreditTransactionType::Purchase)
                ->sum('amount');

            if ($totalPurchased <= 0) {
                if ($throwOnValidation) {
                    throw ValidationException::withMessages([
                        'balance' => ['Seuls les crédits achetés avec de l\'argent réel peuvent être remboursés en argent. Vos crédits promotionnels ou bonus ne sont pas éligibles au remboursement.'],
                    ]);
                }

                return null;
            }

            $totalCashRefunded = (int) CreditTransaction::where('wallet_id', $lockedWallet->id)
                ->where('source', 'credit_balance_cash_refund')
                ->sum('amount');

            $totalDebits = (int) CreditTransaction::where('wallet_id', $lockedWallet->id)
                ->whereIn('type', [CreditTransactionType::Debit, CreditTransactionType::CampaignDebit])
                ->sum('amount');

            $remainingPurchasedCredits = max(0, $totalPurchased - $totalCashRefunded - $totalDebits);
            $amountToRefund = min($currentBalance, $remainingPurchasedCredits);

            if ($amountToRefund <= 0) {
                if ($throwOnValidation) {
                    throw ValidationException::withMessages([
                        'balance' => ['Vos crédits achetés ont déjà été intégralement consommés. Votre solde restant provient de bonus non remboursables en argent.'],
                    ]);
                }

                return null;
            }

            // Ignorer le phone du client : verser uniquement au numéro qui a payé, lu dans la transaction d'origine
            $purchaseTx = PaymentTransaction::where('user_id', $user->id)
                ->where('service_type', PaymentServiceType::Credit)
                ->where('status', PaymentStatus::Confirmed)
                ->latest('id')
                ->first();

            $targetPhone = null;
            $targetOperator = null;

            if ($purchaseTx) {
                $txMeta = $purchaseTx->metadata ?? [];
                $targetPhone = $txMeta['phone'] ?? $txMeta['phonenumber'] ?? $txMeta['phone_number'] ?? null;
                $targetOperator = $txMeta['operator'] ?? null;

                if (! $targetPhone) {
                    $attempt = $purchaseTx->attempts()->latest('id')->first();
                    $targetPhone = $attempt?->metadata['phone'] ?? $attempt?->metadata['phonenumber'] ?? null;
                    $targetOperator = $targetOperator ?: ($attempt?->metadata['operator'] ?? null);
                }
            }

            if (! $targetPhone) {
                $anyTx = PaymentTransaction::where('user_id', $user->id)
                    ->where('status', PaymentStatus::Confirmed)
                    ->latest('id')
                    ->first();

                if ($anyTx) {
                    $txMeta = $anyTx->metadata ?? [];
                    $targetPhone = $txMeta['phone'] ?? $txMeta['phonenumber'] ?? $txMeta['phone_number'] ?? null;
                    $targetOperator = $txMeta['operator'] ?? null;

                    if (! $targetPhone) {
                        $attempt = $anyTx->attempts()->latest('id')->first();
                        $targetPhone = $attempt?->metadata['phone'] ?? $attempt?->metadata['phonenumber'] ?? null;
                        $targetOperator = $targetOperator ?: ($attempt?->metadata['operator'] ?? null);
                    }
                }
            }

            if (empty($targetPhone)) {
                if ($throwOnValidation) {
                    throw ValidationException::withMessages([
                        'phone' => ['Numéro de paiement d\'origine introuvable. Veuillez contacter le support client pour effectuer votre remboursement.'],
                    ]);
                }

                return null;
            }

            $currency = $lockedWallet->currency ?: ($purchaseTx?->currency ?: 'XAF');
            $ref = 'CRED-REFUND-'.$user->id.'-'.strtoupper(Str::random(6));

            $disburse = app(MonetbilGateway::class)->disburseToMobileMoney(
                phone: (string) $targetPhone,
                amount: (float) $amountToRefund,
                currency: $currency,
                reference: $ref,
                operator: is_string($targetOperator) ? $targetOperator : null,
                countryCode: $user->country_code,
                metadata: [
                    'user_id' => $user->id,
                    'reason' => 'Remboursement du solde de crédit BANGO acheté sur le moyen de paiement d\'origine',
                ]
            );

            $creditTx = $this->debit(
                account: $lockedWallet,
                amount: $amountToRefund,
                source: 'credit_balance_cash_refund',
                reference: $ref,
                description: 'Remboursement en argent du solde de crédits achetés sur le moyen de paiement d\'origine',
                idempotencyKey: 'credit_cash_refund_'.$user->id.'_'.$amountToRefund.'_'.now()->format('YmdHis'),
                metadata: [
                    'refund_channel' => 'original_payment_method',
                    'phone' => $disburse['phone'],
                    'operator' => $disburse['operator'],
                    'external_reference' => $disburse['external_reference'],
                ]
            );

            return [
                'refunded' => true,
                'amount' => (float) $amountToRefund,
                'currency' => $currency,
                'refund_channel' => 'original_payment_method',
                'phone' => $disburse['phone'],
                'operator' => $disburse['operator'],
                'external_reference' => $disburse['external_reference'],
                'credit_transaction_id' => $creditTx->id,
            ];
        });
    }
}
