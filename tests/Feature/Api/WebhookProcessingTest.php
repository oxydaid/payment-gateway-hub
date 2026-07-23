<?php

use App\Jobs\SendMerchantCallbackJob;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('it processes midtrans webhook successfully', function (): void {
    Queue::fake();

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
        'reference_id' => 'tx-123456',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-1001',
        'payment_method_id' => $method->id,
        'amount' => 100000,
        'fee' => 4500,
        'total_amount' => 104500,
        'status' => 'PENDING',
        'expired_at' => now()->addHours(24),
    ]);

    // Midtrans webhook payload signature key formula: order_id + status_code + gross_amount + ServerKey
    $orderId = 'tx-123456';
    $statusCode = '200';
    $grossAmount = '104500.00';
    $serverKey = 'midtrans-server-key';
    $signature = hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);

    $payload = [
        'order_id' => $orderId,
        'status_code' => $statusCode,
        'gross_amount' => $grossAmount,
        'signature_key' => $signature,
        'transaction_status' => 'settlement',
        'transaction_id' => 'midtrans-tx-999',
        'settlement_time' => now()->toDateTimeString(),
    ];

    $response = $this->postJson(route('webhooks.midtrans'), $payload);

    $response->assertOk();
    $response->assertJsonPath('success', true);

    $transaction->refresh();
    expect($transaction->status)->toBe('PAID');
    expect($transaction->paid_at)->not->toBeNull();
    expect($transaction->pg_ref_id)->toBe('midtrans-tx-999');

    Queue::assertPushed(SendMerchantCallbackJob::class, function ($job) use ($transaction) {
        return $job->transaction->id === $transaction->id;
    });
});

test('it processes tripay webhook successfully', function (): void {
    Queue::fake();

    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'api_key' => 'test-key',
        'is_active' => true,
        'webhook_url' => 'https://merchant.com/webhook',
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Tripay',
        'code' => 'tripay',
        'credentials' => [
            'private_key' => 'tripay-private-key',
            'api_key' => 'tripay-api-key',
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
        'reference_id' => 'tx-tripay-123',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-1002',
        'payment_method_id' => $method->id,
        'amount' => 100000,
        'fee' => 4500,
        'total_amount' => 104500,
        'status' => 'PENDING',
        'expired_at' => now()->addHours(24),
    ]);

    $payload = [
        'reference' => 'tripay-tx-999',
        'merchant_ref' => 'tx-tripay-123',
        'status' => 'PAID',
        'paid_at' => time(),
        'total_amount' => 104500,
        'is_closed_payment' => 1,
    ];

    $rawBody = json_encode($payload);
    $signature = hash_hmac('sha256', $rawBody, 'tripay-private-key');

    $response = $this->withHeaders([
        'X-Callback-Signature' => $signature,
        'X-Callback-Event' => 'payment_status',
    ])->postJson(route('webhooks.tripay'), $payload);

    $response->assertOk();
    $response->assertJsonPath('success', true);

    $transaction->refresh();
    expect($transaction->status)->toBe('PAID');
    expect($transaction->paid_at)->not->toBeNull();

    Queue::assertPushed(SendMerchantCallbackJob::class);
});

test('it processes tripay webhook successfully with case-insensitive and whitespace event header', function (): void {
    Queue::fake();

    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'api_key' => 'test-key',
        'is_active' => true,
        'webhook_url' => 'https://merchant.com/webhook',
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Tripay',
        'code' => 'tripay',
        'credentials' => [
            'merchant_code' => 'T123',
            'api_key' => 'tripay-api-key',
            'private_key' => 'tripay-private-key',
            'is_production' => false,
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
        'reference_id' => 'tx-tripay-124',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-1003',
        'payment_method_id' => $method->id,
        'amount' => 100000,
        'fee' => 4500,
        'total_amount' => 104500,
        'status' => 'PENDING',
        'expired_at' => now()->addHours(24),
    ]);

    $payload = [
        'reference' => 'tripay-tx-9992',
        'merchant_ref' => 'tx-tripay-124',
        'status' => 'PAID',
        'paid_at' => time(),
        'total_amount' => 104500,
        'is_closed_payment' => 1,
    ];

    $rawBody = json_encode($payload);
    $signature = hash_hmac('sha256', $rawBody, 'tripay-private-key');

    $response = $this->withHeaders([
        'X-Callback-Signature' => $signature,
        'X-Callback-Event' => ' Payment_Status ',
    ])->postJson(route('webhooks.tripay'), $payload);

    $response->assertOk();
    $response->assertJsonPath('success', true);

    $transaction->refresh();
    expect($transaction->status)->toBe('PAID');
});

test('it processes tokopay webhook successfully', function (): void {
    Queue::fake();

    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'api_key' => 'test-key',
        'is_active' => true,
        'webhook_url' => 'https://merchant.com/webhook',
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Tokopay',
        'code' => 'tokopay',
        'credentials' => [
            'merchant_id' => 'tokopay-merchant-id',
            'secret_key' => 'tokopay-secret-key',
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
        'reference_id' => 'tx-tokopay-123',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-1003',
        'payment_method_id' => $method->id,
        'amount' => 100000,
        'fee' => 4500,
        'total_amount' => 104500,
        'status' => 'PENDING',
        'expired_at' => now()->addHours(24),
    ]);

    $payload = [
        'data' => [
            'merchant_id' => 'tokopay-merchant-id',
            'updated_at' => now()->toDateTimeString(),
        ],
        'reference' => 'tokopay-tx-999',
        'reff_id' => 'tx-tokopay-123',
        'status' => 'Success',
        // Signature formula standard or colon-separated. Let's use standard: merchant_id + secret_key + ref_id + status
        'signature' => md5('tokopay-merchant-id'.'tokopay-secret-key'.'tx-tokopay-123'.'Success'),
    ];

    $response = $this->postJson(route('webhooks.tokopay'), $payload);

    $response->assertOk();
    $response->assertJsonPath('status', 'true');

    $transaction->refresh();
    expect($transaction->status)->toBe('PAID');
    expect($transaction->paid_at)->not->toBeNull();

    Queue::assertPushed(SendMerchantCallbackJob::class);
});

test('it processes xendit webhook successfully', function (): void {
    Queue::fake();

    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'api_key' => 'test-key',
        'is_active' => true,
        'webhook_url' => 'https://merchant.com/webhook',
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Xendit',
        'code' => 'xendit',
        'credentials' => [
            'secret_key' => 'xendit-secret-key',
            'callback_token' => 'xendit-callback-token',
        ],
        'is_active' => true,
    ]);

    $method = PaymentMethod::create([
        'payment_gateway_id' => $gateway->id,
        'name' => 'Xendit Invoice',
        'code' => 'xendit_invoice',
        'type' => 'va',
        'fee_type' => 'fix',
        'fee_fix' => 4500,
        'fee_percent' => 0,
        'is_active' => true,
    ]);

    $transaction = Transaction::create([
        'reference_id' => 'tx-xendit-123',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-1004',
        'payment_method_id' => $method->id,
        'amount' => 100000,
        'fee' => 4500,
        'total_amount' => 104500,
        'status' => 'PENDING',
        'expired_at' => now()->addHours(24),
    ]);

    $payload = [
        'id' => 'xendit-invoice-999',
        'external_id' => 'tx-xendit-123',
        'status' => 'PAID',
        'updated' => now()->toIso8601String(),
    ];

    $response = $this->withHeaders([
        'X-Callback-Token' => 'xendit-callback-token',
    ])->postJson(route('webhooks.xendit'), $payload);

    $response->assertOk();
    $response->assertJsonPath('success', true);

    $transaction->refresh();
    expect($transaction->status)->toBe('PAID');
    expect($transaction->paid_at)->not->toBeNull();
    expect($transaction->pg_ref_id)->toBe('xendit-invoice-999');

    Queue::assertPushed(SendMerchantCallbackJob::class);
});

test('it processes xendit v3 payment request webhook successfully', function (): void {
    Queue::fake();

    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'api_key' => 'test-key',
        'is_active' => true,
        'webhook_url' => 'https://merchant.com/webhook',
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Xendit',
        'code' => 'xendit',
        'credentials' => [
            'secret_key' => 'xendit-secret-key',
            'callback_token' => 'xendit-callback-token',
        ],
        'is_active' => true,
    ]);

    $method = PaymentMethod::create([
        'payment_gateway_id' => $gateway->id,
        'name' => 'Xendit Mandiri VA',
        'code' => 'mandiri_va',
        'type' => 'va',
        'fee_type' => 'fix',
        'fee_fix' => 4500,
        'fee_percent' => 0,
        'is_active' => true,
    ]);

    $transaction = Transaction::create([
        'reference_id' => 'tx-xendit-v3-123',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-1005',
        'payment_method_id' => $method->id,
        'amount' => 100000,
        'fee' => 4500,
        'total_amount' => 104500,
        'status' => 'PENDING',
        'expired_at' => now()->addHours(24),
    ]);

    $payload = [
        'event' => 'payment.capture',
        'business_id' => '6094fa76c2fd53701b8e079c',
        'created' => now()->toIso8601String(),
        'data' => [
            'payment_id' => 'py-1fdaf346-dd2e-4b6c-b938-124c7167a822',
            'business_id' => '6094fa76c2fd53701b8e079c',
            'status' => 'SUCCEEDED',
            'payment_request_id' => 'pr-xendit-req-123',
            'request_amount' => 104500,
            'channel_code' => 'MANDIRI',
            'country' => 'ID',
            'currency' => 'IDR',
            'reference_id' => 'tx-xendit-v3-123',
            'type' => 'SINGLE_PAYMENT',
            'created' => now()->toIso8601String(),
            'updated' => now()->toIso8601String(),
        ],
    ];

    $response = $this->withHeaders([
        'X-Callback-Token' => 'xendit-callback-token',
    ])->postJson(route('webhooks.xendit'), $payload);

    $response->assertOk();
    $response->assertJsonPath('success', true);

    $transaction->refresh();
    expect($transaction->status)->toBe('PAID');
    expect($transaction->paid_at)->not->toBeNull();
    expect($transaction->pg_ref_id)->toBe('pr-xendit-req-123');

    Queue::assertPushed(SendMerchantCallbackJob::class);
});
