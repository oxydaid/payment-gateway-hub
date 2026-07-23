<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Inertia\Inertia;
use Inertia\Response;

class PaymentCheckoutController extends Controller
{
    public function show(string $referenceId): Response
    {
        $transaction = Transaction::where('reference_id', $referenceId)
            ->with(['paymentMethod.gateway', 'merchant'])
            ->firstOrFail();

        // Auto-resolve icons if missing
        $gatewayIcon = null;
        if ($transaction->paymentMethod && $transaction->paymentMethod->gateway) {
            $gateway = $transaction->paymentMethod->gateway;
            $gatewayIcon = $gateway->icon_url ?: $this->getGatewayIcon($gateway->name);
        }

        $methodIcon = null;
        if ($transaction->paymentMethod) {
            $method = $transaction->paymentMethod;
            $methodIcon = $method->icon ? asset('storage/'.$method->icon) : $this->getMethodIcon($method->name);
        }

        return Inertia::render('Payments/Checkout', [
            'transaction' => [
                'reference_id' => $transaction->reference_id,
                'merchant_name' => $transaction->merchant->name,
                'merchant_ref_id' => $transaction->merchant_ref_id,
                'amount' => (float) $transaction->amount,
                'fee' => (float) $transaction->fee,
                'total_amount' => (float) $transaction->total_amount,
                'status' => $transaction->status,
                'checkout_url' => $transaction->checkout_url,
                'qris_url' => $transaction->qris_url,
                'payment_code' => $transaction->payment_code,
                'redirect_url' => $transaction->redirect_url,
                'expired_at' => $transaction->expired_at?->toIso8601String(),
                'payment_method' => $transaction->paymentMethod ? [
                    'name' => $transaction->paymentMethod->name,
                    'type' => $transaction->paymentMethod->type,
                    'gateway_name' => $transaction->paymentMethod->gateway->name,
                    'gateway_icon' => $gatewayIcon,
                    'icon' => $methodIcon,
                ] : null,
            ],
        ]);
    }

    protected function getGatewayIcon(string $name): ?string
    {
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($name));
        $extensions = ['svg', 'png', 'jpg', 'jpeg', 'webp'];
        foreach ($extensions as $ext) {
            $path = "images/payment-gateway/{$name}.{$ext}";
            if (file_exists(public_path($path))) {
                return asset($path);
            }
        }

        return null;
    }

    protected function getMethodIcon(string $name): ?string
    {
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($name));

        if (str_ends_with($name, '_virtual_account')) {
            $name = str_replace('_virtual_account', '_va', $name);
        }
        if (str_ends_with($name, 'virtualaccount')) {
            $name = str_replace('virtualaccount', '_va', $name);
        }
        if (str_starts_with($name, 'qris')) {
            $name = 'qris';
        }

        $extensions = ['svg', 'png', 'jpg', 'jpeg', 'webp'];
        foreach ($extensions as $ext) {
            $path = "images/payment-method/{$name}.{$ext}";
            if (file_exists(public_path($path))) {
                return asset($path);
            }
        }

        return null;
    }
}
