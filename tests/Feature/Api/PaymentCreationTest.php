<?php

use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('it blocks request without api key', function (): void {
    $response = $this->postJson(route('payments.store'));
    $response->assertStatus(401);
});

test('it blocks request with invalid api key', function (): void {
    $response = $this->withToken('invalid-key')->postJson(route('payments.store'));
    $response->assertStatus(401);
});

test('it creates a payment successfully using midtrans', function (): void {
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'mock-token',
            'redirect_url' => 'https://mock-checkout-url.com',
        ], 201),
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

    $response = $this->withToken('test-key')->postJson(route('payments.store'), [
        'merchant_ref_id' => 'INV-1001',
        'payment_method_id' => $method->id,
        'amount' => 100000,
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('success', true);
    $response->assertJsonPath('data.amount', 100000);
    $response->assertJsonPath('data.fee', 4500);
    $response->assertJsonPath('data.total_amount', 104500);
    $response->assertJsonPath('data.checkout_url', 'https://mock-checkout-url.com');
    $response->assertJsonPath('data.status', 'PENDING');

    $this->assertDatabaseHas('transactions', [
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-1001',
        'payment_method_id' => $method->id,
        'amount' => 100000,
        'fee' => 4500,
        'total_amount' => 104500,
        'status' => 'PENDING',
        'checkout_url' => 'https://mock-checkout-url.com',
    ]);
});

test('it calculates mix fee type successfully', function (): void {
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'mock-token',
            'redirect_url' => 'https://mock-checkout-url.com',
        ], 201),
    ]);

    $merchant = Merchant::create([
        'name' => 'Test Merchant Mix',
        'api_key' => 'test-key-mix',
        'is_active' => true,
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Midtrans',
        'code' => 'midtrans',
        'is_active' => true,
    ]);

    $method = PaymentMethod::create([
        'payment_gateway_id' => $gateway->id,
        'name' => 'QRIS Mix Fee',
        'code' => 'qris_mix',
        'type' => 'qris',
        'fee_type' => 'mix',
        'fee_fix' => 2000,
        'fee_percent' => 0.7,
        'is_active' => true,
    ]);

    $response = $this->withToken('test-key-mix')->postJson(route('payments.store'), [
        'merchant_ref_id' => 'INV-MIX-1',
        'payment_method_id' => $method->id,
        'amount' => 100000,
    ]);

    // 100000 * 0.7% = 700. 700 + 2000 = 2700.
    $response->assertStatus(201);
    $response->assertJsonPath('success', true);
    $response->assertJsonPath('data.amount', 100000);
    $response->assertJsonPath('data.fee', 2700);
    $response->assertJsonPath('data.total_amount', 102700);
});

test('it fetches active payment methods successfully', function (): void {
    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'api_key' => 'test-key',
        'is_active' => true,
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Midtrans',
        'code' => 'midtrans',
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

    $response = $this->withToken('test-key')->getJson(route('payments.methods'));

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
    $response->assertJsonStructure([
        'success',
        'data' => [
            '*' => [
                'id',
                'name',
                'code',
                'type',
                'fee_type',
                'fee_fix',
                'fee_percent',
                'icon_url',
                'gateway_code',
                'is_active',
            ],
        ],
    ]);
});

test('it fetches merchant transactions paginated', function (): void {
    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'api_key' => 'test-key',
        'is_active' => true,
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Midtrans',
        'code' => 'midtrans',
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

    Transaction::create([
        'reference_id' => 'tx-1',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-1',
        'payment_method_id' => $method->id,
        'amount' => 10000,
        'fee' => 4500,
        'total_amount' => 14500,
        'status' => 'PENDING',
        'expired_at' => now()->addHours(24),
    ]);

    $response = $this->withToken('test-key')->getJson(route('payments.transactions'));

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
    $response->assertJsonStructure([
        'data' => [
            '*' => [
                'reference_id',
                'merchant_ref_id',
                'amount',
                'fee',
                'total_amount',
                'status',
            ],
        ],
        'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
    ]);
});

test('it shows transaction details', function (): void {
    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'api_key' => 'test-key',
        'is_active' => true,
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Midtrans',
        'code' => 'midtrans',
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

    $tx = Transaction::create([
        'reference_id' => 'tx-show-test',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-2',
        'payment_method_id' => $method->id,
        'amount' => 10000,
        'fee' => 4500,
        'total_amount' => 14500,
        'status' => 'PENDING',
        'expired_at' => now()->addHours(24),
    ]);

    $response = $this->withToken('test-key')->getJson(route('payments.show', ['reference_id' => 'tx-show-test']));

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
    $response->assertJsonPath('data.reference_id', 'tx-show-test');
});

test('it fetches merchant webhook logs paginated', function (): void {
    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'api_key' => 'test-key',
        'is_active' => true,
    ]);

    $gateway = PaymentGateway::create([
        'name' => 'Midtrans',
        'code' => 'midtrans',
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

    $tx = Transaction::create([
        'reference_id' => 'tx-log-test',
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-3',
        'payment_method_id' => $method->id,
        'amount' => 10000,
        'fee' => 4500,
        'total_amount' => 14500,
        'status' => 'PENDING',
        'expired_at' => now()->addHours(24),
    ]);

    WebhookLog::create([
        'transaction_id' => $tx->id,
        'direction' => 'incoming',
        'payload' => ['event' => 'test'],
        'status_code' => 200,
    ]);

    $response = $this->withToken('test-key')->getJson(route('payments.webhook-logs'));

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
    $response->assertJsonStructure([
        'data' => [
            '*' => [
                'id',
                'transaction_ref_id',
                'direction',
                'payload',
                'status_code',
                'notes',
            ],
        ],
        'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
    ]);
});
