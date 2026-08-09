<?php

namespace Tests\Feature\Builder;

use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FormBuilderDuplicateRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_field_does_not_produce_not_found_response(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $field = $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'full_name',
            'label' => 'Full Name',
            'type' => 'text',
            'sort_order' => 0,
            'is_required' => true,
        ]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('duplicateField', $field->id)
            ->assertHasNoErrors()
            ->assertStatus(200)
            ->assertSee('Full Name')
            ->assertSee('Full Name (Copy)')
            ->assertSee('full_name_copy');
    }

    public function test_duplicate_field_selects_new_field_in_editor(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $field = $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'email',
            'label' => 'Email',
            'type' => 'email',
            'sort_order' => 0,
        ]);

        $component = Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('duplicateField', $field->id);

        $duplicate = $form->fields()->where('key', 'email_copy')->first();

        $this->assertNotNull($duplicate);
        $component
            ->assertSet('selectedFieldId', $duplicate->id)
            ->assertSet('fieldEditor.key', 'email_copy')
            ->assertSet('fieldEditor.label', 'Email (Copy)');
    }

    public function test_duplicate_preserves_original_field_and_section(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $field = $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'phone',
            'label' => 'Phone',
            'type' => 'text',
            'sort_order' => 0,
            'config' => ['placeholder' => '555-0100'],
            'is_required' => false,
        ]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('duplicateField', $field->id);

        $field->refresh();
        $duplicate = $form->fields()->where('key', 'phone_copy')->first();

        $this->assertSame('phone', $field->key);
        $this->assertSame('Phone', $field->label);
        $this->assertSame($section->id, $duplicate->section_id);
        $this->assertSame('text', $duplicate->type);
        $this->assertSame(['placeholder' => '555-0100'], $duplicate->config);
    }

    public function test_duplicate_inserts_field_after_original_in_sort_order(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $first = $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'first',
            'label' => 'First',
            'type' => 'text',
            'sort_order' => 0,
        ]);
        $second = $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'second',
            'label' => 'Second',
            'type' => 'text',
            'sort_order' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('duplicateField', $second->id);

        $orderedKeys = $section->fields()->orderBy('sort_order')->pluck('key')->all();

        $this->assertSame(['first', 'second', 'second_copy'], $orderedKeys);
        $this->assertSame(3, $section->fields()->count());
    }

    public function test_non_owner_cannot_open_builder_to_duplicate_field(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $field = $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'secret',
            'label' => 'Secret',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $this->actingAs($otherUser)
            ->get(route('forms.builder', $form))
            ->assertForbidden();

        Livewire::actingAs($otherUser)
            ->test(FormBuilder::class, ['form' => $form])
            ->assertForbidden();
    }

    public function test_add_field_also_selects_new_field_without_not_found(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('addField', $section->id)
            ->assertHasNoErrors()
            ->assertStatus(200)
            ->assertSee('New Field');
    }
}
