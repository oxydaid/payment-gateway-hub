<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Dashboard\ApiKeyController;
use App\Http\Controllers\Dashboard\AppSettingController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\MerchantController;
use App\Http\Controllers\Dashboard\PaymentGatewayController;
use App\Http\Controllers\Dashboard\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Transactions
    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    Route::post('/transactions/{transaction}/resend-webhook', [TransactionController::class, 'resendWebhook'])->name('transactions.resend-webhook');

    // Merchants CRUD
    Route::get('/merchants', [MerchantController::class, 'index'])->name('merchants.index');
    Route::post('/merchants', [MerchantController::class, 'store'])->name('merchants.store');
    Route::put('/merchants/{merchant}', [MerchantController::class, 'update'])->name('merchants.update');
    Route::delete('/merchants/{merchant}', [MerchantController::class, 'destroy'])->name('merchants.destroy');
    Route::post('/merchants/{merchant}/generate-key', [MerchantController::class, 'generateKey'])->name('merchants.generate-key');
    Route::post('/merchants/{merchant}/test-webhook', [MerchantController::class, 'testWebhook'])->name('merchants.test-webhook');

    // API Keys CRUD
    Route::get('/api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::put('/api-keys/{apiKey}', [ApiKeyController::class, 'update'])->name('api-keys.update');
    Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

    // Payment Gateways & Methods CRUD
    Route::get('/payment-gateways', [PaymentGatewayController::class, 'index'])->name('payment-gateways.index');
    Route::post('/payment-gateways', [PaymentGatewayController::class, 'store'])->name('payment-gateways.store');
    Route::post('/payment-gateways/{gateway}/update', [PaymentGatewayController::class, 'update'])->name('payment-gateways.update');
    Route::delete('/payment-gateways/{gateway}', [PaymentGatewayController::class, 'destroy'])->name('payment-gateways.destroy');

    Route::post('/payment-gateways/{gateway}/methods', [PaymentGatewayController::class, 'storeMethod'])->name('payment-gateways.methods.store');
    Route::post('/payment-gateways/methods/{method}/update', [PaymentGatewayController::class, 'updateMethod'])->name('payment-gateways.methods.update');
    Route::delete('/payment-gateways/methods/{method}', [PaymentGatewayController::class, 'destroyMethod'])->name('payment-gateways.methods.destroy');

    // App Settings
    Route::get('/app-settings', [AppSettingController::class, 'index'])->name('app-settings.index');
    Route::post('/app-settings', [AppSettingController::class, 'update'])->name('app-settings.update');

    // Webhook Docs
    Route::get('/docs/webhook', function () {
        return inertia('Docs/Webhook');
    })->name('docs.webhook');
});
