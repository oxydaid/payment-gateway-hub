<?php

use App\Jobs\SendMerchantCallbackJob;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('it successfully dispatches callback to merchant with hmac signature and logs it', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://merchant.com/webhook' => Http::response(['success' => true], 200),
    ]);

    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'api_key' => 'merchant-api-key',
        'is_active' => true,
        'webhook_url' => 'https://merchant.com/webhook',
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Midtrans',
        'code' => 'midtrans',
        'credentials' => [],
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
        'reference_id' => 'tx-123456',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-1001',
        'payment_method_id' => $method->id,
        'amount' => 100000,
        'fee' => 4500,
        'total_amount' => 104500,
        'status' => 'PAID',
        'paid_at' => now(),
        'expired_at' => now()->addHours(24),
    ]);

    // Run job synchronously
    $job = new SendMerchantCallbackJob($transaction);
    $job->handle();

    // Verify HTTP request
    Http::assertSent(function (Request $request) use ($merchant, $transaction): bool {
        $signature = $request->header('X-Bridge-Signature')[0] ?? '';
        $body = $request->body();

        // Sign payload with merchant api key
        $expectedSignature = hash_hmac('sha256', $body, $merchant->api_key);

        return $request->url() === 'https://merchant.com/webhook'
            && $request->method() === 'POST'
            && hash_equals($expectedSignature, $signature)
            && $request['reference_id'] === $transaction->reference_id
            && $request['status'] === 'PAID';
    });

    // Verify webhook log created in DB
    $this->assertDatabaseHas('webhook_logs', [
        'transaction_id' => $transaction->id,
        'direction' => 'outgoing',
        'status_code' => 200,
        'notes' => 'Callback sent successfully and accepted by merchant.',
    ]);

    // Verify transaction status updated to DONE
    $transaction->refresh();
    expect($transaction->status)->toBe('DONE');
});

test('it throws exception on callback failure to trigger retry', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://merchant.com/webhook' => Http::response('Server Error', 500),
    ]);

    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'api_key' => 'merchant-api-key',
        'is_active' => true,
        'webhook_url' => 'https://merchant.com/webhook',
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Midtrans',
        'code' => 'midtrans',
        'credentials' => [],
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
        'reference_id' => 'tx-123456',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-1001',
        'payment_method_id' => $method->id,
        'amount' => 100000,
        'fee' => 4500,
        'total_amount' => 104500,
        'status' => 'PAID',
        'paid_at' => now(),
        'expired_at' => now()->addHours(24),
    ]);

    $job = new SendMerchantCallbackJob($transaction);

    try {
        $job->handle();
        $this->fail('Expected exception was not thrown.');
    } catch (Throwable $e) {
        // Assert webhook log shows the failed response body
        $this->assertDatabaseHas('webhook_logs', [
            'transaction_id' => $transaction->id,
            'direction' => 'outgoing',
            'status_code' => 500,
        ]);
    }
});
