<?php

namespace Database\Factories;

use App\Models\Ticker;
use Illuminate\Database\Eloquent\Factories\Factory;

class TickerPriceHistoryFactory extends Factory
{
    public function definition(): array
    {
        // Get a datetime, but force conversion if it's an integer
        $raw = $this->faker->dateTimeBetween('-2 years', 'now');

        // Handle both integer and object
        if (is_int($raw)) {
            $dt = (new \DateTime())->setTimestamp($raw);
        } elseif ($raw instanceof \DateTimeInterface) {
            $dt = $raw;
        } else {
            $dt = new \DateTime($raw);
        }

        // Always MySQL-compatible format
        $formatted = $dt->format('Y-m-d H:i:s');

        return [
            'ticker_id' => Ticker::factory(),
            'o' => $this->faker->randomFloat(2, 50, 500),
            'h' => $this->faker->randomFloat(2, 50, 500),
            'l' => $this->faker->randomFloat(2, 50, 500),
            'c' => $this->faker->randomFloat(2, 50, 500),
            'v' => $this->faker->numberBetween(100000, 50000000),
            'vw' => $this->faker->randomFloat(2, 50, 500),
            't' => $formatted,
            'year' => (int) $dt->format('Y'),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}