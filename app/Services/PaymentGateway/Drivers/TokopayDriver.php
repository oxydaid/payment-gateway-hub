<?php

namespace App\Services\PaymentGateway\Drivers;

use App\Models\Transaction;
use App\Services\PaymentGateway\GatewayDriverInterface;
use App\Services\PaymentGateway\Responses\GatewayPaymentResponse;
use App\Services\PaymentGateway\Responses\GatewayStatusResponse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TokopayDriver implements GatewayDriverInterface
{
    protected string $merchantId;

    protected string $secretKey;

    protected bool $isProduction;

    public function __construct(array $credentials)
    {
        $this->merchantId = $credentials['merchant_id'] ?? '';
        $this->secretKey = $credentials['secret_key'] ?? '';
        $this->isProduction = (bool) ($credentials['is_production'] ?? false);
    }

    public static function getValidationRules(): array
    {
        return [
            'credentials.merchant_id' => ['required', 'string'],
            'credentials.secret_key' => ['required', 'string'],
            'credentials.is_production' => ['required', 'boolean'],
        ];
    }

    public static function getPaymentMethods(): array
    {
        return [
            ['code' => 'BCAVA', 'name' => 'BCA Virtual Account', 'type' => 'va'],
            ['code' => 'BNIVA', 'name' => 'BNI Virtual Account', 'type' => 'va'],
            ['code' => 'BRIVA', 'name' => 'BRI Virtual Account', 'type' => 'va'],
            ['code' => 'MANDIRIVA', 'name' => 'Mandiri Virtual Account', 'type' => 'va'],
            ['code' => 'PERMATAVA', 'name' => 'Permata Virtual Account', 'type' => 'va'],
            ['code' => 'CIMBVA', 'name' => 'CIMB Virtual Account', 'type' => 'va'],
            ['code' => 'BSIVA', 'name' => 'BSI Virtual Account', 'type' => 'va'],
            ['code' => 'QRIS', 'name' => 'QRIS', 'type' => 'qris'],
            ['code' => 'QRISREALTIME', 'name' => 'QRIS Realtime', 'type' => 'qris'],
            ['code' => 'QRIS_CUSTOM', 'name' => 'QRIS Custom', 'type' => 'qris'],
            ['code' => 'GOPAY', 'name' => 'GoPay', 'type' => 'ewallet'],
            ['code' => 'GOPAY_REALTIME', 'name' => 'GoPay Realtime', 'type' => 'ewallet'],
            ['code' => 'SHOPEEPAY', 'name' => 'ShopeePay', 'type' => 'ewallet'],
            ['code' => 'SHOPEEPAY_REALTIME', 'name' => 'ShopeePay Realtime', 'type' => 'ewallet'],
            ['code' => 'OVOPUSH', 'name' => 'OVO Push', 'type' => 'ewallet'],
            ['code' => 'OVOPUSH_REALTIME', 'name' => 'OVO Push Realtime', 'type' => 'ewallet'],
            ['code' => 'DANA', 'name' => 'DANA', 'type' => 'ewallet'],
            ['code' => 'DANA_REALTIME', 'name' => 'DANA Realtime', 'type' => 'ewallet'],
            ['code' => 'LINKAJA', 'name' => 'LinkAja', 'type' => 'ewallet'],
            ['code' => 'ALFAMART', 'name' => 'Alfamart', 'type' => 'retail'],
            ['code' => 'INDOMARET', 'name' => 'Indomaret', 'type' => 'retail'],
        ];
    }

    public function createPayment(Transaction $transaction): GatewayPaymentResponse
    {
        // Tokopay uses api.tokopay.id
        $baseUrl = 'https://api.tokopay.id';
        $url = $baseUrl.'/v1/order';

        $refId = $transaction->reference_id;
        $amount = (int) $transaction->total_amount;
        $paymentCode = $this->mapPaymentMethod($transaction->paymentMethod->code);

        // Signature is md5 of merchant_id:secret:ref_id
        $signature = md5($this->merchantId.':'.$this->secretKey.':'.$refId);

        $payload = [
            'merchant_id' => $this->merchantId,
            'kode_channel' => is_array($paymentCode) ? $paymentCode[0] : $paymentCode,
            'reff_id' => $refId,
            'amount' => $amount,
            'customer_name' => $transaction->merchant->name,
            'customer_email' => 'merchant_'.$transaction->merchant_id.'@example.com',
            'customer_phone' => '081234567890',
            'redirect_url' => url('/payments/checkout/'.$transaction->reference_id),
            'expired_ts' => 0,
            'signature' => $signature,
        ];

        $response = Http::timeout(10)
            ->connectTimeout(3)
            ->retry(3, 100)
            ->post($url, $payload);

        if ($response->failed()) {
            Log::error('Tokopay payment creation failed', [
                'transaction_id' => $transaction->id,
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            $response->throw();
        }

        $data = $response->json();
        $statusStr = $data['status'] ?? '';
        if (strcasecmp($statusStr, 'Success') !== 0) {
            throw new \Exception('Tokopay API error: '.($data['message'] ?? 'Unknown error'));
        }

        $result = $data['data'] ?? [];
        $extractedPaymentCode = $result['nomor_va'] ?? $result['pay_code'] ?? $result['qr_string'] ?? null;

        return new GatewayPaymentResponse(
            pgRefId: $result['trx_id'] ?? null,
            checkoutUrl: $result['pay_url'] ?? $result['checkout_url'] ?? null,
            qrisUrl: $result['qr_link'] ?? null,
            status: 'PENDING',
            rawResponse: $data,
            paymentCode: $extractedPaymentCode
        );
    }

    /**
     * @throws \Exception
     */
    public function handleWebhook(array $payload, array $headers = []): GatewayStatusResponse
    {
        $merchantId = $payload['data']['merchant_id'] ?? '';
        $refId = $payload['reff_id'] ?? '';
        $status = $payload['status'] ?? '';
        $incomingSignature = $payload['signature'] ?? '';
        $reference = $payload['reference'] ?? '';
        $totalDibayar = $payload['data']['total_dibayar'] ?? '';

        // Generate possible signature formulas to guarantee validation
        $signatures = [
            md5($merchantId.':'.$this->secretKey.':'.$refId), // Official formula: merchant_id:secret:ref_id
            md5($merchantId.$this->secretKey.$refId),          // Official formula without colons
            md5($merchantId.':'.$this->secretKey.':'.$reference), // Official formula using pg_ref_id
            md5($merchantId.$this->secretKey.$reference),          // Official formula using pg_ref_id without colons
            md5($merchantId.$this->secretKey.$refId.$status),
            md5($merchantId.':'.$this->secretKey.':'.$refId.':'.$status),
            md5($merchantId.':'.$this->secretKey.':'.$refId.':'.$totalDibayar),
            md5($merchantId.':'.$this->secretKey.':'.$refId.':'.(int) $totalDibayar),
            md5($merchantId.$this->secretKey.$refId.$totalDibayar),
            md5($merchantId.$this->secretKey.$reference.$status),
            md5($merchantId.':'.$this->secretKey.':'.$reference.':'.$status),
        ];

        $isValid = false;
        foreach ($signatures as $sig) {
            if (hash_equals($sig, $incomingSignature)) {
                $isValid = true;
                break;
            }
        }

        if (! $isValid) {
            throw new \Exception('Tokopay signature verification failed.');
        }

        $mappedStatus = $this->mapStatus($status);
        $paidAt = null;
        if ($mappedStatus === 'PAID') {
            $updatedAt = $payload['data']['updated_at'] ?? null;
            $paidAt = $updatedAt ? CarbonImmutable::parse($updatedAt) : now();
        }

        return new GatewayStatusResponse(
            pgRefId: $payload['reference'] ?? null,
            status: $mappedStatus,
            paidAt: $paidAt,
            rawResponse: $payload
        );
    }

    public function checkStatus(Transaction $transaction): GatewayStatusResponse
    {
        $baseUrl = 'https://api.tokopay.id';
        // Note: GET endpoint is listed as /v1/order?merchant=&secret=&ref_id=&nominal=&metode=
        $url = $baseUrl.'/v1/order';

        $paymentCode = $this->mapPaymentMethod($transaction->paymentMethod->code);

        $response = Http::timeout(10)
            ->connectTimeout(3)
            ->retry(3, 100)
            ->get($url, [
                'merchant' => $this->merchantId,
                'secret' => $this->secretKey,
                'ref_id' => $transaction->reference_id,
                'nominal' => (int) $transaction->total_amount,
                'metode' => $paymentCode,
            ]);

        if ($response->failed()) {
            Log::error('Tokopay status check failed', [
                'transaction_id' => $transaction->id,
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            $response->throw();
        }

        $data = $response->json();
        $result = $data['data'] ?? [];
        $status = $this->mapStatus($result['status'] ?? '');
        $paidAt = null;
        if ($status === 'PAID') {
            // Check check status response has paid_at or updated_at, otherwise default to now
            $paidAt = now();
        }

        return new GatewayStatusResponse(
            pgRefId: $result['trx_id'] ?? null,
            status: $status,
            paidAt: $paidAt,
            rawResponse: $data
        );
    }

    protected function mapStatus(string $tokopayStatus): string
    {
        return match (strtolower($tokopayStatus)) {
            'success', 'completed', 'paid' => 'PAID',
            'unpaid', 'pending' => 'PENDING',
            'expired' => 'EXPIRED',
            'failed' => 'FAILED',
            default => 'PENDING',
        };
    }

    protected function mapPaymentMethod(string $code): string
    {
        // Tokopay maps, e.g. bca_va -> BCAVA, qris -> QRIS
        return match ($code) {
            'bca_va' => 'BCAVA',
            'bni_va' => 'BNIVA',
            'bri_va' => 'BRIVA',
            'mandiri_va' => 'MANDIRIVA',
            'permata_va' => 'PERMATAVA',
            'cimb_va' => 'CIMBVA',
            'bsi_va' => 'BSIVA',
            'qris' => ['QRIS', 'QRISREALTIME', 'QRIS_CUSTOM'],
            'gopay' => ['GOPAY', 'GOPAY_REALTIME'],
            'shopeepay' => ['SHOPEEPAY', 'SHOPEEPAY_REALTIME'],
            'ovo' => ['OVOPUSH', 'OVOPUSH_REALTIME'],
            'dana' => ['DANA', 'DANA_REALTIME'],
            'linkaja' => 'LINKAJA',
            'alfamart' => 'ALFAMART',
            'indomaret' => 'INDOMARET',
            default => strtoupper(str_replace('_', '', $code)),
        };
    }
}
