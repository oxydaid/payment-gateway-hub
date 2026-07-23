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
        $baseUrl = 'https://api.xendit.co/v3/payment_requests';

        $paymentMethod = $transaction->paymentMethod;
        $channelCode = $this->mapPaymentMethod($paymentMethod->code);

        $channelProperties = [];
        if ($paymentMethod->type === 'va') {
            $channelProperties['display_name'] = $transaction->merchant->name;
        } elseif (in_array($paymentMethod->type, ['ewallet', 'credit_card'])) {
            $channelProperties['success_return_url'] = url('/');
            $channelProperties['failure_return_url'] = url('/');
        }

        $payload = [
            'reference_id' => $transaction->reference_id,
            'type' => 'PAY',
            'currency' => 'IDR',
            'country' => 'ID',
            'request_amount' => (int) $transaction->total_amount,
            'channel_code' => $channelCode,
            'description' => 'Payment for Order #'.$transaction->order_id,
        ];

        if (! empty($channelProperties)) {
            $payload['channel_properties'] = $channelProperties;
        }

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'API-Version' => '2024-11-11',
            'Authorization' => 'Basic '.base64_encode($this->secretKey.':'),
        ])
            ->timeout(10)
            ->connectTimeout(3)
            ->retry(3, 100)
            ->post($baseUrl, $payload);

        if ($response->failed()) {
            Log::error('Xendit payment creation failed', [
                'transaction_id' => $transaction->id,
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            $response->throw();
        }

        $data = $response->json();

        $checkoutUrl = null;
        $qrisUrl = null;

        if (! empty($data['actions'])) {
            foreach ($data['actions'] as $action) {
                $actionType = $action['type'] ?? '';
                $descriptor = $action['descriptor'] ?? '';
                $value = $action['value'] ?? null;

                if ($actionType === 'REDIRECT_CUSTOMER' || $descriptor === 'WEB_URL' || $descriptor === 'DEEPLINK_URL') {
                    $checkoutUrl = $value;
                } elseif ($descriptor === 'QR_STRING') {
                    $qrisUrl = $value;
                }
            }
        }

        return new GatewayPaymentResponse(
            pgRefId: $data['payment_request_id'] ?? $data['id'] ?? null,
            checkoutUrl: $checkoutUrl,
            qrisUrl: $qrisUrl,
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

        // Handle both v3 Payment Request callbacks and v2 legacy Invoice callbacks
        if (isset($payload['event']) && isset($payload['data'])) {
            $data = $payload['data'];
            $status = $this->mapStatus($data['status'] ?? '');
            $paidAt = null;
            if ($status === 'PAID') {
                $paidAt = isset($data['updated']) ? CarbonImmutable::parse($data['updated']) : now();
            }

            return new GatewayStatusResponse(
                pgRefId: $data['payment_request_id'] ?? $data['id'] ?? null,
                status: $status,
                paidAt: $paidAt,
                rawResponse: $payload
            );
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
        $paymentRequestId = $transaction->pg_ref_id;
        $url = 'https://api.xendit.co/v3/payment_requests/'.$paymentRequestId;

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'API-Version' => '2024-11-11',
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
            pgRefId: $data['payment_request_id'] ?? $data['id'] ?? null,
            status: $status,
            paidAt: $paidAt,
            rawResponse: $data
        );
    }

    protected function mapStatus(string $xenditStatus): string
    {
        return match (strtoupper($xenditStatus)) {
            'PAID', 'SETTLED', 'SUCCEEDED' => 'PAID',
            'PENDING', 'REQUIRES_ACTION' => 'PENDING',
            'EXPIRED' => 'EXPIRED',
            'FAILED' => 'FAILED',
            default => 'PENDING',
        };
    }

    protected function mapPaymentMethod(string $code): string
    {
        return match ($code) {
            'bca_va' => 'BCA_VIRTUAL_ACCOUNT',
            'bni_va' => 'BNI_VIRTUAL_ACCOUNT',
            'bri_va' => 'BRI_VIRTUAL_ACCOUNT',
            'mandiri_va' => 'MANDIRI_VIRTUAL_ACCOUNT',
            'permata_va' => 'PERMATA_VIRTUAL_ACCOUNT',
            'qris' => 'QRIS',
            'gopay' => 'GOPAY',
            'shopeepay' => 'SHOPEEPAY',
            'ovo' => 'OVO',
            'dana' => 'DANA',
            'linkaja' => 'LINKAJA',
            default => strtoupper(str_replace('_va', '_VIRTUAL_ACCOUNT', $code)),
        };
    }
}
