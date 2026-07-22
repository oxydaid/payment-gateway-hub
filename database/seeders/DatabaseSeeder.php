<?php

namespace Database\Seeders;

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebhookLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Super Admin
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
            ]
        );

        // 2. Call Global App Settings Seeder
        $this->call(AppSettingSeeder::class);

        // 3. Create Test Merchant
        $merchant = Merchant::updateOrCreate(
            ['name' => 'Demo Store Online'],
            [
                'api_key' => 'pb_mcht_demo1234567890',
                'webhook_url' => 'https://mock.merchant.com/api/callback',
                'is_active' => true,
            ]
        );

        // Link default API key
        ApiKey::updateOrCreate(
            ['key' => 'pb_mcht_demo1234567890'],
            [
                'merchant_id' => $merchant->id,
                'name' => 'Demo Default Key',
                'is_active' => true,
            ]
        );

        // 4. Create Payment Gateways
        $midtrans = PaymentGateway::updateOrCreate(
            ['code' => 'midtrans'],
            [
                'name' => 'Midtrans',
                'is_active' => true,
                'credentials' => [
                    'merchant_id' => 'G100200300',
                    'client_key' => 'SB-Mid-client-demo123',
                    'server_key' => 'SB-Mid-server-demo456',
                    'is_production' => false,
                ],
            ]
        );

        $xendit = PaymentGateway::updateOrCreate(
            ['code' => 'xendit'],
            [
                'name' => 'Xendit',
                'is_active' => true,
                'credentials' => [
                    'secret_key' => 'xnd_development_mockKey123',
                    'callback_token' => 'xendit_callback_token_demo',
                    'is_production' => false,
                ],
            ]
        );

        // 5. Create Payment Methods
        $qris = PaymentMethod::updateOrCreate(
            ['code' => 'qris', 'payment_gateway_id' => $midtrans->id],
            [
                'name' => 'QRIS (Midtrans)',
                'type' => 'qris',
                'fee_type' => 'percent',
                'fee_fix' => 0,
                'fee_percent' => 0.7,
                'is_active' => true,
            ]
        );

        $bcaVa = PaymentMethod::updateOrCreate(
            ['code' => 'bca_va', 'payment_gateway_id' => $midtrans->id],
            [
                'name' => 'BCA Virtual Account',
                'type' => 'va',
                'fee_type' => 'fix',
                'fee_fix' => 4500,
                'fee_percent' => 0,
                'is_active' => true,
            ]
        );

        $mandiriVa = PaymentMethod::updateOrCreate(
            ['code' => 'mandiri_va', 'payment_gateway_id' => $xendit->id],
            [
                'name' => 'Mandiri Virtual Account (Xendit)',
                'type' => 'va',
                'fee_type' => 'mix',
                'fee_fix' => 2000,
                'fee_percent' => 0.7,
                'is_active' => true,
            ]
        );

        // 6. Create Mock Transactions and Webhook Logs
        $statuses = ['PENDING', 'PAID', 'FAILED', 'EXPIRED'];

        for ($i = 1; $i <= 10; $i++) {
            $status = $statuses[array_rand($statuses)];
            $amount = rand(50000, 500000);
            $fee = 4500;
            $totalAmount = $amount + $fee;

            $method = $i % 2 === 0 ? $bcaVa : $mandiriVa;

            $transaction = Transaction::create([
                'reference_id' => (string) Str::uuid(),
                'merchant_id' => $merchant->id,
                'merchant_ref_id' => 'ORDER-2026-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'fee' => $fee,
                'total_amount' => $totalAmount,
                'status' => $status,
                'pg_ref_id' => 'PG-REF-'.Str::upper(Str::random(8)),
                'checkout_url' => 'https://checkout.mock-gateway.com/pay/'.Str::random(10),
                'paid_at' => $status === 'PAID' ? now()->subMinutes(rand(1, 120)) : null,
                'expired_at' => now()->addHours(24),
            ]);

            // Add Webhook Logs for each transaction
            // Incoming webhook from gateway
            WebhookLog::create([
                'transaction_id' => $transaction->id,
                'direction' => 'incoming',
                'payload' => [
                    'transaction_status' => $status === 'PAID' ? 'settlement' : 'pending',
                    'order_id' => $transaction->reference_id,
                    'gross_amount' => $transaction->total_amount,
                ],
                'status_code' => 200,
                'notes' => 'Simulated incoming callback for status '.$status,
            ]);

            // Outgoing webhook to merchant
            WebhookLog::create([
                'transaction_id' => $transaction->id,
                'direction' => 'outgoing',
                'payload' => [
                    'reference_id' => $transaction->reference_id,
                    'merchant_ref_id' => $transaction->merchant_ref_id,
                    'status' => $transaction->status,
                    'amount' => $transaction->amount,
                ],
                'status_code' => $status === 'PENDING' ? null : 200,
                'notes' => $status === 'PENDING'
                    ? 'Webhook callback dispatch pending'
                    : 'Callback successfully delivered to '.$merchant->webhook_url,
            ]);
        }
    }
}
