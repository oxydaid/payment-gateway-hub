<?php

namespace App\Services\PaymentGateway\Drivers;

use App\Models\Transaction;
use App\Services\PaymentGateway\GatewayDriverInterface;
use App\Services\PaymentGateway\Responses\GatewayPaymentResponse;
use App\Services\PaymentGateway\Responses\GatewayStatusResponse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class XenditDriver implements GatewayDriverInterface
{
    protected string $secretKey;

    protected string $callbackToken;

    protected bool $isProduction;

    public function __construct(array $credentials)
    {
        $this->secretKey = $credentials['secret_key'] ?? '';
        $this->callbackToken = $credentials['callback_token'] ?? '';
        $this->isProduction = (bool) ($credentials['is_production'] ?? false);
    }

    public static function getValidationRules(): array
    {
        return [
            'credentials.secret_key' => ['required', 'string'],
            'credentials.callback_token' => ['nullable', 'string'],
            'credentials.is_production' => ['required', 'boolean'],
        ];
    }

    public function createPayment(Transaction $transaction): GatewayPaymentResponse
    {
        $baseUrl = 'https://api.xendit.co/v2/invoices';

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic '.base64_encode($this->secretKey.':'),
        ])
            ->timeout(10)
            ->connectTimeout(3)
            ->retry(3, 100)
            ->post($baseUrl, [
                'external_id' => $transaction->reference_id,
                'amount' => (int) $transaction->total_amount,
                'description' => 'Payment for Order #'.$transaction->order_id,
                'success_redirect_url' => route('home'),
                'failure_redirect_url' => route('home'),
            ]);

        if ($response->failed()) {
            Log::error('Xendit payment creation failed', [
                'transaction_id' => $transaction->id,
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            $response->throw();
        }

        $data = $response->json();

        return new GatewayPaymentResponse(
            pgRefId: $data['id'] ?? null,
            checkoutUrl: $data['invoice_url'] ?? null,
            qrisUrl: null,
            status: $this->mapStatus($data['status'] ?? 'PENDING'),
            rawResponse: $data
        );
    }

    /**
     * @throws \Exception
     */
    public function handleWebhook(array $payload, array $headers = []): GatewayStatusResponse
    {
        if ($this->callbackToken) {
            $incomingToken = $headers['x-callback-token'] ?? null;
            if (is_array($incomingToken)) {
                $incomingToken = $incomingToken[0] ?? null;
            }
            if ($incomingToken !== $this->callbackToken) {
                throw new \Exception('Xendit callback token verification failed.');
            }
        }

        $status = $this->mapStatus($payload['status'] ?? '');
        $paidAt = null;
        if ($status === 'PAID') {
            $paidAt = isset($payload['updated']) ? CarbonImmutable::parse($payload['updated']) : now();
        }

        return new GatewayStatusResponse(
            pgRefId: $payload['id'] ?? null,
            status: $status,
            paidAt: $paidAt,
            rawResponse: $payload
        );
    }

    public function checkStatus(Transaction $transaction): GatewayStatusResponse
    {
        $invoiceId = $transaction->pg_reference_id;
        $url = 'https://api.xendit.co/v2/invoices/'.$invoiceId;

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Basic '.base64_encode($this->secretKey.':'),
        ])
            ->timeout(10)
            ->connectTimeout(3)
            ->retry(3, 100)
            ->get($url);

        if ($response->failed()) {
            Log::error('Xendit status check failed', [
                'transaction_id' => $transaction->id,
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            $response->throw();
        }

        $data = $response->json();
        $status = $this->mapStatus($data['status'] ?? '');
        $paidAt = null;
        if ($status === 'PAID') {
            $paidAt = isset($data['updated']) ? CarbonImmutable::parse($data['updated']) : now();
        }

        return new GatewayStatusResponse(
            pgRefId: $data['id'] ?? null,
            status: $status,
            paidAt: $paidAt,
            rawResponse: $data
        );
    }

    protected function mapStatus(string $xenditStatus): string
    {
        return match (strtoupper($xenditStatus)) {
            'PAID', 'SETTLED' => 'PAID',
            'PENDING' => 'PENDING',
            'EXPIRED' => 'EXPIRED',
            default => 'PENDING',
        };
    }
}
