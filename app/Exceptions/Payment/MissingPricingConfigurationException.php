<?php

namespace App\Exceptions\Payment;

use RuntimeException;

class MissingPricingConfigurationException extends RuntimeException
{
    public function __construct(string $message = 'La configuration tarifaire ou de frais est manquante pour cette passerelle.')
    {
        parent::__construct($message);
    }
}
