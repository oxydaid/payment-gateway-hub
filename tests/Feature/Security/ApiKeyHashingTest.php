<?php

declare(strict_types=1);

use App\Jobs\SendMerchantCallbackJob;
use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('api key is stored as sha256 hash instead of plaintext', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/api-keys', [
        'name' => 'Hashed Production Key',
        'merchant_id' => null,
    ]);

    $response->assertRedirect();

    $apiKey = ApiKey::latest()->firstOrFail();

    expect(Schema::hasColumn('api_keys', 'key'))->toBeFalse();
    expect($apiKey->key_hash)->not->toBeNull();
    expect(strlen($apiKey->key_hash))->toBe(64);
    expect($apiKey->key_prefix)->toStartWith('pb_live_');
    expect($apiKey->key_prefix)->toEndWith('****');
    expect(array_key_exists('key_hash', $apiKey->toArray()))->toBeFalse();
});

test('api keys index only exposes masked prefix and never exposes hash', function (): void {
    $user = User::factory()->create();
    $plainToken = 'pb_live_'.Str::random(40);

    ApiKey::create([
        'name' => 'Masked Key',
        'key_prefix' => ApiKey::maskToken($plainToken),
        'key_hash' => ApiKey::hashToken($plainToken),
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)->get('/api-keys');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ApiKeys/Index')
            ->has('api_keys', 1)
            ->where('api_keys.0.key_prefix', ApiKey::maskToken($plainToken))
            ->missing('api_keys.0.key')
            ->missing('api_keys.0.key_hash')
        );
});

test('authentication succeeds with hashed api key lookup and rejects inactive keys', function (): void {
    $plainToken = 'pb_live_'.Str::random(40);

    $apiKey = ApiKey::create([
        'name' => 'Active Hashed Key',
        'key_prefix' => ApiKey::maskToken($plainToken),
        'key_hash' => ApiKey::hashToken($plainToken),
        'is_active' => true,
    ]);

    $this->withToken($plainToken)
        ->getJson(route('payments.methods'))
        ->assertOk();

    $apiKey->refresh();
    expect($apiKey->last_used_at)->not->toBeNull();

    $apiKey->update(['is_active' => false]);

    $this->withToken($plainToken)
        ->getJson(route('payments.methods'))
        ->assertStatus(401);
});

test('dashboard returns cached metrics and deferred trends and recent transactions', function (): void {
    $user = User::factory()->create();
    $merchant = Merchant::factory()->create(['is_active' => true]);
    $gateway = PaymentGateway::factory()->create(['code' => 'midtrans', 'is_active' => true]);
    $method = PaymentMethod::create([
        'payment_gateway_id' => $gateway->id,
        'name' => 'QRIS',
        'code' => 'qris',
        'type' => 'qris',
        'fee_type' => 'fix',
        'fee_fix' => 1000,
        'fee_percent' => 0,
        'is_active' => true,
    ]);

    Transaction::create([
        'reference_id' => (string) Str::uuid(),
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-DASH-1',
        'payment_method_id' => $method->id,
        'amount' => 50000,
        'fee' => 1000,
        'total_amount' => 51000,
        'status' => 'PAID',
        'paid_at' => now(),
        'expired_at' => now()->addHours(24),
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('metrics.total_volume', 51000)
            ->where('metrics.total_fees', 1000)
            ->where('metrics.successful_count', 1)
            ->where('metrics.active_gateways', 1)
            ->missing('recent_transactions')
            ->missing('volume_trends')
            ->missing('chart_data')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('recent_transactions', 1)
                ->has('volume_trends', 30)
            )
        );
});

test('merchant callback dispatches asynchronously to queue by default', function (): void {
    Queue::fake();

    config()->set('queue.merchant_callback_sync', false);

    $merchant = Merchant::factory()->create(['is_active' => true]);
    $gateway = PaymentGateway::factory()->create(['code' => 'midtrans', 'is_active' => true]);
    $method = PaymentMethod::create([
        'payment_gateway_id' => $gateway->id,
        'name' => 'BCA VA',
        'code' => 'bca_va',
        'type' => 'va',
        'fee_type' => 'fix',
        'fee_fix' => 4500,
        'fee_percent' => 0,
        'is_active' => true,
    ]);

    $transaction = Transaction::create([
        'reference_id' => (string) Str::uuid(),
        'merchant_id' => $merchant->id,
        'merchant_ref_id' => 'INV-ASYNC-1',
        'payment_method_id' => $method->id,
        'amount' => 100000,
        'fee' => 4500,
        'total_amount' => 104500,
        'status' => 'PAID',
        'paid_at' => now(),
        'expired_at' => now()->addHours(24),
    ]);

    SendMerchantCallbackJob::dispatchMerchantCallback($transaction);

    Queue::assertPushed(SendMerchantCallbackJob::class, function (SendMerchantCallbackJob $job) use ($transaction): bool {
        return $job->transaction->id === $transaction->id
            && $job->tries === 5
            && $job->backoff === [10, 60, 300, 1800, 3600];
    });
});
