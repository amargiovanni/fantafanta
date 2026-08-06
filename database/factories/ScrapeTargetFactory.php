<?php

namespace Database\Factories;

use App\Models\ScrapeTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScrapeTarget>
 */
class ScrapeTargetFactory extends Factory
{
    protected $model = ScrapeTarget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'url' => fake()->unique()->url(),
            'rss_url' => null,
            'enabled' => true,
            'last_scraped_at' => null,
        ];
    }
}
