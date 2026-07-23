<?php

namespace App\Http\Controllers\Api\v1;

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
        return $this->processWebhook($request, 'midtrans', function (array $payload) {
            return $payload['order_id'] ?? null;
        });
    }

    /**
     * Handle Tokopay Webhook.
     */
    public function tokopay(Request $request): JsonResponse
    {
        return $this->processWebhook($request, 'tokopay', function (array $payload) {
            return $payload['reff_id'] ?? null;
        });
    }

    /**
     * Handle Xendit Webhook.
     */
    public function xendit(Request $request): JsonResponse
    {
        return $this->processWebhook($request, 'xendit', function (array $payload) {
            if (isset($payload['event']) && isset($payload['data'])) {
                return $payload['data']['reference_id'] ?? null;
            }

            return $payload['external_id'] ?? null;
        });
    }

    /**
     * Handle Pakasir Webhook.
     */
    public function pakasir(Request $request): JsonResponse
    {
        return $this->processWebhook($request, 'pakasir', function (array $payload) {
            return $payload['order_id'] ?? null;
        });
    }

    /**
     * Handle Ariepulsa Webhook.
     */
    public function ariepulsa(Request $request): JsonResponse
    {
        return $this->processWebhook($request, 'ariepulsa', function (array $payload) {
            return $payload['data']['kode_deposit'] ?? null;
        });
    }

    /**
     * Core Webhook processing logic.
     */
    protected function processWebhook(Request $request, string $gatewayCode, callable $extractRefId): JsonResponse
    {
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

            $shouldDispatchCallback = false;

            $status = $lock->block(5, function () use ($transaction, $payload, $request, &$shouldDispatchCallback) {
                return DB::transaction(function () use ($transaction, $payload, $request, &$shouldDispatchCallback) {
                    $transaction->refresh();

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

                    return true;
                });
            });

            if (! $status) {
                throw new \Exception('Failed to acquire transaction lock.');
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

        } catch (Throwable $e) {
            Log::error("Error processing webhook for {$gatewayCode}", [
                'payload' => $payload,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $incomingLog->update([
                'status_code' => 500,
                'notes' => 'Webhook processing failed: '.$e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
