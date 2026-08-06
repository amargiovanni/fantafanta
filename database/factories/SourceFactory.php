<?php

namespace Database\Factories;

use App\Enums\SourceOrigin;
use App\Enums\SourceStatus;
use App\Enums\SourceType;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    protected $model = Source::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = fake()->paragraphs(3, true);

        return [
            'type' => SourceType::Note,
            'title' => fake()->sentence(),
            'url' => null,
            'raw_content' => $content,
            'content_hash' => Source::hashContent($content.fake()->uuid()),
            'origin' => SourceOrigin::Manual,
            'status' => SourceStatus::Queued,
            'processed_at' => null,
            'error' => null,
        ];
    }

    public function processed(): self
    {
        return $this->state(fn () => [
            'status' => SourceStatus::Processed,
            'processed_at' => now(),
        ]);
    }
}
