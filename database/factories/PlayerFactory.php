<?php

namespace Database\Factories;

use App\Enums\PlayerRole;
use App\Enums\PlayerStatus;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    protected $model = Player::class;

    /**
     * Progressivo che rende il nome unico per costruzione.
     *
     * `fake()->unique()` non basta: il vocabolario dei cognomi si esaurisce
     * intorno al mezzo migliaio, e un listone di Serie A ne ha 600. Il nome
     * resta comunque un nome, con un ordinale in coda.
     */
    private static int $ordinal = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->lastName().' '.fake()->firstName().' '.++self::$ordinal,
            'role' => fake()->randomElement(PlayerRole::cases()),
            'real_team' => fake()->city(),
            'quotazione' => fake()->numberBetween(1, 60),
            'fvm' => fake()->numberBetween(1, 300),
            'season_stats' => null,
            'status' => PlayerStatus::Available,
            'is_rigorista' => false,
            'expected_starter' => fake()->randomFloat(2, 0, 1),
        ];
    }
}
