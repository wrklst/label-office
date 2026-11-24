<?php

namespace Database\Factories;

use App\Models\Agreement;
use App\Models\AgreementTemplate;
use App\Models\Label;
use App\Models\Payee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agreement>
 */
class AgreementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label_id' => Label::factory(),
            'agreement_template_id' => fn (array $attributes) => AgreementTemplate::factory()->create(['label_id' => $attributes['label_id']])->id,
            'payee_id' => fn (array $attributes) => Payee::factory()->create(['label_id' => $attributes['label_id']])->id,
            'name' => fake()->unique()->words(3, true),
            'licensor' => 'label',
            'terms' => ['work_title' => 'Example Song (Example Remix)', 'share' => '50'],
        ];
    }
}
