<?php

namespace Database\Factories;

use App\Models\Source;
use App\Models\SourcePage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourcePage>
 */
class SourcePageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = fake()->unique()->url();

        return [
            'source_id' => Source::factory(),
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'http_status' => 200,
            'content_hash' => hash('sha256', fake()->sentence()),
            'parse_status' => 'pending',
        ];
    }
}
