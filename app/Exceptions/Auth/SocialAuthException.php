<?php

namespace App\Exceptions\Auth;

use Exception;

class SocialAuthException extends Exception
{
    public function __construct(
        string $message,
        protected string $kind = 'invalid_token',
        int $code = 422,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getKind(): string
    {
        return $this->kind;
    }
}
