<?php

namespace App\Console\Commands;

use App\Jobs\SendMerchantCallbackJob;
use App\Models\Transaction;
use App\Services\PaymentGateway\PaymentGatewayManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileTransactionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reconcile-transactions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile pending transactions with the payment gateway status';

    /**
     * Execute the console command.
     */
    public function handle(PaymentGatewayManager $gatewayManager): int
    {
        $this->info('Starting transaction reconciliation...');

        $transactions = Transaction::with('paymentMethod.gateway')
            ->where('status', 'PENDING')
            ->where('created_at', '>=', now()->subHours(24))
            ->get();

        $this->info("Found {$transactions->count()} pending transactions to check.");

        foreach ($transactions as $transaction) {
            $this->info("Reconciling transaction: {$transaction->reference_id}...");

            $lockKey = 'webhook_lock_'.$transaction->reference_id;
            $lock = Cache::lock($lockKey, 10);

            try {
                $lock->get(function () use ($transaction, $gatewayManager) {
                    DB::transaction(function () use ($transaction, $gatewayManager) {
                        $transaction->refresh();

                        if ($transaction->status !== 'PENDING') {
                            $this->line("Transaction {$transaction->reference_id} is no longer PENDING (status: {$transaction->status}). Skipping.");

                            return;
                        }

                        $gateway = $transaction->paymentMethod->gateway;
                        $driver = $gatewayManager->driver($gateway);

                        // Call checkStatus on resolved driver
                        $statusResponse = $driver->checkStatus($transaction);

                        $newPgStatus = $statusResponse->rawResponse['transaction_status'] ?? $statusResponse->rawResponse['status'] ?? $statusResponse->status;

                        $isStatusChanged = $transaction->status !== $statusResponse->status;
                        $isPgStatusChanged = $transaction->pg_status !== $newPgStatus;

                        if ($isStatusChanged || $isPgStatusChanged) {
                            $oldStatus = $transaction->status;

                            $transaction->update([
                                'status' => $statusResponse->status,
                                'paid_at' => $statusResponse->paidAt,
                                'pg_status' => $newPgStatus,
                                'pg_response' => array_merge($transaction->pg_response ?? [], [
                                    'reconciliation' => $statusResponse->rawResponse,
                                ]),
                            ]);

                            if ($isStatusChanged) {
                                $this->info("Transaction {$transaction->reference_id} status updated from {$oldStatus} to {$statusResponse->status}.");
                                SendMerchantCallbackJob::dispatch($transaction);
                            }
                        }

                        // Check local expiration if it is still PENDING
                        $transaction->refresh();
                        if ($transaction->status === 'PENDING' && $transaction->expired_at->isPast()) {
                            $transaction->update(['status' => 'EXPIRED']);
                            $this->info("Transaction {$transaction->reference_id} marked as EXPIRED (local expiration reached).");
                            SendMerchantCallbackJob::dispatch($transaction);
                        }
                    });
                });
            } catch (Throwable $e) {
                Log::error("Failed to reconcile transaction {$transaction->reference_id}", [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Error reconciling {$transaction->reference_id}: ".$e->getMessage());
            }
        }

        $this->info('Transaction reconciliation completed.');

        return 0;
    }
}
