<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendMerchantCallbackJob;
use App\Models\Transaction;
use App\Services\PaymentGateway\PaymentGatewayManager;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
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

        $processedCount = 0;

        Transaction::with('paymentMethod.gateway')
            ->where('status', 'PENDING')
            ->where('created_at', '>=', now()->subHours(24))
            ->chunkById(100, function (Collection $transactions) use ($gatewayManager, &$processedCount): void {
                foreach ($transactions as $transaction) {
                    $processedCount++;
                    $this->reconcileTransaction($transaction, $gatewayManager);
                }
            });

        $this->info("Transaction reconciliation completed. Processed {$processedCount} pending transactions.");

        return self::SUCCESS;
    }

    /**
     * Reconcile a single pending transaction under a distributed cache lock.
     */
    private function reconcileTransaction(Transaction $transaction, PaymentGatewayManager $gatewayManager): void
    {
        $this->info("Reconciling transaction: {$transaction->reference_id}...");

        $lockKey = 'webhook_lock_'.$transaction->reference_id;
        $lock = Cache::lock($lockKey, 10);

        if (! $lock->get()) {
            $this->line("Transaction {$transaction->reference_id} is currently locked by another process. Skipping.");

            return;
        }

        $shouldDispatchCallback = false;

        try {
            DB::transaction(function () use (&$transaction, $gatewayManager, &$shouldDispatchCallback): void {
                $lockedTransaction = Transaction::with('paymentMethod.gateway')
                    ->whereKey($transaction->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedTransaction || $lockedTransaction->status !== 'PENDING') {
                    $status = $lockedTransaction->status ?? 'MISSING';
                    $this->line("Transaction {$transaction->reference_id} is no longer PENDING (status: {$status}). Skipping.");

                    return;
                }

                $transaction = $lockedTransaction;

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
                        $shouldDispatchCallback = true;
                    }
                }

                // Check local expiration if it is still PENDING
                $transaction->refresh();
                if ($transaction->status === 'PENDING' && $transaction->expired_at !== null && $transaction->expired_at->isPast()) {
                    $transaction->update(['status' => 'EXPIRED']);
                    $this->info("Transaction {$transaction->reference_id} marked as EXPIRED (local expiration reached).");
                    $shouldDispatchCallback = true;
                }
            });

            if ($shouldDispatchCallback) {
                SendMerchantCallbackJob::dispatchMerchantCallback($transaction);
            }
        } catch (Throwable $e) {
            Log::error("Failed to reconcile transaction {$transaction->reference_id}", [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
            $this->error("Error reconciling {$transaction->reference_id}: ".$e->getMessage());
        } finally {
            $lock->release();
        }
    }
}
