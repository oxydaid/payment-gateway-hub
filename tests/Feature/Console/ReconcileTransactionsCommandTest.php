<?php

use App\Jobs\SendMerchantCallbackJob;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('it reconciles pending transaction to PAID and dispatches callback', function (): void {
    Queue::fake();
    Http::preventStrayRequests();

    // Fake Midtrans Status API
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/tx-pending-123/status' => Http::response([
            'transaction_status' => 'settlement',
            'transaction_id' => 'midtrans-tx-123',
            'settlement_time' => now()->toDateTimeString(),
        ], 200),
    ]);

    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'api_key' => 'test-key',
        'is_active' => true,
        'webhook_url' => 'https://merchant.com/webhook',
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Midtrans',
        'code' => 'midtrans',
        'credentials' => [
            'server_key' => 'midtrans-server-key',
        ],
        'is_active' => true,
    ]);

    $method = PaymentMethod::create([
        'payment_gateway_id' => $gateway->id,
        'name' => 'BCA Virtual Account',
        'code' => 'bca_va',
        'type' => 'va',
        'fee_type' => 'fix',
        'fee_fix' => 4500,
        'fee_percent' => 0,
        'is_active' => true,
    ]);

    $transaction = Transaction::create([
        'reference_id' => 'tx-pending-123',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-1001',
        'payment_method_id' => $method->id,
        'amount' => 100000,
        'fee' => 4500,
        'total_amount' => 104500,
        'status' => 'PENDING',
        'expired_at' => now()->addHours(24),
    ]);

    $this->artisan('app:reconcile-transactions')
        ->assertExitCode(0);

    $transaction->refresh();
    expect($transaction->status)->toBe('PAID');
    expect($transaction->paid_at)->not->toBeNull();

    Queue::assertPushed(SendMerchantCallbackJob::class, function ($job) use ($transaction) {
        return $job->transaction->id === $transaction->id;
    });
});

test('it marks expired transaction as EXPIRED and dispatches callback', function (): void {
    Queue::fake();
    Http::preventStrayRequests();

    // Fake Midtrans Status API still returns pending, but local expiration is reached
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/tx-expired-123/status' => Http::response([
            'transaction_status' => 'pending',
            'transaction_id' => 'midtrans-tx-123',
        ], 200),
    ]);

    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'api_key' => 'test-key',
        'is_active' => true,
        'webhook_url' => 'https://merchant.com/webhook',
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Midtrans',
        'code' => 'midtrans',
        'credentials' => [
            'server_key' => 'midtrans-server-key',
        ],
        'is_active' => true,
    ]);

    $method = PaymentMethod::create([
        'payment_gateway_id' => $gateway->id,
        'name' => 'BCA Virtual Account',
        'code' => 'bca_va',
        'type' => 'va',
        'fee_type' => 'fix',
        'fee_fix' => 4500,
        'fee_percent' => 0,
        'is_active' => true,
    ]);

    // Create a transaction that expired 1 hour ago
    $transaction = Transaction::create([
        'reference_id' => 'tx-expired-123',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-1002',
        'payment_method_id' => $method->id,
        'amount' => 100000,
        'fee' => 4500,
        'total_amount' => 104500,
        'status' => 'PENDING',
        'expired_at' => now()->subHour(),
    ]);

    $this->artisan('app:reconcile-transactions')
        ->assertExitCode(0);

    $transaction->refresh();
    expect($transaction->status)->toBe('EXPIRED');

    Queue::assertPushed(SendMerchantCallbackJob::class);
});
