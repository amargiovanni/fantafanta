<?php

namespace Database\Factories;

use App\Enums\PlayerRole;
use App\Enums\SlotStatus;
use App\Models\Plan;
use App\Models\PlanSlot;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanSlot>
 */
class PlanSlotFactory extends Factory
{
    protected $model = PlanSlot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'role' => PlayerRole::Attaccante,
            'slot_index' => 1,
            'player_id' => Player::factory(),
            'target_price' => 30,
            'max_price' => 40,
            'alternatives' => [],
            'slot_status' => SlotStatus::Pending,
        ];
    }
}
