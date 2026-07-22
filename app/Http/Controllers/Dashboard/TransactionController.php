<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\SendMerchantCallbackJob;
use App\Models\PaymentGateway;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Transaction::with(['paymentMethod.gateway', 'merchant']);

        // Filter by Search (Reference ID, Merchant Reference ID, or Merchant Name)
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('reference_id', 'like', "%{$search}%")
                    ->orWhere('merchant_ref_id', 'like', "%{$search}%")
                    ->orWhereHas('merchant', function ($mQuery) use ($search) {
                        $mQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // Filter by Status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by Gateway
        if ($request->filled('gateway_id')) {
            $gatewayId = $request->input('gateway_id');
            $query->whereHas('paymentMethod', function ($q) use ($gatewayId) {
                $q->where('payment_gateway_id', $gatewayId);
            });
        }

        $transactions = $query->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(function ($tx) {
                return [
                    'id' => $tx->id,
                    'reference_id' => $tx->reference_id,
                    'merchant_name' => $tx->merchant->name,
                    'gateway_name' => $tx->paymentMethod->gateway->name ?? 'Unknown',
                    'method_name' => $tx->paymentMethod->name,
                    'amount' => $tx->amount,
                    'fee' => $tx->fee,
                    'total_amount' => $tx->total_amount,
                    'status' => $tx->status,
                    'created_at' => $tx->created_at->toIso8601String(),
                ];
            });

        $gateways = PaymentGateway::select('id', 'name')->get();

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'gateways' => $gateways,
            'filters' => $request->only(['search', 'status', 'gateway_id']),
        ]);
    }

    public function show(Transaction $transaction): Response
    {
        $transaction->load(['paymentMethod.gateway', 'merchant', 'webhookLogs' => function ($q) {
            $q->latest();
        }]);

        $details = [
            'id' => $transaction->id,
            'reference_id' => $transaction->reference_id,
            'pg_ref_id' => $transaction->pg_ref_id,
            'merchant_name' => $transaction->merchant->name,
            'merchant_ref_id' => $transaction->merchant_ref_id,
            'gateway_name' => $transaction->paymentMethod->gateway->name ?? 'Unknown',
            'method_name' => $transaction->paymentMethod->name,
            'amount' => $transaction->amount,
            'fee' => $transaction->fee,
            'total_amount' => $transaction->total_amount,
            'status' => $transaction->status,
            'pg_status' => $transaction->pg_status,
            'paid_at' => $transaction->paid_at ? $transaction->paid_at->toIso8601String() : null,
            'expired_at' => $transaction->expired_at ? $transaction->expired_at->toIso8601String() : null,
            'created_at' => $transaction->created_at->toIso8601String(),
            'pg_response' => $transaction->pg_response,
            'webhook_logs' => $transaction->webhookLogs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'direction' => $log->direction,
                    'status_code' => $log->status_code,
                    'payload' => $log->payload,
                    'notes' => $log->notes,
                    'created_at' => $log->created_at->toIso8601String(),
                ];
            }),
        ];

        return Inertia::render('Transactions/Show', [
            'transaction' => $details,
        ]);
    }

    public function resendWebhook(Transaction $transaction): RedirectResponse
    {
        try {
            SendMerchantCallbackJob::dispatchSync($transaction);

            return Inertia::flash('success', 'Webhook callback has been resent to merchant successfully.')->back();
        } catch (\Throwable $e) {
            return Inertia::flash('error', 'Failed to resend webhook: '.$e->getMessage())->back();
        }
    }
}
