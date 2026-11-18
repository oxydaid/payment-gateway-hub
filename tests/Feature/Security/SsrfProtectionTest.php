<?php

declare(strict_types=1);

use App\Jobs\SendMerchantCallbackJob;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Security\WebhookUrlValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('merchant creation rejects loopback and private ip webhook urls', function (string $unsafeUrl): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/merchants', [
        'name' => 'SSRF Merchant',
        'webhook_url' => $unsafeUrl,
        'is_active' => true,
    ]);

    $response->assertSessionHasErrors('webhook_url');
})->with([
    'loopback ipv4' => ['http://127.0.0.1/webhook'],
    'localhost' => ['http://localhost:8080/webhook'],
    'private 10.x' => ['http://10.0.0.5/internal'],
    'private 172.16.x' => ['http://172.16.0.1/internal'],
    'private 192.168.x' => ['http://192.168.1.10/hook'],
    'aws metadata link-local' => ['http://169.254.169.254/latest/meta-data/'],
    'ipv6 loopback' => ['http://[::1]/webhook'],
    'internal suffix' => ['https://billing.internal/webhook'],
]);

test('testWebhook endpoint blocks private ip targets without sending outbound http', function (): void {
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $merchant = Merchant::factory()->create([
        'webhook_url' => 'http://127.0.0.1/secret-admin',
    ]);

    $response = $this->actingAs($user)->postJson(route('merchants.test-webhook', $merchant));

    $response->assertStatus(400)
        ->assertJsonPath('success', false);

    Http::assertNothingSent();
});

test('SendMerchantCallbackJob blocks private ip webhook urls and logs ssrf block', function (): void {
    Http::preventStrayRequests();

    $merchant = Merchant::factory()->create([
        'webhook_url' => 'http://169.254.169.254/latest/meta-data/',
    ]);
    $gateway = PaymentGateway::factory()->create(['code' => 'midtrans']);
    $method = PaymentMethod::factory()->for($gateway, 'gateway')->create();
    $transaction = Transaction::factory()
        ->for($merchant)
        ->for($method, 'paymentMethod')
        ->paid()
        ->create();

    $job = new SendMerchantCallbackJob($transaction);
    $job->handle(app(WebhookUrlValidator::class));

    Http::assertNothingSent();
    $this->assertDatabaseHas('webhook_logs', [
        'transaction_id' => $transaction->id,
        'direction' => 'outgoing',
        'status_code' => 400,
    ]);
});

test('merchant webhook_secret is stored encrypted and hidden from serialization', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/merchants', [
        'name' => 'Encrypted Secret Merchant',
        'webhook_url' => 'https://example.com/webhook',
        'is_active' => true,
    ])->assertRedirect();

    $merchant = Merchant::where('name', 'Encrypted Secret Merchant')->firstOrFail();

    expect($merchant->webhook_secret)->toStartWith('whsec_');
    expect($merchant->signingSecret())->toBe($merchant->webhook_secret);
    expect(array_key_exists('webhook_secret', $merchant->toArray()))->toBeFalse();

    // Raw database value must be encrypted, not plaintext
    $rawSecret = DB::table('merchants')
        ->where('id', $merchant->id)
        ->value('webhook_secret');

    expect($rawSecret)->not->toBe($merchant->webhook_secret);
});
