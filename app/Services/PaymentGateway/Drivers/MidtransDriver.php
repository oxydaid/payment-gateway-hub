<?php

namespace App\Services\PaymentGateway\Drivers;

use App\Models\Transaction;
use App\Services\PaymentGateway\GatewayDriverInterface;
use App\Services\PaymentGateway\Responses\GatewayPaymentResponse;
use App\Services\PaymentGateway\Responses\GatewayStatusResponse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MidtransDriver implements GatewayDriverInterface
{
    protected string $serverKey;

    protected string $clientKey;

    protected string $merchantId;

    protected bool $isProduction;

    public function __construct(array $credentials)
    {
        $this->serverKey = $credentials['server_key'] ?? '';
        $this->clientKey = $credentials['client_key'] ?? '';
        $this->merchantId = $credentials['merchant_id'] ?? '';
        $this->isProduction = (bool) ($credentials['is_production'] ?? false);
    }

    public static function getValidationRules(): array
    {
        return [
            'credentials.merchant_id' => ['required', 'string'],
            'credentials.client_key' => ['required', 'string'],
            'credentials.server_key' => ['required', 'string'],
            'credentials.is_production' => ['required', 'boolean'],
        ];
    }

    public function createPayment(Transaction $transaction): GatewayPaymentResponse
    {
        $baseUrl = $this->isProduction
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

        $payload = [
            'transaction_details' => [
                'order_id' => $transaction->reference_id,
                'gross_amount' => (int) $transaction->total_amount,
            ],
            'credit_card' => [
                'secure' => true,
            ],
            'customer_details' => [
                'first_name' => $transaction->merchant->name,
                'email' => 'merchant_'.$transaction->merchant_id.'@example.com',
            ],
        ];

        $paymentCode = $transaction->paymentMethod->code;
        $enabledPayments = $this->mapPaymentMethod($paymentCode);
        if (! empty($enabledPayments)) {
            $payload['enabled_payments'] = $enabledPayments;
        }

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic '.base64_encode($this->serverKey.':'),
        ])
            ->timeout(10)
            ->connectTimeout(3)
            ->retry(3, 100)
            ->post($baseUrl, $payload);

        if ($response->failed()) {
            Log::error('Midtrans payment creation failed', [
                'transaction_id' => $transaction->id,
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            $response->throw();
        }

        $data = $response->json();

        return new GatewayPaymentResponse(
            pgRefId: $data['token'] ?? null,
            checkoutUrl: $data['redirect_url'] ?? null,
            qrisUrl: null,
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
        $statusCode = $payload['status_code'] ?? '';
        $grossAmount = $payload['gross_amount'] ?? '';
        $signatureKey = $payload['signature_key'] ?? '';

        $localSignature = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        if ($signatureKey !== $localSignature) {
            throw new \Exception('Midtrans signature verification failed.');
        }

        $status = $this->mapStatus($payload['transaction_status'] ?? '');
        $paidAt = null;
        if ($status === 'PAID') {
            $paidAt = isset($payload['settlement_time'])
                ? CarbonImmutable::parse($payload['settlement_time'])
                : now();
        }

        return new GatewayStatusResponse(
            pgRefId: $payload['transaction_id'] ?? null,
            status: $status,
            paidAt: $paidAt,
            rawResponse: $payload
        );
    }

    public function checkStatus(Transaction $transaction): GatewayStatusResponse
    {
        $baseUrl = $this->isProduction
            ? 'https://api.midtrans.com/v2'
            : 'https://api.sandbox.midtrans.com/v2';

        $url = $baseUrl.'/'.$transaction->reference_id.'/status';

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic '.base64_encode($this->serverKey.':'),
        ])
            ->timeout(10)
            ->connectTimeout(3)
            ->retry(3, 100)
            ->get($url);

        if ($response->failed()) {
            Log::error('Midtrans status check failed', [
                'transaction_id' => $transaction->id,
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            $response->throw();
        }

        $data = $response->json();
        $status = $this->mapStatus($data['transaction_status'] ?? '');
        $paidAt = null;
        if ($status === 'PAID') {
            $paidAt = isset($data['settlement_time'])
                ? CarbonImmutable::parse($data['settlement_time'])
                : now();
        }

        return new GatewayStatusResponse(
            pgRefId: $data['transaction_id'] ?? null,
            status: $status,
            paidAt: $paidAt,
            rawResponse: $data
        );
    }

    protected function mapStatus(string $midtransStatus): string
    {
        return match ($midtransStatus) {
            'capture', 'settlement' => 'PAID',
            'pending' => 'PENDING',
            'deny', 'cancel' => 'FAILED',
            'expire' => 'EXPIRED',
            'refund', 'partial_refund' => 'REFUNDED',
            default => 'PENDING',
        };
    }

    protected function mapPaymentMethod(string $code): array
    {
        return match ($code) {
            'bca_va' => ['bca_va', 'bank_transfer'],
            'bni_va' => ['bni_va', 'bank_transfer'],
            'bri_va' => ['bri_va', 'bank_transfer'],
            'mandiri_va' => ['echannel', 'bank_transfer'],
            'permata_va' => ['permata_va', 'bank_transfer'],
            'qris' => ['gopay', 'shopeepay', 'qris'],
            'gopay' => ['gopay'],
            'shopeepay' => ['shopeepay'],
            default => [],
        };
    }
}
