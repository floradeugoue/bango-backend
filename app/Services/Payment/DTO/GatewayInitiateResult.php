<?php

namespace App\Services\Payment\DTO;

readonly class GatewayInitiateResult
{
    public function __construct(
        public bool $isSuccessful,
        public ?string $gatewayReference,
        public ?string $operatorCode = null,
        public ?string $ussdPrompt = null,
        public int $timeoutSeconds = 120,
        public array $rawResponse = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null
    ) {}

    public static function success(
        string $gatewayReference,
        ?string $operatorCode = null,
        ?string $ussdPrompt = null,
        int $timeoutSeconds = 120,
        array $raw = []
    ): self {
        return new self(
            isSuccessful: true,
            gatewayReference: $gatewayReference,
            operatorCode: $operatorCode,
            ussdPrompt: $ussdPrompt,
            timeoutSeconds: $timeoutSeconds,
            rawResponse: $raw
        );
    }

    public static function failure(string $errorCode, string $errorMessage, array $raw = []): self
    {
        return new self(
            isSuccessful: false,
            gatewayReference: null,
            rawResponse: $raw,
            errorCode: $errorCode,
            errorMessage: $errorMessage
        );
    }
}
