<?php

namespace Database\Factories;

use App\Models\Taxonomy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Taxonomy>
 */
class TaxonomyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'type' => fake()->randomElement(['genre', 'country', 'actor']),
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}
