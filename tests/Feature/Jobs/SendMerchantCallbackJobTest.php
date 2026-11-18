<?php

declare(strict_types=1);

use App\Jobs\SendMerchantCallbackJob;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('it successfully dispatches callback to merchant with hmac signature using webhook_secret and logs it', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://merchant.example.com/webhook' => Http::response(['success' => true], 200),
    ]);

    $merchant = Merchant::factory()->create([
        'api_key' => 'merchant-api-key',
        'webhook_secret' => 'whsec_dedicated_signing_secret',
        'webhook_url' => 'https://merchant.example.com/webhook',
    ]);

    $gateway = PaymentGateway::factory()->create(['code' => 'midtrans']);
    $method = PaymentMethod::factory()->for($gateway, 'gateway')->create();
    $transaction = Transaction::factory()
        ->for($merchant)
        ->for($method, 'paymentMethod')
        ->paid()
        ->create(['reference_id' => 'tx-123456', 'merchant_ref_id' => 'INV-1001']);

    $job = new SendMerchantCallbackJob($transaction);
    $job->handle();

    Http::assertSent(function (Request $request) use ($merchant, $transaction): bool {
        $signature = $request->header('X-Bridge-Signature')[0] ?? '';
        $body = $request->body();

        $expectedSignature = hash_hmac('sha256', $body, $merchant->signingSecret());

        return $request->url() === 'https://merchant.example.com/webhook'
            && $request->method() === 'POST'
            && hash_equals($expectedSignature, $signature)
            && $request['reference_id'] === $transaction->reference_id
            && $request['status'] === 'PAID';
    });

    $this->assertDatabaseHas('webhook_logs', [
        'transaction_id' => $transaction->id,
        'direction' => 'outgoing',
        'status_code' => 200,
        'notes' => 'Callback sent successfully and accepted by merchant.',
    ]);

    $transaction->refresh();
    expect($transaction->status)->toBe('DONE');
});

test('it throws exception on callback failure to trigger retry', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://merchant.example.com/webhook' => Http::response('Server Error', 500),
    ]);

    $merchant = Merchant::factory()->create([
        'webhook_url' => 'https://merchant.example.com/webhook',
    ]);

    $gateway = PaymentGateway::factory()->create(['code' => 'midtrans']);
    $method = PaymentMethod::factory()->for($gateway, 'gateway')->create();
    $transaction = Transaction::factory()
        ->for($merchant)
        ->for($method, 'paymentMethod')
        ->paid()
        ->create(['reference_id' => 'tx-123456', 'merchant_ref_id' => 'INV-1001']);

    $job = new SendMerchantCallbackJob($transaction);

    try {
        $job->handle();
        $this->fail('Expected exception was not thrown.');
    } catch (Throwable) {
        $this->assertDatabaseHas('webhook_logs', [
            'transaction_id' => $transaction->id,
            'direction' => 'outgoing',
            'status_code' => 500,
        ]);
    }
});
