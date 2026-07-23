<?php

namespace App\Services\PaymentGateway\Drivers;

use App\Models\Transaction;
use App\Services\PaymentGateway\GatewayDriverInterface;
use App\Services\PaymentGateway\Responses\GatewayPaymentResponse;
use App\Services\PaymentGateway\Responses\GatewayStatusResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AriepulsaDriver implements GatewayDriverInterface
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

    public function createPayment(Transaction $transaction): GatewayPaymentResponse
    {
        $methodCode = strtolower($transaction->paymentMethod->code);

        if ($methodCode === 'qrisrealtime') {
            $url = 'https://ariepulsa.com/api/qrisrealtime';
            $payload = [
                'api_key' => $this->apiKey,
                'action' => 'get-deposit',
                'jumlah' => (int) $transaction->amount,
                'reff_id' => $transaction->reference_id,
                'kode_channel' => 'QRISREALTIME',
                'type_fee' => $this->typeFee,
            ];
        } elseif ($methodCode === 'qrisgo') {
            $url = 'https://ariepulsa.com/api/qrisgo';
            $payload = [
                'api_key' => $this->apiKey,
                'action' => 'get-deposit',
                'jumlah' => (int) $transaction->amount,
                'reff_id' => $transaction->reference_id,
                'type_fee' => $this->typeFee,
            ];
        } else {
            // Default: qris standard
            $url = 'https://ariepulsa.com/api/qris';
            $payload = [
                'api_key' => $this->apiKey,
                'action' => 'get-deposit',
                'jumlah' => (int) $transaction->amount,
                'type_fee' => $this->typeFee,
            ];
        }

        $response = Http::asForm()->post($url, $payload);

        if ($response->failed()) {
            Log::error('Ariepulsa payment creation failed', [
                'transaction_id' => $transaction->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Ariepulsa connection error.');
        }

        $data = $response->json();

        if (empty($data['status'])) {
            Log::error('Ariepulsa payment creation error response', [
                'transaction_id' => $transaction->id,
                'response' => $data,
            ]);
            throw new \Exception('Ariepulsa error: '.($data['data']['pesan'] ?? 'Unknown error'));
        }

        $resData = $data['data'];

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
            throw new \Exception('Ariepulsa callback error: missing kode_deposit');
        }

        // Find the transaction locally to determine the correct payment method (and thus the correct status check endpoint)
        $transaction = Transaction::where('pg_ref_id', $kodeDeposit)->first();

        if (! $transaction) {
            throw new \Exception("Ariepulsa webhook verification failed: Transaction not found locally for pg_ref_id [{$kodeDeposit}].");
        }

        return $this->checkStatus($transaction);
    }

    public function checkStatus(Transaction $transaction): GatewayStatusResponse
    {
        $methodCode = strtolower($transaction->paymentMethod->code);

        if ($methodCode === 'qrisrealtime') {
            $url = 'https://ariepulsa.com/api/qrisrealtime';
        } elseif ($methodCode === 'qrisgo') {
            $url = 'https://ariepulsa.com/api/qrisgo';
        } else {
            $url = 'https://ariepulsa.com/api/qris';
        }

        $response = Http::asForm()->post($url, [
            'api_key' => $this->apiKey,
            'action' => 'status-deposit',
            'kode_deposit' => $transaction->pg_ref_id,
        ]);

        if ($response->failed()) {
            Log::error('Ariepulsa status check failed', [
                'transaction_id' => $transaction->id,
                'status' => $response->status(),
            ]);
            throw new \Exception('Ariepulsa connection error.');
        }

        $data = $response->json();

        if (empty($data['status'])) {
            Log::error('Ariepulsa status check error response', [
                'transaction_id' => $transaction->id,
                'response' => $data,
            ]);
            throw new \Exception('Ariepulsa error: '.($data['data']['pesan'] ?? 'Unknown error'));
        }

        $resData = $data['data'];
        $mappedStatus = $this->mapStatus($resData['status'] ?? 'Pending');

        return new GatewayStatusResponse(
            pgRefId: $resData['kode_deposit'] ?? null,
            status: $mappedStatus,
            paidAt: $mappedStatus === 'PAID' ? now()->toImmutable() : null,
            rawResponse: $data
        );
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
