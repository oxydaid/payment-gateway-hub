<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\WebhookLog;
use App\Services\Security\WebhookUrlValidator;
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
    public $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public $backoff = [10, 60, 300, 1800, 3600];

    /**
     * Create a new job instance.
     */
    public function __construct(public Transaction $transaction) {}

    /**
     * Dispatch the job either synchronously or asynchronously depending on configuration.
     */
    public static function dispatchMerchantCallback(Transaction $transaction): void
    {
        if (config('queue.merchant_callback_sync', false)) {
            self::dispatchSync($transaction);
        } else {
            self::dispatch($transaction);
        }
    }

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
    public function handle(?WebhookUrlValidator $webhookUrlValidator = null): void
    {
        $webhookUrlValidator ??= app(WebhookUrlValidator::class);
        $merchant = $this->transaction->merchant;

        if (! $merchant || empty($merchant->webhook_url)) {
            Log::warning('Skipping callback: Merchant webhook URL not set or merchant not found.', [
                'transaction_id' => $this->transaction->id,
            ]);

            return;
        }

        if (! $webhookUrlValidator->isSafe($merchant->webhook_url)) {
            Log::warning('Blocked outbound merchant callback due to SSRF protection.', [
                'transaction_id' => $this->transaction->id,
                'merchant_id' => $merchant->id,
                'url' => $merchant->webhook_url,
            ]);

            WebhookLog::create([
                'transaction_id' => $this->transaction->id,
                'direction' => 'outgoing',
                'payload' => [
                    'reference_id' => $this->transaction->reference_id,
                    'status' => $this->transaction->status,
                ],
                'status_code' => 400,
                'notes' => 'Callback blocked by SSRF protection: target URL resolves to a disallowed address.',
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
            'pg_ref_id' => $this->transaction->pg_ref_id,
            'checkout_url' => $this->transaction->checkout_url,
            'qris_url' => $this->transaction->qris_url,
            'created_at' => $this->transaction->created_at?->toIso8601String(),
            'paid_at' => $this->transaction->paid_at?->toIso8601String(),
        ];

        $encodedPayload = json_encode($payload);
        // Sign the JSON payload using merchant's encrypted webhook_secret (or legacy api_key)
        $signature = hash_hmac('sha256', $encodedPayload !== false ? $encodedPayload : '', $merchant->signingSecret());

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
                if (is_array($responseData) && isset($responseData['success']) && ($responseData['success'] === true || $responseData['success'] === 'true')) {
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

            // Save connection/timeout failures in WebhookLog
            WebhookLog::create([
                'transaction_id' => $this->transaction->id,
                'direction' => 'outgoing',
                'payload' => $payload,
                'status_code' => 500, // standard status code for connection failures
                'notes' => 'Connection failed: '.$e->getMessage(),
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
