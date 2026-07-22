<?php

namespace App\Services\PaymentGateway;

use App\Models\Transaction;
use App\Services\PaymentGateway\Responses\GatewayPaymentResponse;
use App\Services\PaymentGateway\Responses\GatewayStatusResponse;

interface GatewayDriverInterface
{
    /**
     * Create a payment request to the Payment Gateway.
     */
    public function createPayment(Transaction $transaction): GatewayPaymentResponse;

    /**
     * Validate the webhook signature and extract transaction status.
     *
     * @throws \Exception If signature is invalid
     */
    public function handleWebhook(array $payload, array $headers = []): GatewayStatusResponse;

    /**
     * Directly query the Payment Gateway to check the transaction status.
     */
    public function checkStatus(Transaction $transaction): GatewayStatusResponse;
}
