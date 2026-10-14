<?php

namespace App\Services\Payment\Contracts;

use App\Models\Payment\PaymentTransaction;
use App\Services\Payment\DTO\GatewayInitiateResult;
use App\Services\Payment\DTO\GatewayStatusResult;

interface PaymentGatewayInterface
{
    /**
     * Code identifiant unique du fournisseur (ex: 'monetbil', 'flutterwave').
     */
    public function getIdentifier(): string;

    /**
     * Vérifie si la passerelle est activée et dispose des clés d'accès requises.
     */
    public function isAvailable(): bool;

    /**
     * Initialise une session de paiement auprès de la passerelle.
     *
     * @param  PaymentTransaction  $transaction  Transaction locale en attente
     * @param  array<string, mixed>  $params  Paramètres supplémentaires (téléphone, opérateur, redirection)
     */
    public function initiatePayment(PaymentTransaction $transaction, array $params = []): GatewayInitiateResult;

    /**
     * Vérifie directement l'état d'une transaction auprès de la passerelle.
     *
     * @param  PaymentTransaction  $transaction  Transaction locale à vérifier
     */
    public function checkStatus(PaymentTransaction $transaction): GatewayStatusResult;
}
