<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it dynamically renders a valid PNG QRIS image from stored payment_code', function (): void {
    $merchant = Merchant::factory()->create();
    $gateway = PaymentGateway::factory()->create(['code' => 'pakasir']);
    $method = PaymentMethod::factory()->for($gateway, 'gateway')->qris()->create();

    $transaction = Transaction::factory()
        ->for($merchant)
        ->for($method, 'paymentMethod')
        ->create([
            'reference_id' => 'tx-qris-png-001',
            'payment_code' => '00020101021226610014COM.GO-JEK.WWW01189360091436153605820210G6153605820303UMI5802ID6304A8E2',
        ]);

    $response = $this->get(route('payments.qris', [
        'reference_id' => $transaction->reference_id,
        'format' => 'png',
    ]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Cache-Control'))->toContain('max-age=86400');
    // Verify PNG binary magic header (\x89PNG\r\n\x1a\n)
    expect(substr($response->getContent(), 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});

test('it dynamically renders a valid SVG QRIS image from stored payment_code', function (): void {
    $merchant = Merchant::factory()->create();
    $gateway = PaymentGateway::factory()->create(['code' => 'xendit']);
    $method = PaymentMethod::factory()->for($gateway, 'gateway')->qris()->create();

    $transaction = Transaction::factory()
        ->for($merchant)
        ->for($method, 'paymentMethod')
        ->create([
            'reference_id' => 'tx-qris-svg-001',
            'payment_code' => '00020101021226610014COM.GO-JEK.WWW01189360091436153605820210G6153605820303UMI5802ID6304A8E2',
        ]);

    $response = $this->get(route('payments.qris', [
        'reference_id' => $transaction->reference_id,
        'format' => 'svg',
    ]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->getContent())->toContain('<svg');
});

test('it returns 404 for non-qris transactions, missing transactions, or unsupported formats', function (): void {
    $merchant = Merchant::factory()->create();
    $gateway = PaymentGateway::factory()->create(['code' => 'midtrans']);
    $method = PaymentMethod::factory()->for($gateway, 'gateway')->create();

    $vaTransaction = Transaction::factory()
        ->for($merchant)
        ->for($method, 'paymentMethod')
        ->create([
            'reference_id' => 'tx-va-only-001',
            'payment_code' => '8801081234567890',
        ]);

    $this->get(route('payments.qris', ['reference_id' => $vaTransaction->reference_id, 'format' => 'png']))
        ->assertNotFound();

    $this->get(route('payments.qris', ['reference_id' => 'non-existent-ref', 'format' => 'png']))
        ->assertNotFound();

    $this->get('/payments/qris/tx-va-only-001.jpg')
        ->assertNotFound();
});
