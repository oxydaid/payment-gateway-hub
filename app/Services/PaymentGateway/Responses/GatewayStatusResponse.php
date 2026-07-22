<?php

namespace App\Services\PaymentGateway\Responses;

use Carbon\CarbonImmutable;

class GatewayStatusResponse
{
    public function __construct(
        public ?string $pgRefId,
        public string $status, // PENDING, PAID, FAILED, EXPIRED, REFUNDED
        public ?CarbonImmutable $paidAt,
        public array $rawResponse
    ) {}
}
