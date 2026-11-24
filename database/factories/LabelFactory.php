<?php

namespace Database\Factories;

use App\Models\Label;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Label>
 */
class LabelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'slug' => fake()->unique()->slug(2),
            'currency' => 'EUR',
            // Most tests are about a label that is in use; the ones about accepting the terms unset this.
            'terms_accepted' => ['version' => Label::TERMS_VERSION, 'user_id' => 0, 'name' => 'Example Admin', 'email' => 'admin@example.com', 'at' => '2026-11-24T00:00:00+00:00'],
        ];
    }
}
