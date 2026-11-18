<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = 100000;
        $fee = 4500;

        return [
            'reference_id' => (string) Str::uuid(),
            'merchant_id' => Merchant::factory(),
            'merchant_ref_id' => 'INV-'.strtoupper(Str::random(8)),
            'payment_method_id' => PaymentMethod::factory(),
            'amount' => $amount,
            'fee' => $fee,
            'total_amount' => $amount + $fee,
            'status' => 'PENDING',
            'pg_status' => 'PENDING',
            'expired_at' => now()->addHours(24),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => 'PAID',
            'pg_status' => 'settlement',
            'paid_at' => now(),
        ]);
    }
}
