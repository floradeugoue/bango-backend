<?php

namespace App\Services\Payment\DTO;

use App\Enums\Payment\PaymentStatus;

readonly class GatewayStatusResult
{
    public function __construct(
        public PaymentStatus $status,
        public ?string $gatewayReference = null,
        public ?string $operatorReference = null,
        public ?string $operatorCode = null,
        public array $rawResponse = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null
    ) {}

    public static function confirmed(
        ?string $gatewayReference = null,
        ?string $operatorReference = null,
        ?string $operatorCode = null,
        array $raw = []
    ): self {
        return new self(
            status: PaymentStatus::Confirmed,
            gatewayReference: $gatewayReference,
            operatorReference: $operatorReference,
            operatorCode: $operatorCode,
            rawResponse: $raw
        );
    }

    public static function pending(
        ?string $gatewayReference = null,
        array $raw = []
    ): self {
        return new self(
            status: PaymentStatus::Processing,
            gatewayReference: $gatewayReference,
            rawResponse: $raw
        );
    }

    public static function failed(
        string $errorCode,
        string $errorMessage,
        ?string $gatewayReference = null,
        array $raw = []
    ): self {
        return new self(
            status: PaymentStatus::Failed,
            gatewayReference: $gatewayReference,
            rawResponse: $raw,
            errorCode: $errorCode,
            errorMessage: $errorMessage
        );
    }

    public static function unknown(
        ?string $gatewayReference = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        array $raw = []
    ): self {
        return new self(
            status: PaymentStatus::Unknown,
            gatewayReference: $gatewayReference,
            rawResponse: $raw,
            errorCode: $errorCode,
            errorMessage: $errorMessage
        );
    }
}
