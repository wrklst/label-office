<?php

namespace Database\Factories;

use App\Models\AgreementTemplate;
use App\Models\Label;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgreementTemplate>
 */
class AgreementTemplateFactory extends Factory
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
            'name' => fake()->unique()->words(2, true),
            'body' => 'Agreement between {{ label.legal_name }} and {{ payee.legal_name }} on {{ share }} % of "{{ work.title }}".',
        ];
    }
}
