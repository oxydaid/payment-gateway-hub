<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Exceptions\InvalidWebhookSignatureException;
use App\Http\Controllers\Controller;
use App\Jobs\SendMerchantCallbackJob;
use App\Models\Transaction;
use App\Models\WebhookLog;
use App\Services\PaymentGateway\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookController extends Controller
{
    public function __construct(protected PaymentGatewayManager $gatewayManager) {}

    /**
     * Handle Midtrans Webhook.
     */
    public function midtrans(Request $request): JsonResponse
    {
        return $this->processWebhook($request, 'midtrans', function (array $payload): ?string {
            return isset($payload['order_id']) ? (string) $payload['order_id'] : null;
        });
    }

    /**
     * Handle Tokopay Webhook.
     */
    public function tokopay(Request $request): JsonResponse
    {
        return $this->processWebhook($request, 'tokopay', function (array $payload): ?string {
            return isset($payload['reff_id']) ? (string) $payload['reff_id'] : null;
        });
    }

    /**
     * Handle Xendit Webhook.
     */
    public function xendit(Request $request): JsonResponse
    {
        return $this->processWebhook($request, 'xendit', function (array $payload): ?string {
            if (isset($payload['event']) && isset($payload['data']) && is_array($payload['data'])) {
                return isset($payload['data']['reference_id']) ? (string) $payload['data']['reference_id'] : null;
            }

            return isset($payload['external_id']) ? (string) $payload['external_id'] : null;
        });
    }

    /**
     * Handle Pakasir Webhook.
     */
    public function pakasir(Request $request): JsonResponse
    {
        return $this->processWebhook($request, 'pakasir', function (array $payload): ?string {
            return isset($payload['order_id']) ? (string) $payload['order_id'] : null;
        });
    }

    /**
     * Handle Ariepulsa Webhook.
     */
    public function ariepulsa(Request $request): JsonResponse
    {
        return $this->processWebhook($request, 'ariepulsa', function (array $payload): ?string {
            if (isset($payload['data']) && is_array($payload['data']) && isset($payload['data']['kode_deposit'])) {
                return (string) $payload['data']['kode_deposit'];
            }

            return null;
        });
    }

    /**
     * Core Webhook processing logic.
     *
     * @param  callable(array<string, mixed>): ?string  $extractRefId
     */
    protected function processWebhook(Request $request, string $gatewayCode, callable $extractRefId): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        // 1. Log the incoming webhook (raw payload)
        $incomingLog = WebhookLog::create([
            'direction' => 'incoming',
            'payload' => $payload,
            'status_code' => 200,
            'notes' => 'Raw callback received from '.ucfirst($gatewayCode),
        ]);

        try {
            // 2. Extract reference ID
            $referenceId = $extractRefId($payload);

            if (empty($referenceId)) {
                $incomingLog->update([
                    'status_code' => 400,
                    'notes' => 'Reference ID could not be extracted from payload.',
                ]);

                return response()->json(['success' => false, 'message' => 'Invalid payload: missing reference ID.'], 400);
            }

            // 3. Find transaction (support lookup by reference_id or gateway pg_ref_id)
            $transaction = Transaction::where('reference_id', $referenceId)
                ->orWhere('pg_ref_id', $referenceId)
                ->first();

            if (! $transaction) {
                $incomingLog->update([
                    'status_code' => 404,
                    'notes' => "Transaction reference [{$referenceId}] not found in database.",
                ]);

                // Return success to gateway so it stops retrying for invalid local transactions
                return response()->json(['success' => true, 'message' => 'Transaction not found. No action taken.']);
            }

            // Associate log with transaction
            $incomingLog->update(['transaction_id' => $transaction->id]);

            // 4. Acquire Lock to prevent Race Conditions / Double Webhooks
            $lockKey = 'webhook_lock_'.$transaction->reference_id;
            $lock = Cache::lock($lockKey, 10);

            if (! $lock->get()) {
                Log::info('Webhook duplicate received while transaction is already being processed.', [
                    'transaction_id' => $transaction->id,
                    'reference_id' => $referenceId,
                    'gateway' => $gatewayCode,
                ]);

                $incomingLog->update([
                    'status_code' => 200,
                    'notes' => 'Duplicate webhook ignored while transaction lock is active.',
                ]);

                if ($gatewayCode === 'tokopay') {
                    return response()->json(['status' => 'true']);
                }

                return response()->json(['success' => true, 'message' => 'Webhook already being processed.']);
            }

            $shouldDispatchCallback = false;

            try {
                DB::transaction(function () use (&$transaction, $payload, $request, &$shouldDispatchCallback): void {
                    $lockedTransaction = Transaction::with('paymentMethod.gateway')
                        ->whereKey($transaction->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $lockedTransaction) {
                        return;
                    }

                    $transaction = $lockedTransaction;

                    $gateway = $transaction->paymentMethod->gateway;
                    $driver = $this->gatewayManager->driver($gateway);

                    // Collect headers for driver signature verification if required
                    $headers = [
                        'x-callback-signature' => $request->header('x-callback-signature'),
                        'x-callback-token' => $request->header('x-callback-token'),
                        'raw_body' => $request->getContent(),
                    ];

                    // Verify signature and map status
                    $statusResponse = $driver->handleWebhook($payload, $headers);

                    // If driver requires an active status poll to determine final state (e.g. Ariepulsa)
                    if ($driver->requiresStatusPollForWebhook()) {
                        $statusResponse = $driver->checkStatus($transaction);
                    }

                    $newPgStatus = $payload['transaction_status'] ?? $payload['status'] ?? $statusResponse->status;

                    // If status changes from PENDING, or pg_status changes, update and queue merchant callback
                    if ($transaction->status !== $statusResponse->status || $transaction->pg_status !== $newPgStatus) {
                        $transaction->update([
                            'status' => $statusResponse->status,
                            'paid_at' => $statusResponse->paidAt,
                            'pg_ref_id' => $statusResponse->pgRefId ?? $transaction->pg_ref_id,
                            'pg_status' => $newPgStatus,
                            'pg_response' => array_merge($transaction->pg_response ?? [], [
                                'webhook' => $statusResponse->rawResponse,
                            ]),
                        ]);

                        $shouldDispatchCallback = true;
                    }
                });
            } finally {
                $lock->release();
            }

            // Dispatch callback to merchant outside database transaction
            if ($shouldDispatchCallback) {
                try {
                    SendMerchantCallbackJob::dispatchMerchantCallback($transaction);
                } catch (Throwable $callbackEx) {
                    Log::warning('Merchant callback dispatch failed during webhook processing.', [
                        'transaction_id' => $transaction->id,
                        'error' => $callbackEx->getMessage(),
                    ]);
                }
            }

            // Standardize return responses for each gateway if needed
            if ($gatewayCode === 'tokopay') {
                return response()->json(['status' => 'true']);
            }

            return response()->json(['success' => true]);

        } catch (InvalidWebhookSignatureException $e) {
            $errorMessage = (string) $e->getMessage();

            Log::warning("Invalid webhook signature for {$gatewayCode}", [
                'payload' => $payload,
                'error' => $errorMessage,
            ]);

            $incomingLog->update([
                'status_code' => 403,
                'notes' => 'Signature verification failed: '.$errorMessage,
            ]);

            return response()->json([
                'success' => false,
                'message' => $errorMessage,
            ], 403);
        } catch (Throwable $e) {
            $errorMessage = (string) $e->getMessage();

            Log::error("Error processing webhook for {$gatewayCode}", [
                'payload' => $payload,
                'error' => $errorMessage,
                'trace' => $e->getTraceAsString(),
            ]);

            $incomingLog->update([
                'status_code' => 500,
                'notes' => 'Webhook processing failed: '.$errorMessage,
            ]);

            return response()->json([
                'success' => false,
                'message' => $errorMessage,
            ], 500);
        }
    }
}
