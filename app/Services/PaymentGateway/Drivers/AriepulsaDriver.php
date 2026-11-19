<?php

declare(strict_types=1);

namespace App\Services\PaymentGateway\Drivers;

use App\Models\Transaction;
use App\Services\PaymentGateway\AbstractGatewayDriver;
use App\Services\PaymentGateway\Responses\GatewayPaymentResponse;
use App\Services\PaymentGateway\Responses\GatewayStatusResponse;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AriepulsaDriver extends AbstractGatewayDriver
{
    protected string $apiKey;

    protected string $typeFee;

    public function __construct(array $credentials)
    {
        $this->apiKey = $credentials['api_key'] ?? '';
        $this->typeFee = $credentials['type_fee'] ?? '1';
    }

    public static function getValidationRules(): array
    {
        return [
            'credentials.api_key' => ['required', 'string'],
            'credentials.type_fee' => ['nullable', 'string', 'in:1,2'],
        ];
    }

    public static function getPaymentMethods(): array
    {
        return [
            ['code' => 'qris', 'name' => 'QRIS Standard', 'type' => 'qris'],
            ['code' => 'qrisgo', 'name' => 'QRIS GO', 'type' => 'qris'],
            ['code' => 'qrisrealtime', 'name' => 'QRIS Realtime', 'type' => 'qris'],
        ];
    }

    public function requiresStatusPollForWebhook(): bool
    {
        return true;
    }

    public function createPayment(Transaction $transaction): GatewayPaymentResponse
    {
        $methodCode = strtolower($transaction->paymentMethod->code);
        $url = $this->resolveEndpoint($methodCode);

        $payload = [
            'api_key' => $this->apiKey,
            'action' => 'get-deposit',
            'jumlah' => (int) $transaction->amount,
            'type_fee' => $this->typeFee,
        ];

        if ($methodCode === 'qrisrealtime') {
            $payload['reff_id'] = $transaction->reference_id;
            $payload['kode_channel'] = 'QRISREALTIME';
        } elseif ($methodCode === 'qrisgo') {
            $payload['reff_id'] = $transaction->reference_id;
        }

        $response = Http::asForm()
            ->timeout(10)
            ->connectTimeout(3)
            ->retry(3, 100)
            ->post($url, $payload);

        $this->throwIfFailed($response, 'Ariepulsa payment creation', $transaction->id);

        $data = $response->json();

        if (empty($data['status'])) {
            Log::error('Ariepulsa payment creation error response', [
                'transaction_id' => $transaction->id,
                'response' => $data,
            ]);
            throw new Exception('Ariepulsa error: '.($data['data']['pesan'] ?? 'Unknown error'));
        }

        $resData = $data['data'] ?? [];

        return new GatewayPaymentResponse(
            pgRefId: $resData['kode_deposit'] ?? null,
            checkoutUrl: $resData['link_payment'] ?? null,
            qrisUrl: $resData['link_qr'] ?? null,
            status: 'PENDING',
            rawResponse: $data,
            paymentCode: $resData['qr_string'] ?? null
        );
    }

    public function handleWebhook(array $payload, array $headers = []): GatewayStatusResponse
    {
        $resData = $payload['data'] ?? [];
        $kodeDeposit = $resData['kode_deposit'] ?? null;

        if (empty($kodeDeposit)) {
            throw new Exception('Ariepulsa callback error: missing kode_deposit');
        }

        // Return status PENDING; controller will immediately call checkStatus() with the resolved transaction
        return new GatewayStatusResponse(
            pgRefId: (string) $kodeDeposit,
            status: 'PENDING',
            paidAt: null,
            rawResponse: $payload
        );
    }

    public function checkStatus(Transaction $transaction): GatewayStatusResponse
    {
        $methodCode = strtolower($transaction->paymentMethod->code);
        $url = $this->resolveEndpoint($methodCode);

        $response = Http::asForm()
            ->timeout(10)
            ->connectTimeout(3)
            ->retry(3, 100)
            ->post($url, [
                'api_key' => $this->apiKey,
                'action' => 'status-deposit',
                'kode_deposit' => $transaction->pg_ref_id,
            ]);

        $this->throwIfFailed($response, 'Ariepulsa status check', $transaction->id);

        $data = $response->json();

        if (empty($data['status'])) {
            Log::error('Ariepulsa status check error response', [
                'transaction_id' => $transaction->id,
                'response' => $data,
            ]);
            throw new Exception('Ariepulsa error: '.($data['data']['pesan'] ?? 'Unknown error'));
        }

        $resData = $data['data'] ?? [];
        $mappedStatus = $this->mapStatus($resData['status'] ?? 'Pending');

        return new GatewayStatusResponse(
            pgRefId: $resData['kode_deposit'] ?? null,
            status: $mappedStatus,
            paidAt: $mappedStatus === 'PAID' ? $this->resolvePaidAt(null) : null,
            rawResponse: $data
        );
    }

    protected function resolveEndpoint(string $methodCode): string
    {
        return match ($methodCode) {
            'qrisrealtime' => 'https://ariepulsa.com/api/qrisrealtime',
            'qrisgo' => 'https://ariepulsa.com/api/qrisgo',
            default => 'https://ariepulsa.com/api/qris',
        };
    }

    protected function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'success' => 'PAID',
            'pending' => 'PENDING',
            'error' => 'FAILED',
            default => 'PENDING',
        };
    }
}
