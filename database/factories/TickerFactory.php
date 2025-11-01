<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TickerFactory extends Factory
{
    public function definition(): array
    {
        $symbol = strtoupper($this->faker->unique()->bothify('??##'));

        return [
            'ticker' => $symbol,
            'name' => $this->faker->company(),
            'slug' => Str::slug($symbol),
            'primary_exchange' => $this->faker->randomElement(['NASDAQ', 'NYSE', 'AMEX']),
            'currency_symbol' => 'USD',
            'active' => true,
        ];
    }
}