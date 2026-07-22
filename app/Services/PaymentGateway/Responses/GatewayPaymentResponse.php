<?php

namespace App\Services\PaymentGateway\Responses;

class GatewayPaymentResponse
{
    public function __construct(
        public ?string $pgRefId,
        public ?string $checkoutUrl,
        public ?string $qrisUrl,
        public string $status,
        public array $rawResponse
    ) {}
}
