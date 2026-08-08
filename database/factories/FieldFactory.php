<?php

namespace Database\Factories;

use App\Models\Field;
use App\Models\Form;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Field>
 */
class FieldFactory extends Factory
{
    protected $model = Field::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = Str::snake(fake()->unique()->words(2, true));

        return [
            'form_id' => Form::factory(),
            'section_id' => Section::factory(),
            'key' => $key,
            'label' => Str::title(str_replace('_', ' ', $key)),
            'type' => 'text',
            'sort_order' => fake()->numberBetween(0, 10),
            'config' => [],
            'validation' => [],
            'is_required' => false,
        ];
    }
}
