<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\WebhookLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendMerchantCallbackJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public $backoff = [60, 300, 600];

    /**
     * Create a new job instance.
     */
    public function __construct(public Transaction $transaction) {}

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return (string) $this->transaction->id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $merchant = $this->transaction->merchant;

        if (! $merchant || empty($merchant->webhook_url)) {
            Log::warning('Skipping callback: Merchant webhook URL not set or merchant not found.', [
                'transaction_id' => $this->transaction->id,
            ]);

            return;
        }

        $this->transaction->loadMissing('paymentMethod.gateway');

        $payload = [
            'reference_id' => $this->transaction->reference_id,
            'merchant_ref_id' => $this->transaction->merchant_ref_id,
            'payment_gateway' => $this->transaction->paymentMethod->gateway->code ?? 'unknown',
            'payment_method' => $this->transaction->paymentMethod->code ?? 'unknown',
            'amount' => (float) $this->transaction->amount,
            'fee' => (float) $this->transaction->fee,
            'total_amount' => (float) $this->transaction->total_amount,
            'status' => $this->transaction->status,
            'pg_status' => $this->transaction->pg_status,
            'created_at' => $this->transaction->created_at->toIso8601String(),
            'paid_at' => $this->transaction->paid_at?->toIso8601String(),
        ];

        // Sign the JSON payload using merchant's api_key
        $signature = hash_hmac('sha256', json_encode($payload), $merchant->api_key ?? '');

        try {
            $response = Http::withHeaders([
                'X-Bridge-Signature' => $signature,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->timeout(10)
                ->connectTimeout(3)
                ->post($merchant->webhook_url, $payload);

            $isSuccessResponse = false;
            if ($response->successful()) {
                $responseData = $response->json();
                if (isset($responseData['success']) && ($responseData['success'] === true || $responseData['success'] === 'true')) {
                    $isSuccessResponse = true;
                }
            }

            WebhookLog::create([
                'transaction_id' => $this->transaction->id,
                'direction' => 'outgoing',
                'payload' => $payload,
                'status_code' => $response->status(),
                'notes' => $isSuccessResponse
                    ? 'Callback sent successfully and accepted by merchant.'
                    : ($response->successful()
                        ? 'Callback sent, but merchant did not return {"success": true}. Response: '.$response->body()
                        : 'Callback response failed: '.$response->body()),
            ]);

            if ($response->failed()) {
                $response->throw();
            }

            if ($isSuccessResponse) {
                $this->transaction->update([
                    'status' => 'DONE',
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Merchant callback attempt failed', [
                'transaction_id' => $this->transaction->id,
                'url' => $merchant->webhook_url,
                'error' => $e->getMessage(),
            ]);

            // Re-throw to trigger job retry
            throw $e;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(?Throwable $exception): void
    {
        WebhookLog::create([
            'transaction_id' => $this->transaction->id,
            'direction' => 'outgoing',
            'payload' => [
                'reference_id' => $this->transaction->reference_id,
                'status' => $this->transaction->status,
            ],
            'status_code' => 500,
            'notes' => 'Callback retries exhausted. Final error: '.($exception ? $exception->getMessage() : 'Unknown error'),
        ]);
    }
}
