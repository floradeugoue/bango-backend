<?php

namespace App\Exceptions\Payment;

use App\Enums\Payment\PaymentStatus;
use DomainException;

class InvalidPaymentTransitionException extends DomainException
{
    public function __construct(PaymentStatus $from, PaymentStatus $to)
    {
        parent::__construct(
            sprintf('Transition de statut de paiement non autorisée : de "%s" (%s) vers "%s" (%s).',
                $from->value,
                $from->label(),
                $to->value,
                $to->label()
            )
        );
    }
}
