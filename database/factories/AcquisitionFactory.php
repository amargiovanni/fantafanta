<?php

namespace Database\Factories;

use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Acquisition>
 */
class AcquisitionFactory extends Factory
{
    protected $model = Acquisition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'auction_id' => Auction::factory(),
            'player_id' => Player::factory(),
            'team_id' => Team::factory(),
            'price' => fake()->numberBetween(1, 80),

            // Null di proposito: lo scatto lo mette l'observer leggendo la
            // valutazione corrente, ed è quello il comportamento da testare.
            'valuation_at_purchase' => null,
        ];
    }
}
