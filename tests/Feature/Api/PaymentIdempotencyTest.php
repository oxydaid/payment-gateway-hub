<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('retrying payment creation with same merchant_ref_id returns existing transaction idempotently', function (): void {
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'midtrans-snap-token',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/midtrans-snap-token',
        ], 201),
    ]);

    $merchant = Merchant::factory()->create();
    $plainToken = 'pb_mcht_'.Str::random(40);
    ApiKey::create([
        'merchant_id' => $merchant->id,
        'name' => 'Idempotency Key Test',
        'key_prefix' => ApiKey::maskToken($plainToken),
        'key_hash' => ApiKey::hashToken($plainToken),
        'is_active' => true,
    ]);

    $gateway = PaymentGateway::factory()->create(['code' => 'midtrans']);
    $method = PaymentMethod::factory()->for($gateway, 'gateway')->create();

    $payload = [
        'merchant_ref_id' => 'INV-IDEM-001',
        'payment_method_id' => $method->id,
        'amount' => 100000,
        'redirect_url' => 'https://merchant.example.com/done',
    ];

    $firstResponse = $this->withToken($plainToken)->postJson(route('payments.store'), $payload);
    $firstResponse->assertStatus(201)->assertJsonPath('success', true);

    $referenceId = $firstResponse->json('data.reference_id');

    $secondResponse = $this->withToken($plainToken)->postJson(route('payments.store'), $payload);
    $secondResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('idempotent', true)
        ->assertJsonPath('data.reference_id', $referenceId);

    // Upstream gateway must only have been invoked once
    Http::assertSentCount(1);
    expect(Transaction::count())->toBe(1);
});

test('retrying payment creation with Idempotency-Key header returns existing transaction', function (): void {
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'midtrans-snap-token-2',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/midtrans-snap-token-2',
        ], 201),
    ]);

    $merchant = Merchant::factory()->create();
    $plainToken = 'pb_mcht_'.Str::random(40);
    ApiKey::create([
        'merchant_id' => $merchant->id,
        'name' => 'Idempotency Header Test',
        'key_prefix' => ApiKey::maskToken($plainToken),
        'key_hash' => ApiKey::hashToken($plainToken),
        'is_active' => true,
    ]);

    $gateway = PaymentGateway::factory()->create(['code' => 'midtrans']);
    $method = PaymentMethod::factory()->for($gateway, 'gateway')->create();

    $idempotencyKey = 'idem-'.Str::uuid();

    $firstResponse = $this->withToken($plainToken)
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson(route('payments.store'), [
            'merchant_ref_id' => 'INV-HDR-001',
            'payment_method_id' => $method->id,
            'amount' => 75000,
            'redirect_url' => 'https://merchant.example.com/done',
        ]);

    $firstResponse->assertStatus(201);

    $secondResponse = $this->withToken($plainToken)
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson(route('payments.store'), [
            'merchant_ref_id' => 'INV-HDR-002',
            'payment_method_id' => $method->id,
            'amount' => 75000,
            'redirect_url' => 'https://merchant.example.com/done',
        ]);

    $secondResponse->assertStatus(200)
        ->assertJsonPath('idempotent', true)
        ->assertJsonPath('data.reference_id', $firstResponse->json('data.reference_id'));

    Http::assertSentCount(1);
});

test('reusing merchant_ref_id with different amount returns 409 conflict', function (): void {
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'midtrans-snap-token-3',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/midtrans-snap-token-3',
        ], 201),
    ]);

    $merchant = Merchant::factory()->create();
    $plainToken = 'pb_mcht_'.Str::random(40);
    ApiKey::create([
        'merchant_id' => $merchant->id,
        'name' => 'Conflict Key Test',
        'key_prefix' => ApiKey::maskToken($plainToken),
        'key_hash' => ApiKey::hashToken($plainToken),
        'is_active' => true,
    ]);

    $gateway = PaymentGateway::factory()->create(['code' => 'midtrans']);
    $method = PaymentMethod::factory()->for($gateway, 'gateway')->create();

    $this->withToken($plainToken)->postJson(route('payments.store'), [
        'merchant_ref_id' => 'INV-CONFLICT-001',
        'payment_method_id' => $method->id,
        'amount' => 50000,
        'redirect_url' => 'https://merchant.example.com/done',
    ])->assertStatus(201);

    $conflictResponse = $this->withToken($plainToken)->postJson(route('payments.store'), [
        'merchant_ref_id' => 'INV-CONFLICT-001',
        'payment_method_id' => $method->id,
        'amount' => 99000,
        'redirect_url' => 'https://merchant.example.com/done',
    ]);

    $conflictResponse->assertStatus(409)
        ->assertJsonPath('success', false);
});

test('raw qris string immediately exposes Hub dynamic qris_url without external qr api calls', function (): void {
    Http::preventStrayRequests();

    $rawQrisString = '00020101021226610014COM.GO-JEK.WWW01189360091436153605820210G6153605820303UMI5802ID6304A8E2';

    Http::fake([
        'https://app.pakasir.com/api/transactioncreate/qris' => Http::response([
            'payment' => [
                'order_id' => 'pakasir-order-1',
                'payment_number' => $rawQrisString,
            ],
        ], 200),
    ]);

    $merchant = Merchant::factory()->create();
    $plainToken = 'pb_mcht_'.Str::random(40);
    ApiKey::create([
        'merchant_id' => $merchant->id,
        'name' => 'QRIS Local URL Test',
        'key_prefix' => ApiKey::maskToken($plainToken),
        'key_hash' => ApiKey::hashToken($plainToken),
        'is_active' => true,
    ]);

    $gateway = PaymentGateway::factory()->create([
        'code' => 'pakasir',
        'credentials' => [
            'project_slug' => 'demo',
            'api_key' => 'pakasir-secret',
        ],
    ]);
    $method = PaymentMethod::factory()->for($gateway, 'gateway')->qris()->create();

    $response = $this->withToken($plainToken)->postJson(route('payments.store'), [
        'merchant_ref_id' => 'INV-QRIS-LOCAL-1',
        'payment_method_id' => $method->id,
        'amount' => 25000,
        'redirect_url' => 'https://merchant.example.com/done',
    ]);

    $referenceId = $response->json('data.reference_id');

    $response->assertStatus(201)
        ->assertJsonPath('data.payment_code', $rawQrisString)
        ->assertJsonPath('data.qris_url', route('payments.qris', ['reference_id' => $referenceId, 'format' => 'png']));

    // Only the upstream gateway request was made; zero calls to external QR APIs
    Http::assertSentCount(1);
});
