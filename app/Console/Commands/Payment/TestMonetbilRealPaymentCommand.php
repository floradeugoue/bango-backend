<?php

namespace App\Console\Commands\Payment;

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\User;
use App\Services\Payment\PaymentEngineService;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PricingService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TestMonetbilRealPaymentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment:test-monetbil 
                            {--phone=658414656 : Numéro de téléphone Orange ou MTN} 
                            {--amount=100 : Montant en XAF à tester (défaut 100 XAF)} 
                            {--user-id=4 : ID de l\'utilisateur BANGO} 
                            {--operator=orange : Opérateur télécom (orange ou mtn)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Déclenche un test réel de paiement Mobile Money via Monetbil (Push USSD) et suit le statut en direct';

    /**
     * Execute the console command.
     */
    public function handle(PricingService $pricingService, PaymentEngineService $paymentEngine): int
    {
        $phone = (string) $this->option('phone');
        $amount = (float) $this->option('amount');
        $userId = (int) $this->option('user-id');
        $operator = (string) $this->option('operator');

        $this->info('=== TEST DE PAIEMENT RÉEL MONETBIL ===');
        $this->line("Numéro cible : <comment>{$phone}</comment>");
        $this->line("Montant : <comment>{$amount} XAF</comment>");
        $this->line("Opérateur : <comment>{$operator}</comment>");
        $this->line('Notify URL (Webhook) : <comment>'.config('payment.gateways.monetbil.notify_url').'</comment>');

        $user = User::find($userId) ?? User::first();
        if (! $user) {
            $this->error('Aucun utilisateur trouvé en base de données.');

            return self::FAILURE;
        }

        $this->line("Utilisateur : <info>{$user->name}</info> (ID: {$user->id})");

        // 1. Calcul du devis serveur
        $this->info("\n1. Établissement du devis certifié serveur...");
        $quote = $pricingService->calculateQuote(
            serviceType: PaymentServiceType::Visit,
            customAmount: $amount,
            gateway: 'monetbil'
        );

        $this->table(
            ['Montant de base', 'Frais passerelle', 'Taxes', 'Total dû'],
            [[$quote->amount.' XAF', $quote->feeAmount.' XAF', $quote->taxAmount.' XAF', $quote->totalAmount.' XAF']]
        );

        // 2. Création de la transaction
        $idempotencyKey = 'test_'.Str::random(12);
        $this->info("2. Création de la transaction (Clé: {$idempotencyKey})...");
        $transaction = $paymentEngine->createOrRetrieveTransaction(
            user: $user,
            quote: $quote,
            idempotencyKey: $idempotencyKey,
            context: [
                'phone' => $phone,
                'operator' => $operator,
                'channel' => 'mobile_money',
            ]
        );

        $this->line("Transaction créée : <info>{$transaction->reference}</info> (ID: {$transaction->id})");

        // 3. Déclenchement de l'appel direct Monetbil
        $this->info("\n3. Envoi de la requête Direct API vers Monetbil (placePayment)...");
        $this->line('Attente de la réponse Monetbil...');

        $attempt = $transaction->createAttempt(
            provider: PaymentProvider::Monetbil,
            channel: PaymentChannel::MobileMoney,
            externalReference: null,
            metadata: ['phone' => $phone, 'operator' => $operator]
        );

        /** @var PaymentGatewayManager $gatewayManager */
        $gatewayManager = app(PaymentGatewayManager::class);
        $gateway = $gatewayManager->driver('monetbil');

        try {
            $initiateResult = $gateway->initiatePayment($transaction, [
                'phone' => $phone,
                'operator' => $operator,
            ]);
        } catch (\Throwable $e) {
            $attempt->markFailed(errorCode: 'EXCEPTION', errorMessage: $e->getMessage());
            $this->error("Erreur lors de l'initiation Monetbil : ".$e->getMessage());

            return self::FAILURE;
        }

        if (! $initiateResult->isSuccessful) {
            $attempt->markFailed(errorCode: 'GATEWAY_ERROR', errorMessage: $initiateResult->errorMessage ?? 'Erreur inconnue');
            $this->error("\n❌ Monetbil a refusé la requête : ".($initiateResult->errorMessage ?? 'Échec'));

            return self::FAILURE;
        }

        $attempt->markSuccessful(
            externalReference: $initiateResult->gatewayReference,
            metadata: ['ussd_prompt' => $initiateResult->ussdPrompt]
        );

        $transaction->external_reference = $initiateResult->gatewayReference;
        $transaction->transitionTo(
            targetStatus: PaymentStatus::Processing,
            source: PaymentEventSource::Gateway,
            reason: 'Demande USSD acceptée par Monetbil',
            payload: ['paymentId' => $initiateResult->gatewayReference]
        );

        $this->info("\n=== RÉSULTAT DE L'INITIATION MONETBIL ===");
        $this->line('Statut : <info>processing</info>');
        $this->line("ID Paiement Monetbil (paymentId) : <comment>{$initiateResult->gatewayReference}</comment>");
        $this->line("Message : <info>{$initiateResult->ussdPrompt}</info>");

        // 4. Suivi en direct du paiement (Polling checkPayment)
        $this->info("\n4. Suivi en direct : Regardez votre téléphone ({$phone}) et validez le débit USSD.");
        $this->line('Vérification auprès de Monetbil toutes les 5 secondes (max 120s)...');

        $startTime = time();
        $timeout = 120;

        while ((time() - $startTime) < $timeout) {
            sleep(5);
            $elapsed = time() - $startTime;

            $statusResult = $gateway->checkStatus($transaction);

            $this->line("[+{$elapsed}s] Statut Monetbil : <comment>{$statusResult->status->value}</comment>");

            if ($statusResult->status === PaymentStatus::Confirmed) {
                $paymentEngine->confirmAndFulfill(
                    transaction: $transaction,
                    externalReference: $initiateResult->gatewayReference,
                    source: PaymentEventSource::Gateway,
                    fulfillmentDetails: [
                        'operator_reference' => $statusResult->operatorReference,
                    ]
                );

                $this->info("\n🎉 PAIEMENT CONFIRMÉ AVEC SUCCÈS !");
                $this->line("Montant débité : {$transaction->total_amount} XAF");
                $this->line("Référence : {$transaction->reference}");
                if ($statusResult->operatorReference) {
                    $this->line("Réf. Opérateur Télécom : {$statusResult->operatorReference}");
                }

                return self::SUCCESS;
            }

            if ($statusResult->status === PaymentStatus::Failed) {
                $transaction->transitionTo(
                    targetStatus: PaymentStatus::Failed,
                    source: PaymentEventSource::Gateway,
                    reason: $statusResult->rawResponse['message'] ?? 'Paiement échoué chez Monetbil'
                );
                $this->error("\n❌ Le paiement a été rejeté ou a échoué.");

                return self::FAILURE;
            }

            if ($statusResult->status === PaymentStatus::Cancelled) {
                $transaction->transitionTo(
                    targetStatus: PaymentStatus::Cancelled,
                    source: PaymentEventSource::Gateway,
                    reason: 'Annulé'
                );
                $this->warn("\n⚠️ Le paiement a été annulé.");

                return self::FAILURE;
            }
        }

        $this->warn("\n⏱️ Délai de 120 secondes écoulé sans confirmation finale.");

        return self::SUCCESS;
    }
}
