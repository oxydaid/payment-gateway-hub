<?php

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('super admin can create a merchant', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/merchants', [
        'name' => 'Test Store Online',
        'webhook_url' => 'https://teststore.com/webhook',
        'is_active' => true,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('merchants', [
        'name' => 'Test Store Online',
    ]);
});

test('super admin can generate global and merchant api keys', function () {
    $user = User::factory()->create();
    $merchant = Merchant::factory()->create(['is_active' => true]);

    $globalResponse = $this->actingAs($user)->post('/api-keys', [
        'name' => 'Production Global Key',
        'merchant_id' => null,
    ]);
    $globalResponse->assertRedirect();

    $merchantResponse = $this->actingAs($user)->post('/api-keys', [
        'name' => 'Merchant Specific Key',
        'merchant_id' => $merchant->id,
    ]);
    $merchantResponse->assertRedirect();

    $this->assertDatabaseHas('api_keys', ['name' => 'Production Global Key']);
    $this->assertDatabaseHas('api_keys', ['name' => 'Merchant Specific Key']);
});

test('api middleware validates merchant key from api_keys table', function () {
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'mock-token',
            'redirect_url' => 'https://mock-checkout-url.com',
        ], 201),
    ]);
    $merchant = Merchant::factory()->create(['is_active' => true]);
    $apiKey = ApiKey::create([
        'merchant_id' => $merchant->id,
        'name' => 'Integration Key',
        'key' => 'pb_mcht_integration1234567890',
        'is_active' => true,
    ]);

    $gateway = PaymentGateway::factory()->create(['code' => 'midtrans', 'is_active' => true]);
    $method = $gateway->paymentMethods()->create([
        'name' => 'QRIS',
        'code' => 'qris',
        'type' => 'qris',
        'fee_type' => 'percent',
        'fee_percent' => 0.7,
        'fee_fix' => 0,
        'is_active' => true,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer pb_mcht_integration1234567890')
        ->postJson('/api/v1/payments', [
            'merchant_ref_id' => 'INV-TEST-KEY-1',
            'payment_method_id' => $method->id,
            'amount' => 50000,
        ]);

    $response->assertStatus(201);
});

test('api middleware validates global key with merchant_id payload', function () {
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'mock-token',
            'redirect_url' => 'https://mock-checkout-url.com',
        ], 201),
    ]);
    $merchant = Merchant::factory()->create(['is_active' => true]);
    $apiKey = ApiKey::create([
        'merchant_id' => null, // Global Key
        'name' => 'Master Global Key',
        'key' => 'pb_live_globalmaster987654321',
        'is_active' => true,
    ]);

    $gateway = PaymentGateway::factory()->create(['code' => 'midtrans', 'is_active' => true]);
    $method = $gateway->paymentMethods()->create([
        'name' => 'QRIS',
        'code' => 'qris',
        'type' => 'qris',
        'fee_type' => 'percent',
        'fee_percent' => 0.7,
        'fee_fix' => 0,
        'is_active' => true,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer pb_live_globalmaster987654321')
        ->postJson('/api/v1/payments', [
            'merchant_id' => $merchant->id,
            'merchant_ref_id' => 'INV-TEST-GLOBAL-1',
            'payment_method_id' => $method->id,
            'amount' => 75000,
        ]);

    $response->assertStatus(201);
});

test('super admin can update api key status and name', function () {
    $user = User::factory()->create();
    $apiKey = ApiKey::create([
        'name' => 'Test Key',
        'key' => 'pb_live_test123',
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)->put("/api-keys/{$apiKey->id}", [
        'name' => 'Updated Key Name',
        'is_active' => false,
    ]);
    $response->assertRedirect();

    $apiKey->refresh();
    expect($apiKey->name)->toBe('Updated Key Name');
    expect($apiKey->is_active)->toBeFalse();
});
