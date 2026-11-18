<?php

use App\Jobs\SendMerchantCallbackJob;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

test('it processes pakasir webhook successfully', function (): void {
    Queue::fake();

    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'api_key' => 'test-key',
        'is_active' => true,
        'webhook_url' => 'https://merchant.com/webhook',
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Pakasir',
        'code' => 'pakasir',
        'credentials' => [
            'project_slug' => 'depodomain',
            'api_key' => 'pakasir-api-key',
        ],
        'is_active' => true,
    ]);

    $method = PaymentMethod::create([
        'payment_gateway_id' => $gateway->id,
        'name' => 'QRIS (Pakasir)',
        'code' => 'qris',
        'type' => 'qris',
        'fee_type' => 'percent',
        'fee_fix' => 0,
        'fee_percent' => 0.7,
        'is_active' => true,
    ]);

    $transaction = Transaction::create([
        'reference_id' => 'tx-pakasir-123',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-1005',
        'payment_method_id' => $method->id,
        'amount' => 100000,
        'fee' => 700,
        'total_amount' => 100700,
        'status' => 'PENDING',
        'expired_at' => now()->addHours(24),
    ]);

    $payload = [
        'amount' => 100700,
        'order_id' => 'tx-pakasir-123',
        'project' => 'depodomain',
        'status' => 'completed',
        'payment_method' => 'qris',
        'completed_at' => '2026-07-23T10:45:00+07:00',
    ];

    $response = $this->postJson(route('webhooks.pakasir'), $payload);

    $response->assertOk();
    $response->assertJsonPath('success', true);

    $transaction->refresh();
    expect($transaction->status)->toBe('PAID');
    expect($transaction->paid_at)->not->toBeNull();
    expect($transaction->pg_ref_id)->toBe('tx-pakasir-123');

    Queue::assertPushed(SendMerchantCallbackJob::class);
});

test('it processes ariepulsa webhook successfully', function (): void {
    Queue::fake();
    Http::fake([
        'https://ariepulsa.com/api/qrisgo' => Http::response([
            'status' => true,
            'data' => [
                'kode_deposit' => 'TRX-00009',
                'status' => 'Success',
                'jumlah_transfer' => 1007,
                'saldo_diterima' => 1000,
                'detail_pengirim' => '-',
                'fee' => 7,
                'link_payment' => 'https://ariepulsa.com/pay-qrisgo/TRX-00009',
            ],
        ], 200),
    ]);

    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'api_key' => 'test-key',
        'is_active' => true,
        'webhook_url' => 'https://merchant.com/webhook',
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Ariepulsa',
        'code' => 'ariepulsa',
        'credentials' => [
            'api_key' => 'ariepulsa-api-key',
        ],
        'is_active' => true,
    ]);

    $method = PaymentMethod::create([
        'payment_gateway_id' => $gateway->id,
        'name' => 'QRIS GO (Arie Pulsa)',
        'code' => 'qrisgo',
        'type' => 'qris',
        'fee_type' => 'fix',
        'fee_fix' => 7,
        'fee_percent' => 0,
        'is_active' => true,
    ]);

    $transaction = Transaction::create([
        'reference_id' => 'tx-ariepulsa-123',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-1006',
        'payment_method_id' => $method->id,
        'amount' => 1000,
        'fee' => 7,
        'total_amount' => 1007,
        'status' => 'PENDING',
        'pg_ref_id' => 'TRX-00009',
        'expired_at' => now()->addHours(24),
    ]);

    $payload = [
        'status' => true,
        'data' => [
            'kode_deposit' => 'TRX-00009',
            'status' => 'Success',
            'jumlah_transfer' => 1007,
            'saldo_diterima' => 1000,
            'detail_pengirim' => '-',
            'fee' => 7,
            'link_payment' => 'https://ariepulsa.com/pay-qrisgo/TRX-00009',
        ],
    ];

    $response = $this->postJson(route('webhooks.ariepulsa'), $payload);

    $response->assertOk();
    $response->assertJsonPath('success', true);

    $transaction->refresh();
    expect($transaction->status)->toBe('PAID');
    expect($transaction->paid_at)->not->toBeNull();
    expect($transaction->pg_ref_id)->toBe('TRX-00009');

    Queue::assertPushed(SendMerchantCallbackJob::class);
});

test('it returns 200 ok silently when transaction webhook lock is already held', function (): void {
    Queue::fake();

    $merchant = Merchant::factory()->create([
        'is_active' => true,
        'webhook_url' => 'https://merchant.com/webhook',
    ]);

    $gateway = PaymentGateway::factory()->create([
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
        'reference_id' => 'tx-locked-123',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-LOCKED-1',
        'payment_method_id' => $method->id,
        'amount' => 100000,
        'fee' => 4500,
        'total_amount' => 104500,
        'status' => 'PENDING',
        'expired_at' => now()->addHours(24),
    ]);

    $existingLock = Cache::lock('webhook_lock_tx-locked-123', 10);
    expect($existingLock->get())->toBeTrue();

    try {
        $payload = [
            'order_id' => 'tx-locked-123',
            'status_code' => '200',
            'gross_amount' => '104500.00',
            'signature_key' => hash('sha512', 'tx-locked-123200104500.00midtrans-server-key'),
            'transaction_status' => 'settlement',
        ];

        $response = $this->postJson(route('webhooks.midtrans'), $payload);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Webhook already being processed.');

        $transaction->refresh();
        expect($transaction->status)->toBe('PENDING');
        Queue::assertNotPushed(SendMerchantCallbackJob::class);
    } finally {
        $existingLock->release();
    }
});

test('it rejects midtrans webhook with invalid signature returning 403', function (): void {
    Queue::fake();

    $merchant = Merchant::factory()->create();
    $gateway = PaymentGateway::factory()->create([
        'code' => 'midtrans',
        'credentials' => ['server_key' => 'real-midtrans-server-key'],
    ]);
    $method = PaymentMethod::factory()->for($gateway, 'gateway')->create();
    $transaction = Transaction::factory()
        ->for($merchant)
        ->for($method, 'paymentMethod')
        ->create(['reference_id' => 'tx-invalid-midtrans']);

    $response = $this->postJson(route('webhooks.midtrans'), [
        'order_id' => 'tx-invalid-midtrans',
        'status_code' => '200',
        'gross_amount' => '104500.00',
        'signature_key' => 'forged-signature-value',
        'transaction_status' => 'settlement',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $transaction->refresh();
    expect($transaction->status)->toBe('PENDING');
    Queue::assertNotPushed(SendMerchantCallbackJob::class);
});

test('it rejects tokopay webhook with invalid signature returning 403', function (): void {
    Queue::fake();

    $merchant = Merchant::factory()->create();
    $gateway = PaymentGateway::factory()->create([
        'code' => 'tokopay',
        'credentials' => [
            'merchant_id' => 'M123456',
            'secret_key' => 'real-tokopay-secret',
        ],
    ]);
    $method = PaymentMethod::factory()->for($gateway, 'gateway')->create();
    $transaction = Transaction::factory()
        ->for($merchant)
        ->for($method, 'paymentMethod')
        ->create(['reference_id' => 'tx-invalid-tokopay']);

    $response = $this->postJson(route('webhooks.tokopay'), [
        'data' => ['merchant_id' => 'M123456'],
        'reference' => 'tp-ref-1',
        'reff_id' => 'tx-invalid-tokopay',
        'status' => 'Success',
        'signature' => 'invalid-md5-signature',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $transaction->refresh();
    expect($transaction->status)->toBe('PENDING');
    Queue::assertNotPushed(SendMerchantCallbackJob::class);
});

test('it rejects xendit webhook with invalid callback token returning 403', function (): void {
    Queue::fake();

    $merchant = Merchant::factory()->create();
    $gateway = PaymentGateway::factory()->create([
        'code' => 'xendit',
        'credentials' => [
            'secret_key' => 'xnd_secret',
            'callback_token' => 'real-xendit-token',
        ],
    ]);
    $method = PaymentMethod::factory()->for($gateway, 'gateway')->create();
    $transaction = Transaction::factory()
        ->for($merchant)
        ->for($method, 'paymentMethod')
        ->create(['reference_id' => 'tx-invalid-xendit']);

    $response = $this->withHeaders([
        'X-Callback-Token' => 'wrong-token',
    ])->postJson(route('webhooks.xendit'), [
        'event' => 'payment.succeeded',
        'data' => [
            'reference_id' => 'tx-invalid-xendit',
            'status' => 'SUCCEEDED',
        ],
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $transaction->refresh();
    expect($transaction->status)->toBe('PENDING');
    Queue::assertNotPushed(SendMerchantCallbackJob::class);
});

test('it rejects pakasir and ariepulsa webhooks when reference id is missing', function (): void {
    $this->postJson(route('webhooks.pakasir'), ['status' => 'completed'])
        ->assertStatus(400)
        ->assertJsonPath('success', false);

    $this->postJson(route('webhooks.ariepulsa'), ['status' => true, 'data' => []])
        ->assertStatus(400)
        ->assertJsonPath('success', false);
});
