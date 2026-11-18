<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_gateway_id' => PaymentGateway::factory(),
            'name' => 'BCA Virtual Account',
            'code' => 'bca_va',
            'type' => 'va',
            'fee_type' => 'fix',
            'fee_fix' => 4500,
            'fee_percent' => 0,
            'is_active' => true,
        ];
    }

    public function qris(): static
    {
        return $this->state(fn (): array => [
            'name' => 'QRIS',
            'code' => 'qris',
            'type' => 'qris',
            'fee_type' => 'percent',
            'fee_fix' => 0,
            'fee_percent' => 0.7,
        ]);
    }
}
