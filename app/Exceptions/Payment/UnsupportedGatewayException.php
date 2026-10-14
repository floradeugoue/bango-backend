<?php

namespace App\Exceptions\Payment;

use DomainException;

class UnsupportedGatewayException extends DomainException
{
    public function __construct(string $gateway)
    {
        parent::__construct(sprintf('La passerelle de paiement "%s" n\'est pas supportée, est désactivée ou est mal configurée.', $gateway));
    }
}
