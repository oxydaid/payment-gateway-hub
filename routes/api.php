<?php

use App\Http\Controllers\Api\v1\PaymentController;
use App\Http\Controllers\Api\v1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Public webhook endpoints for payment gateways
    Route::post('/webhooks/midtrans', [WebhookController::class, 'midtrans'])->name('webhooks.midtrans');
    Route::post('/webhooks/tripay', [WebhookController::class, 'tripay'])->name('webhooks.tripay');
    Route::post('/webhooks/tokopay', [WebhookController::class, 'tokopay'])->name('webhooks.tokopay');
    Route::post('/webhooks/xendit', [WebhookController::class, 'xendit'])->name('webhooks.xendit');
    Route::post('/webhooks/pakasir', [WebhookController::class, 'pakasir'])->name('webhooks.pakasir');

    // Merchant API endpoints protected by api_key token validation middleware
    Route::middleware('merchant.auth')->group(function (): void {
        Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::get('/payment-methods', [PaymentController::class, 'methods'])->name('payments.methods');
        Route::get('/transactions', [PaymentController::class, 'transactions'])->name('payments.transactions');
        Route::get('/transactions/{reference_id}', [PaymentController::class, 'show'])->name('payments.show');
        Route::get('/webhook-logs', [PaymentController::class, 'webhookLogs'])->name('payments.webhook-logs');
    });
});
