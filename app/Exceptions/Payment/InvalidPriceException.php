<?php

namespace App\Exceptions\Payment;

use DomainException;

class InvalidPriceException extends DomainException
{
    public function __construct(string $message = 'Le tarif demandé est introuvable, inactif ou expiré.')
    {
        parent::__construct($message);
    }
}
