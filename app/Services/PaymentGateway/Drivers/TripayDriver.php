<?php

namespace App\Services\PaymentGateway\Drivers;

use App\Models\Transaction;
use App\Services\PaymentGateway\GatewayDriverInterface;
use App\Services\PaymentGateway\Responses\GatewayPaymentResponse;
use App\Services\PaymentGateway\Responses\GatewayStatusResponse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TripayDriver implements GatewayDriverInterface
{
    protected string $merchantCode;

    protected string $apiKey;

    protected string $privateKey;

    protected bool $isProduction;

    public function __construct(array $credentials)
    {
        $this->merchantCode = $credentials['merchant_code'] ?? '';
        $this->apiKey = $credentials['api_key'] ?? '';
        $this->privateKey = $credentials['private_key'] ?? '';
        $this->isProduction = (bool) ($credentials['is_production'] ?? false);
    }

    public static function getValidationRules(): array
    {
        return [
            'credentials.merchant_code' => ['required', 'string'],
            'credentials.api_key' => ['required', 'string'],
            'credentials.private_key' => ['required', 'string'],
            'credentials.is_production' => ['required', 'boolean'],
        ];
    }

    public function createPayment(Transaction $transaction): GatewayPaymentResponse
    {
        $baseUrl = $this->isProduction
            ? 'https://tripay.co.id/api'
            : 'https://tripay.co.id/api-sandbox';

        $url = $baseUrl.'/transaction/create';

        $merchantRef = $transaction->reference_id;
        $amount = (int) $transaction->total_amount;

        $paymentCode = $this->mapPaymentMethod($transaction->paymentMethod->code);

        $signature = hash_hmac('sha256', $this->merchantCode.$merchantRef.$amount, $this->privateKey);

        $payload = [
            'method' => $paymentCode,
            'merchant_ref' => $merchantRef,
            'amount' => $amount,
            'customer_name' => $transaction->merchant->name,
            'customer_email' => 'merchant_'.$transaction->merchant_id.'@example.com',
            'customer_phone' => '081234567890',
            'order_items' => [
                [
                    'name' => 'Payment Ref '.$merchantRef,
                    'price' => $amount,
                    'quantity' => 1,
                ],
            ],
            'signature' => $signature,
        ];

        // Tripay documentation uses form-urlencoded or raw post. We can use standard JSON POST since Tripay API supports JSON.
        // Wait, the PHP example in Tripay.md uses:
        // CURLOPT_POSTFIELDS => http_build_query($data)
        // Let's use asForm() to match their curl example exactly, ensuring maximum compatibility.
        $response = Http::asForm()
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])
            ->timeout(10)
            ->connectTimeout(3)
            ->retry(3, 100)
            ->post($url, $payload);

        if ($response->failed()) {
            Log::error('Tripay payment creation failed', [
                'transaction_id' => $transaction->id,
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            $response->throw();
        }

        $data = $response->json();
        if (! ($data['success'] ?? false)) {
            throw new \Exception('Tripay API error: '.($data['message'] ?? 'Unknown error'));
        }

        $result = $data['data'] ?? [];

        return new GatewayPaymentResponse(
            pgRefId: $result['reference'] ?? null,
            // Tripay provides checkout_url for redirect and pay_url / pay_code for direct
            checkoutUrl: $result['checkout_url'] ?? $result['pay_url'] ?? null,
            qrisUrl: $result['qr_url'] ?? null,
            status: $this->mapStatus($result['status'] ?? 'UNPAID'),
            rawResponse: $data
        );
    }

    /**
     * @throws \Exception
     */
    public function handleWebhook(array $payload, array $headers = []): GatewayStatusResponse
    {
        // For Tripay webhooks, we need the raw payload content and the signature from headers.
        // We will pass the signature from the controller via the headers array: $headers['x-callback-signature']
        $callbackSignature = $headers['x-callback-signature'] ?? '';
        $rawBody = $headers['raw_body'] ?? json_encode($payload);

        $localSignature = hash_hmac('sha256', $rawBody, $this->privateKey);

        if (empty($callbackSignature) || ! hash_equals($localSignature, $callbackSignature)) {
            throw new \Exception('Tripay signature verification failed.');
        }

        $status = $this->mapStatus($payload['status'] ?? '');
        $paidAt = null;
        if ($status === 'PAID') {
            $paidAt = isset($payload['paid_at'])
                ? CarbonImmutable::createFromTimestamp($payload['paid_at'])
                : now();
        }

        return new GatewayStatusResponse(
            pgRefId: $payload['reference'] ?? null,
            status: $status,
            paidAt: $paidAt,
            rawResponse: $payload
        );
    }

    public function checkStatus(Transaction $transaction): GatewayStatusResponse
    {
        $baseUrl = $this->isProduction
            ? 'https://tripay.co.id/api'
            : 'https://tripay.co.id/api-sandbox';

        $url = $baseUrl.'/transaction/detail';

        // Tripay reference is saved in pg_ref_id
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
        ])
            ->timeout(10)
            ->connectTimeout(3)
            ->retry(3, 100)
            ->get($url, [
                'reference' => $transaction->pg_ref_id,
            ]);

        if ($response->failed()) {
            Log::error('Tripay status check failed', [
                'transaction_id' => $transaction->id,
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            $response->throw();
        }

        $data = $response->json();
        if (! ($data['success'] ?? false)) {
            throw new \Exception('Tripay API error: '.($data['message'] ?? 'Unknown error'));
        }

        $result = $data['data'] ?? [];
        $status = $this->mapStatus($result['status'] ?? 'UNPAID');
        $paidAt = null;
        if ($status === 'PAID') {
            $paidAt = isset($result['paid_at'])
                ? CarbonImmutable::createFromTimestamp($result['paid_at'])
                : now();
        }

        return new GatewayStatusResponse(
            pgRefId: $result['reference'] ?? null,
            status: $status,
            paidAt: $paidAt,
            rawResponse: $data
        );
    }

    protected function mapStatus(string $tripayStatus): string
    {
        return match (strtoupper($tripayStatus)) {
            'PAID' => 'PAID',
            'UNPAID' => 'PENDING',
            'EXPIRED' => 'EXPIRED',
            'FAILED' => 'FAILED',
            'REFUND' => 'REFUNDED',
            default => 'PENDING',
        };
    }

    protected function mapPaymentMethod(string $code): string
    {
        // Tripay codes are uppercase, e.g. bca_va -> BCAVA, qris -> QRIS
        return match ($code) {
            'bca_va' => 'BCAVA',
            'bni_va' => 'BNIVA',
            'bri_va' => 'BRIVA',
            'mandiri_va' => 'MANDIRIVA',
            'permata_va' => 'PERMATAVA',
            'qris' => 'QRIS',
            'gopay' => 'GOPAY',
            'shopeepay' => 'SHOPEEPAY',
            'ovo' => 'OVO',
            'dana' => 'DANA',
            'linkaja' => 'LINKAJA',
            default => strtoupper(str_replace('_', '', $code)),
        };
    }
}
