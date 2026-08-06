<?php

namespace Database\Factories;

use App\Enums\AiRunStatus;
use App\Models\AiRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRun>
 */
class AiRunFactory extends Factory
{
    protected $model = AiRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task' => 'extract-signals',
            'prompt_file' => 'extract-signals.md',
            'prompt_hash' => hash('sha256', fake()->uuid()),
            'status' => AiRunStatus::Succeeded,
            'duration_ms' => fake()->numberBetween(1000, 60000),
            'output_raw' => '{"result":"ok"}',
            'error' => null,
            'context' => ['source_id' => 1],
        ];
    }
}
