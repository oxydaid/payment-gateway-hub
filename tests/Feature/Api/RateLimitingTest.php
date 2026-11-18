<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('webhook endpoints are rate limited at 120 requests per minute', function (): void {
    $response = null;

    for ($i = 0; $i < 121; $i++) {
        $response = $this->postJson(route('webhooks.midtrans'), []);
    }

    $response->assertStatus(429);
});

test('merchant api endpoints are rate limited at 60 requests per minute', function (): void {
    $merchant = Merchant::factory()->create(['is_active' => true]);
    $plainToken = 'pb_mcht_'.Str::random(40);

    ApiKey::create([
        'merchant_id' => $merchant->id,
        'name' => 'Rate Limit Test Key',
        'key_prefix' => ApiKey::maskToken($plainToken),
        'key_hash' => ApiKey::hashToken($plainToken),
        'is_active' => true,
    ]);

    $response = null;

    for ($i = 0; $i < 61; $i++) {
        $response = $this->withToken($plainToken)->getJson(route('payments.methods'));
    }

    $response->assertStatus(429);
});

test('invalid api key returns 401 unauthorized', function (): void {
    $response = $this->withToken('invalid-token-value')
        ->postJson(route('payments.store'), []);

    $response->assertStatus(401)
        ->assertJsonPath('success', false);
});
