<?php

namespace App\Exceptions\Payment;

use DomainException;

class IdempotencyConflictException extends DomainException
{
    public function __construct(string $message = 'Une transaction différente existe déjà avec cette clé d\'idempotence.')
    {
        parent::__construct($message);
    }
}
