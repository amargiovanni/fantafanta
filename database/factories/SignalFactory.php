<?php

namespace Database\Factories;

use App\Enums\SignalType;
use App\Models\Player;
use App\Models\Signal;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Signal>
 */
class SignalFactory extends Factory
{
    protected $model = Signal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'player_id' => Player::factory(),
            'type' => SignalType::Infortunio,
            'payload' => ['nota' => fake()->sentence()],
            'confidence' => 0.8,
            'impact' => -2,
            'source_id' => Source::factory(),
            'event_date' => now()->toDateString(),
            'superseded_by' => null,
            'needs_review' => false,
            'raw_name' => null,
        ];
    }

    public function needsReview(string $rawName = 'Nome Ignoto'): self
    {
        return $this->state(fn () => [
            'player_id' => null,
            'needs_review' => true,
            'raw_name' => $rawName,
        ]);
    }
}
