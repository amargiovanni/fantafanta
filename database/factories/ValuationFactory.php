<?php

namespace Database\Factories;

use App\Models\Player;
use App\Models\Valuation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Valuation>
 */
class ValuationFactory extends Factory
{
    protected $model = Valuation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $base = fake()->randomFloat(2, 1, 120);

        return [
            'player_id' => Player::factory(),
            'base_value' => $base,
            'adjusted_value' => $base,
            'max_bid' => (int) ceil($base),
            'tier' => fake()->numberBetween(1, 5),
            'scarcity_index' => 1.0,
            'computed_at' => now(),
        ];
    }
}
