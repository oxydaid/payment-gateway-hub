<?php

namespace Database\Factories;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'webhook_url' => fake()->url(),
            'api_key' => 'pb_mcht_'.fake()->regexify('[A-Za-z0-9]{32}'),
            'is_active' => true,
        ];
    }
}
