<?php

namespace Database\Factories;

use App\Enums\PlanStatus;
use App\Enums\PlanTrigger;
use App\Models\Auction;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'auction_id' => Auction::factory(),
            'version' => 1,
            'trigger' => PlanTrigger::Initial,
            'status' => PlanStatus::Ready,
            'strategy_notes' => 'Difesa concentrata su due squadre, un top in attacco.',
            'budget_summary' => null,
        ];
    }

    public function generating(): self
    {
        return $this->state(fn () => ['status' => PlanStatus::Generating]);
    }

    public function failed(): self
    {
        return $this->state(fn () => ['status' => PlanStatus::Failed]);
    }
}
