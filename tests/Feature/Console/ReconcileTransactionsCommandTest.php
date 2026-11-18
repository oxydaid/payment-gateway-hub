<?php

declare(strict_types=1);

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

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/tx-pending-123/status' => Http::response([
            'transaction_status' => 'settlement',
            'transaction_id' => 'midtrans-tx-123',
            'settlement_time' => now()->toDateTimeString(),
        ], 200),
    ]);

    $merchant = Merchant::factory()->create();
    $gateway = PaymentGateway::factory()->create([
        'code' => 'midtrans',
        'credentials' => ['server_key' => 'midtrans-server-key'],
    ]);
    $method = PaymentMethod::factory()->for($gateway, 'gateway')->create();
    $transaction = Transaction::factory()
        ->for($merchant)
        ->for($method, 'paymentMethod')
        ->create([
            'reference_id' => 'tx-pending-123',
            'merchant_ref_id' => 'INV-1001',
        ]);

    $this->artisan('app:reconcile-transactions')
        ->assertExitCode(0);

    $transaction->refresh();
    expect($transaction->status)->toBe('PAID');
    expect($transaction->paid_at)->not->toBeNull();

    Queue::assertPushed(SendMerchantCallbackJob::class, function ($job) use ($transaction): bool {
        return $job->transaction->id === $transaction->id;
    });
});

test('it marks expired transaction as EXPIRED and dispatches callback', function (): void {
    Queue::fake();
    Http::preventStrayRequests();

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/tx-expired-123/status' => Http::response([
            'transaction_status' => 'pending',
            'transaction_id' => 'midtrans-tx-123',
        ], 200),
    ]);

    $merchant = Merchant::factory()->create();
    $gateway = PaymentGateway::factory()->create([
        'code' => 'midtrans',
        'credentials' => ['server_key' => 'midtrans-server-key'],
    ]);
    $method = PaymentMethod::factory()->for($gateway, 'gateway')->create();
    $transaction = Transaction::factory()
        ->for($merchant)
        ->for($method, 'paymentMethod')
        ->create([
            'reference_id' => 'tx-expired-123',
            'merchant_ref_id' => 'INV-1002',
            'expired_at' => now()->subHour(),
        ]);

    $this->artisan('app:reconcile-transactions')
        ->assertExitCode(0);

    $transaction->refresh();
    expect($transaction->status)->toBe('EXPIRED');

    Queue::assertPushed(SendMerchantCallbackJob::class);
});
