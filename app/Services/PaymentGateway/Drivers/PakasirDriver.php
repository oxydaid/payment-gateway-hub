<?php

namespace App\Services\PaymentGateway\Drivers;

use App\Models\Transaction;
use App\Services\PaymentGateway\GatewayDriverInterface;
use App\Services\PaymentGateway\Responses\GatewayPaymentResponse;
use App\Services\PaymentGateway\Responses\GatewayStatusResponse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PakasirDriver implements GatewayDriverInterface
{
    protected string $projectSlug;

    protected string $apiKey;

    public function __construct(array $credentials)
    {
        $this->projectSlug = $credentials['project_slug'] ?? '';
        $this->apiKey = $credentials['api_key'] ?? '';
    }

    public static function getValidationRules(): array
    {
        return [
            'credentials.project_slug' => ['required', 'string'],
            'credentials.api_key' => ['required', 'string'],
        ];
    }

    public function createPayment(Transaction $transaction): GatewayPaymentResponse
    {
        $paymentCode = $this->mapPaymentMethod($transaction->paymentMethod->code);
        $url = 'https://app.pakasir.com/api/transactioncreate/'.$paymentCode;

        $response = Http::timeout(10)
            ->connectTimeout(3)
            ->retry(3, 100)
            ->post($url, [
                'project' => $this->projectSlug,
                'order_id' => $transaction->reference_id,
                'amount' => (int) $transaction->total_amount,
                'api_key' => $this->apiKey,
            ]);

        if ($response->failed()) {
            Log::error('Pakasir payment creation failed', [
                'transaction_id' => $transaction->id,
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            $response->throw();
        }

        $data = $response->json();
        $payment = $data['payment'] ?? null;

        if (! $payment) {
            throw new \Exception('Pakasir API error: '.($data['message'] ?? 'Failed to create payment'));
        }

        $paymentNumber = $payment['payment_number'] ?? null;
        $qrisUrl = null;
        if ($paymentCode === 'qris' && ! empty($paymentNumber)) {
            $qrisUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data='.urlencode($paymentNumber);
        }

        $checkoutUrl = 'https://app.pakasir.com/pay/'.$this->projectSlug.'/'.(int) $transaction->total_amount.'?order_id='.$transaction->reference_id;

        return new GatewayPaymentResponse(
            pgRefId: $payment['order_id'] ?? $transaction->reference_id,
            checkoutUrl: $checkoutUrl,
            qrisUrl: $qrisUrl,
            status: 'PENDING',
            rawResponse: $data
        );
    }

    /**
     * @throws \Exception
     */
    public function handleWebhook(array $payload, array $headers = []): GatewayStatusResponse
    {
        $orderId = $payload['order_id'] ?? '';
        if (empty($orderId)) {
            throw new \Exception('Pakasir webhook missing order_id.');
        }

        $status = $this->mapStatus($payload['status'] ?? '');
        $paidAt = null;
        if ($status === 'PAID') {
            $paidAt = isset($payload['completed_at'])
                ? CarbonImmutable::parse($payload['completed_at'])
                : now();
        }

        return new GatewayStatusResponse(
            pgRefId: $orderId,
            status: $status,
            paidAt: $paidAt,
            rawResponse: $payload
        );
    }

    public function checkStatus(Transaction $transaction): GatewayStatusResponse
    {
        $baseUrl = 'https://app.pakasir.com/api/transactiondetail';

        $response = Http::timeout(10)
            ->connectTimeout(3)
            ->retry(3, 100)
            ->get($baseUrl, [
                'project' => $this->projectSlug,
                'amount' => (int) $transaction->total_amount,
                'order_id' => $transaction->reference_id,
                'api_key' => $this->apiKey,
            ]);

        if ($response->failed()) {
            Log::error('Pakasir status check failed', [
                'transaction_id' => $transaction->id,
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            $response->throw();
        }

        $data = $response->json();
        $transactionData = $data['transaction'] ?? null;

        if (! $transactionData) {
            throw new \Exception('Transaction not found on Pakasir.');
        }

        $status = $this->mapStatus($transactionData['status'] ?? '');
        $paidAt = null;
        if ($status === 'PAID') {
            $paidAt = isset($transactionData['completed_at'])
                ? CarbonImmutable::parse($transactionData['completed_at'])
                : now();
        }

        return new GatewayStatusResponse(
            pgRefId: $transactionData['order_id'] ?? $transaction->reference_id,
            status: $status,
            paidAt: $paidAt,
            rawResponse: $data
        );
    }

    protected function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'completed' => 'PAID',
            'pending' => 'PENDING',
            'expired' => 'EXPIRED',
            'failed', 'cancelled' => 'FAILED',
            default => 'PENDING',
        };
    }

    protected function mapPaymentMethod(string $code): string
    {
        $code = strtolower($code);

        return match ($code) {
            'qris' => 'qris',
            'bca_va' => 'atm_bersama_va',
            'bni_va' => 'bni_va',
            'bri_va' => 'bri_va',
            'mandiri_va' => 'atm_bersama_va',
            'permata_va' => 'permata_va',
            default => 'qris',
        };
    }
}
