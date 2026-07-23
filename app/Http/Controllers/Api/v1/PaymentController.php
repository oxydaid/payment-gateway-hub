<?php

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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        if ($merchant) {
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
        if ($merchant) {
            $query->where('merchant_id', $merchant->id);
        }

        $transaction = $query->where(function ($query) use ($referenceId) {
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
        if ($merchant) {
            $query->whereHas('transaction', function ($query) use ($merchant) {
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
        if (! $merchant && $request->has('merchant_id')) {
            $merchant = Merchant::find($request->merchant_id);
        }
        $amount = (float) $request->amount;
        $paymentMethod = PaymentMethod::with('gateway')->find($request->payment_method_id);

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

        DB::beginTransaction();

        try {
            // 1. Create pending transaction
            $transaction = Transaction::create([
                'reference_id' => $referenceId,
                'merchant_id' => $merchant->id,
                'merchant_ref_id' => $request->merchant_ref_id,
                'payment_method_id' => $paymentMethod->id,
                'amount' => $amount,
                'fee' => $fee,
                'total_amount' => $totalAmount,
                'status' => 'PENDING',
                'pg_status' => 'PENDING',
                'expired_at' => $expiredAt,
                'redirect_url' => $request->redirect_url,
            ]);

            // 2. Call resolved gateway driver
            $gateway = $paymentMethod->gateway;
            $driver = $this->gatewayManager->driver($gateway);

            $gatewayResponse = $driver->createPayment($transaction);

            // Extract payment code and qris url
            $paymentCode = $gatewayResponse->paymentCode;
            $qrisUrl = $gatewayResponse->qrisUrl;

            // Detect raw QRIS string and convert to stored local image
            $rawQris = null;
            if ($qrisUrl && (str_starts_with($qrisUrl, '000201') || ! filter_var($qrisUrl, FILTER_VALIDATE_URL))) {
                $rawQris = $qrisUrl;
            } elseif ($paymentCode && str_starts_with($paymentCode, '000201')) {
                $rawQris = $paymentCode;
            }

            if ($rawQris) {
                $paymentCode = $rawQris;
                $qrisUrl = $this->generateQrisImage($rawQris, $transaction->reference_id);
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

        } catch (Throwable $e) {
            DB::rollBack();

            Log::error('Failed to create payment transaction', [
                'merchant_id' => $merchant->id,
                'merchant_ref_id' => $request->merchant_ref_id,
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
     * Generate local QRIS image and store it on public disk.
     */
    protected function generateQrisImage(string $qrContent, string $referenceId): ?string
    {
        try {
            $response = Http::get('https://api.qrserver.com/v1/create-qr-code/', [
                'size' => '300x300',
                'data' => $qrContent,
            ]);

            if ($response->successful()) {
                $fileName = 'qris-'.$referenceId.'.png';
                Storage::disk('public')->put('qris/'.$fileName, $response->body());

                return asset('storage/qris/'.$fileName);
            }
        } catch (Throwable $e) {
            Log::error('Failed to generate local QRIS image', [
                'error' => $e->getMessage(),
                'qr_content' => $qrContent,
            ]);
        }

        return null;
    }
}
