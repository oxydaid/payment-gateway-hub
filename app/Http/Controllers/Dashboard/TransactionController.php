<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\Dashboard\TransactionListResource;
use App\Jobs\SendMerchantCallbackJob;
use App\Models\PaymentGateway;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Transaction::with(['paymentMethod.gateway', 'merchant']);

        // Filter by Search (Reference ID, Merchant Reference ID, or Merchant Name)
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $isMysqlFamily = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

            $query->where(function ($q) use ($search, $isMysqlFamily): void {
                // Prefix match utilizes B-tree indexes on reference_id and merchant_ref_id
                $q->where('reference_id', 'like', "{$search}%")
                    ->orWhere('merchant_ref_id', 'like', "{$search}%")
                    ->orWhereHas('merchant', function ($mQuery) use ($search): void {
                        $mQuery->where('name', 'like', "{$search}%");
                    });

                // On MariaDB/MySQL, also leverage InnoDB FULLTEXT index on merchant_ref_id for multi-token searches (>= 3 chars)
                if ($isMysqlFamily && mb_strlen($search) >= 3) {
                    $q->orWhereFullText(['merchant_ref_id'], $search);
                }
            });
        }

        // Filter by Status
        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        // Filter by Gateway
        if ($request->filled('gateway_id')) {
            $gatewayId = $request->input('gateway_id');
            $query->whereHas('paymentMethod', function ($q) use ($gatewayId): void {
                $q->where('payment_gateway_id', $gatewayId);
            });
        }

        $transactions = $query->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Transaction $tx): array => (new TransactionListResource($tx))->resolve($request));

        $gateways = PaymentGateway::select('id', 'name')->get();

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'gateways' => $gateways,
            'filters' => $request->only(['search', 'status', 'gateway_id']),
        ]);
    }

    public function show(Transaction $transaction): Response
    {
        $transaction->load(['paymentMethod.gateway', 'merchant', 'webhookLogs' => function ($q): void {
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
            'paid_at' => $transaction->paid_at?->toIso8601String(),
            'expired_at' => $transaction->expired_at?->toIso8601String(),
            'created_at' => $transaction->created_at?->toIso8601String(),
            'pg_response' => $transaction->pg_response,
            'webhook_logs' => $transaction->webhookLogs->map(function ($log): array {
                return [
                    'id' => $log->id,
                    'direction' => $log->direction,
                    'status_code' => $log->status_code,
                    'payload' => $log->payload,
                    'notes' => $log->notes,
                    'created_at' => $log->created_at?->toIso8601String(),
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
