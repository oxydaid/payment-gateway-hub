<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\v1\StorePaymentRequest;
use App\Http\Resources\Api\v1\PaymentMethodResource;
use App\Http\Resources\Api\v1\TransactionResource;
use App\Http\Resources\Api\v1\WebhookLogResource;
use App\Models\Merchant;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\WebhookLog;
use App\Services\PaymentGateway\PaymentGatewayManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PaymentController extends Controller
{
    public function __construct(protected PaymentGatewayManager $gatewayManager) {}

    /**
     * Get active payment methods for merchants.
     */
    public function methods(Request $request): AnonymousResourceCollection
    {
        $methods = PaymentMethod::with('gateway')
            ->where('is_active', true)
            ->get();

        return PaymentMethodResource::collection($methods)->additional([
            'success' => true,
        ]);
    }

    /**
     * Get paginated transactions for merchants.
     */
    public function transactions(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $perPage = (int) $request->query('per_page', 15);

        $query = Transaction::with('paymentMethod.gateway');
        if ($merchant instanceof Merchant) {
            $query->where('merchant_id', $merchant->id);
        }

        $transactions = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => TransactionResource::collection($transactions->items()),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    /**
     * Get details of a single transaction.
     */
    public function show(Request $request, string $referenceId): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');

        $query = Transaction::with('paymentMethod.gateway');
        if ($merchant instanceof Merchant) {
            $query->where('merchant_id', $merchant->id);
        }

        $transaction = $query->where(function ($query) use ($referenceId): void {
            $query->where('reference_id', $referenceId)
                ->orWhere('merchant_ref_id', $referenceId);
        })->first();

        if (! $transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found.',
            ], 404);
        }

        return (new TransactionResource($transaction))
            ->additional(['success' => true])
            ->response();
    }

    /**
     * Get paginated webhook logs for merchants.
     */
    public function webhookLogs(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $perPage = (int) $request->query('per_page', 15);

        $query = WebhookLog::with('transaction');
        if ($merchant instanceof Merchant) {
            $query->whereHas('transaction', function ($query) use ($merchant): void {
                $query->where('merchant_id', $merchant->id);
            });
        }

        $logs = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => WebhookLogResource::collection($logs->items()),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Create a payment transaction and request pay URL from gateway.
     */
    public function store(StorePaymentRequest $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        if (! $merchant instanceof Merchant && $request->has('merchant_id')) {
            $merchant = Merchant::find($request->input('merchant_id'));
        }

        if (! $merchant instanceof Merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant context could not be resolved.',
            ], 422);
        }

        $amount = (float) $request->input('amount');
        $merchantRefId = (string) $request->input('merchant_ref_id');
        $paymentMethod = PaymentMethod::with('gateway')->findOrFail($request->input('payment_method_id'));

        $rawIdempotencyKey = $request->header('Idempotency-Key');
        $idempotencyKey = is_string($rawIdempotencyKey) && trim($rawIdempotencyKey) !== ''
            ? substr(trim($rawIdempotencyKey), 0, 128)
            : null;

        // Check for existing transaction by Idempotency-Key or (merchant_id, merchant_ref_id)
        $existingTransaction = $this->findExistingTransaction($merchant->id, $merchantRefId, $idempotencyKey);
        if ($existingTransaction !== null) {
            return $this->buildIdempotentResponse($existingTransaction, $amount, $paymentMethod->id);
        }

        // Calculate Fee based on fee_type
        $fee = 0.0;
        $feeFix = (float) $paymentMethod->fee_fix;
        $feePercent = (float) $paymentMethod->fee_percent;

        if ($paymentMethod->fee_type === 'fix') {
            $fee = $feeFix;
        } elseif ($paymentMethod->fee_type === 'percent') {
            $fee = ($feePercent / 100.0) * $amount;
        } elseif ($paymentMethod->fee_type === 'mix') {
            $fee = $feeFix + (($feePercent / 100.0) * $amount);
        }

        $totalAmount = $amount + $fee;
        $referenceId = (string) Str::uuid();
        $expiredAt = now()->addHours(24);
        $rawQris = null;

        DB::beginTransaction();

        try {
            // 1. Create pending transaction
            $transaction = Transaction::create([
                'reference_id' => $referenceId,
                'merchant_id' => $merchant->id,
                'merchant_ref_id' => $merchantRefId,
                'idempotency_key' => $idempotencyKey,
                'payment_method_id' => $paymentMethod->id,
                'amount' => $amount,
                'fee' => $fee,
                'total_amount' => $totalAmount,
                'status' => 'PENDING',
                'pg_status' => 'PENDING',
                'expired_at' => $expiredAt,
                'redirect_url' => (string) $request->input('redirect_url'),
            ]);

            // 2. Call resolved gateway driver
            $gateway = $paymentMethod->gateway;
            $driver = $this->gatewayManager->driver($gateway);

            $gatewayResponse = $driver->createPayment($transaction);

            // Extract payment code and qris url
            $paymentCode = $gatewayResponse->paymentCode;
            $qrisUrl = $gatewayResponse->qrisUrl;

            // Detect raw QRIS EMV string and serve it dynamically via Hub's own QRIS image endpoint
            if ($qrisUrl && (str_starts_with($qrisUrl, '000201') || ! filter_var($qrisUrl, FILTER_VALIDATE_URL))) {
                $rawQris = $qrisUrl;
            } elseif ($paymentCode && str_starts_with($paymentCode, '000201')) {
                $rawQris = $paymentCode;
            }

            if ($rawQris !== null) {
                $paymentCode = $rawQris;
                $qrisUrl = route('payments.qris', [
                    'reference_id' => $transaction->reference_id,
                    'format' => 'png',
                ]);
            }

            // 3. Update transaction with PG details
            $transaction->update([
                'pg_ref_id' => $gatewayResponse->pgRefId,
                'checkout_url' => $gatewayResponse->checkoutUrl,
                'qris_url' => $qrisUrl,
                'payment_code' => $paymentCode,
                'pg_status' => $gatewayResponse->status ?? 'PENDING',
                'pg_response' => [
                    'create' => $gatewayResponse->rawResponse,
                ],
            ]);

            DB::commit();

            return (new TransactionResource($transaction->load('paymentMethod.gateway')))
                ->additional(['success' => true])
                ->response()
                ->setStatusCode(201);

        } catch (UniqueConstraintViolationException) {
            DB::rollBack();

            $concurrentTx = $this->findExistingTransaction($merchant->id, $merchantRefId, $idempotencyKey);
            if ($concurrentTx !== null) {
                return $this->buildIdempotentResponse($concurrentTx, $amount, $paymentMethod->id);
            }

            return response()->json([
                'success' => false,
                'message' => 'Duplicate transaction reference detected.',
            ], 409);
        } catch (Throwable $e) {
            DB::rollBack();

            Log::error('Failed to create payment transaction', [
                'merchant_id' => $merchant->id,
                'merchant_ref_id' => $merchantRefId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment creation failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Find an existing transaction matching either the idempotency key or merchant reference ID.
     */
    protected function findExistingTransaction(int $merchantId, string $merchantRefId, ?string $idempotencyKey): ?Transaction
    {
        return Transaction::where('merchant_id', $merchantId)
            ->where(function ($query) use ($merchantRefId, $idempotencyKey): void {
                $query->where('merchant_ref_id', $merchantRefId);
                if ($idempotencyKey !== null) {
                    $query->orWhere('idempotency_key', $idempotencyKey);
                }
            })
            ->first();
    }

    /**
     * Return 200 OK for a matching idempotent replay, or 409 Conflict if parameters diverge.
     */
    protected function buildIdempotentResponse(Transaction $existingTransaction, float $amount, int $paymentMethodId): JsonResponse
    {
        $amountMatches = abs((float) $existingTransaction->amount - $amount) < 0.001;
        $methodMatches = (int) $existingTransaction->payment_method_id === $paymentMethodId;

        if (! $amountMatches || ! $methodMatches) {
            return response()->json([
                'success' => false,
                'message' => 'Idempotency conflict: transaction reference or Idempotency-Key was already used with different payment parameters.',
            ], 409);
        }

        return (new TransactionResource($existingTransaction->load('paymentMethod.gateway')))
            ->additional([
                'success' => true,
                'idempotent' => true,
            ])
            ->response()
            ->setStatusCode(200);
    }
}
