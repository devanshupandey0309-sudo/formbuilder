<?php

namespace Tests\Feature\Builder;

use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FormBuilderValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_field_editor_includes_email_validation_state(): void
    {
        [$user, $form, $field] = $this->createField('email', 'email', ['format' => 'email']);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('selectField', $field->id)
            ->assertSet('fieldEditor.validation_format_enabled', true);
    }

    public function test_date_field_editor_includes_date_validation_state(): void
    {
        [$user, $form, $field] = $this->createField('start_date', 'date', [
            'format' => 'Y-m-d',
            'min' => '2020-01-01',
            'max' => '2030-12-31',
        ]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('selectField', $field->id)
            ->assertSet('fieldEditor.validation_format_enabled', true)
            ->assertSet('fieldEditor.validation_min', '2020-01-01')
            ->assertSet('fieldEditor.validation_max', '2030-12-31');
    }

    public function test_number_field_editor_includes_numeric_validation_state(): void
    {
        [$user, $form, $field] = $this->createField('age', 'number', ['min' => 18, 'max' => 100]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('selectField', $field->id)
            ->assertSet('fieldEditor.validation_min', 18)
            ->assertSet('fieldEditor.validation_max', 100);
    }

    public function test_text_field_editor_includes_length_validation_state(): void
    {
        [$user, $form, $field] = $this->createField('bio', 'textarea', [
            'min_length' => 10,
            'max_length' => 500,
        ]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('selectField', $field->id)
            ->assertSet('fieldEditor.validation_min_length', 10)
            ->assertSet('fieldEditor.validation_max_length', 500);
    }

    public function test_changing_field_type_clears_incompatible_validation(): void
    {
        [$user, $form, $field] = $this->createField('age', 'number', ['min' => 18, 'max' => 100]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('selectField', $field->id)
            ->set('fieldEditor.type', 'email')
            ->assertSet('fieldEditor.validation_format_enabled', true)
            ->assertSet('fieldEditor.validation_min', '')
            ->assertSet('fieldEditor.validation_max', '');
    }

    public function test_saving_email_field_persists_validation_metadata(): void
    {
        [$user, $form, $field] = $this->createField('email', 'email');

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('selectField', $field->id)
            ->set('fieldEditor.validation_format_enabled', true)
            ->set('fieldEditor.validation_min_length', 5)
            ->set('fieldEditor.validation_max_length', 120)
            ->call('saveSelectedField')
            ->assertHasNoErrors();

        $field->refresh();

        $this->assertSame([
            'format' => 'email',
            'min_length' => 5,
            'max_length' => 120,
        ], $field->validation);
    }

    public function test_reloading_field_preserves_validation_metadata(): void
    {
        [$user, $form, $field] = $this->createField('score', 'number', ['min' => 0, 'max' => 10]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('selectField', $field->id)
            ->assertSet('fieldEditor.validation_min', 0)
            ->assertSet('fieldEditor.validation_max', 10);
    }

    /**
     * @param  array<string, mixed>|null  $validation
     * @return array{0: \App\Models\User, 1: Form, 2: \App\Models\Field}
     */
    private function createField(string $key, string $type, ?array $validation = null): array
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $field = $form->fields()->create([
            'section_id' => $section->id,
            'key' => $key,
            'label' => ucfirst(str_replace('_', ' ', $key)),
            'type' => $type,
            'sort_order' => 0,
            'validation' => $validation,
        ]);

        return [$user, $form, $field];
    }
}
