<?php

namespace Database\Factories;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Auction>
 */
class AuctionFactory extends Factory
{
    protected $model = Auction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Asta '.fake()->year(),
            'status' => AuctionStatus::Setup,
            'started_at' => null,
        ];
    }

    public function live(): self
    {
        return $this->state(fn () => [
            'status' => AuctionStatus::Live,
            'started_at' => now(),
        ]);
    }

    public function closed(): self
    {
        return $this->state(fn () => [
            'status' => AuctionStatus::Closed,
            'started_at' => now()->subDay(),
        ]);
    }
}
