<?php

namespace Tests\Feature\Builder;

use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FormBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_builder(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('forms.builder', $form))
            ->assertOk()
            ->assertSeeLivewire(FormBuilder::class);
    }

    public function test_non_owner_cannot_edit_builder(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();

        $this->actingAs($otherUser)
            ->get(route('forms.builder', $form))
            ->assertForbidden();
    }

    public function test_section_can_be_created(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('addSection')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sections', [
            'form_id' => $form->id,
            'title' => 'New Section',
        ]);
    }

    public function test_section_can_be_renamed(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Old Title', 'sort_order' => 0]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('updateSectionTitle', $section->id, 'Updated Title')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sections', [
            'id' => $section->id,
            'title' => 'Updated Title',
        ]);
    }

    public function test_section_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Remove Me', 'sort_order' => 0]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('deleteSection', $section->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('sections', ['id' => $section->id]);
    }

    public function test_field_can_be_created(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('addField', $section->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fields', [
            'form_id' => $form->id,
            'section_id' => $section->id,
            'label' => 'New Field',
            'type' => 'text',
        ]);
    }

    public function test_field_can_be_updated(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $field = $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'email',
            'label' => 'Email Address',
            'type' => 'email',
            'sort_order' => 0,
        ]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('selectField', $field->id)
            ->set('fieldEditor.label', 'Work Email')
            ->set('fieldEditor.key', 'work_email')
            ->set('fieldEditor.type', 'email')
            ->set('fieldEditor.is_required', true)
            ->call('saveSelectedField')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fields', [
            'id' => $field->id,
            'key' => 'work_email',
            'label' => 'Work Email',
            'is_required' => true,
        ]);
    }

    public function test_field_can_be_duplicated_with_unique_key(): void
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
        ]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('duplicateField', $field->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fields', [
            'form_id' => $form->id,
            'key' => 'full_name_copy',
        ]);
        $this->assertSame(2, $form->fields()->count());
    }

    public function test_field_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $field = $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'remove_me',
            'label' => 'Remove Me',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('deleteField', $field->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('fields', ['id' => $field->id]);
    }

    public function test_field_ordering_persists(): void
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
            ->call('reorderFields', $section->id, [$second->id, $first->id])
            ->assertHasNoErrors();

        $this->assertSame(0, $second->fresh()->sort_order);
        $this->assertSame(1, $first->fresh()->sort_order);
    }

    public function test_section_ordering_persists(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $first = $form->sections()->create(['title' => 'First', 'sort_order' => 0]);
        $second = $form->sections()->create(['title' => 'Second', 'sort_order' => 1]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('reorderSections', [$second->id, $first->id])
            ->assertHasNoErrors();

        $this->assertSame(0, $second->fresh()->sort_order);
        $this->assertSame(1, $first->fresh()->sort_order);
    }

    public function test_required_and_configuration_changes_persist(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $field = $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'department',
            'label' => 'Department',
            'type' => 'select',
            'sort_order' => 0,
            'config' => ['options' => ['HR']],
        ]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('selectField', $field->id)
            ->set('fieldEditor.is_required', true)
            ->set('fieldEditor.optionsText', "HR\nIT\nFinance")
            ->call('saveSelectedField')
            ->assertHasNoErrors();

        $field->refresh();

        $this->assertTrue($field->is_required);
        $this->assertSame(['HR', 'IT', 'Finance'], $field->config['options']);
    }

    public function test_invalid_json_is_rejected(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'name',
            'label' => 'Name',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->set('jsonEditor', '{ invalid json')
            ->call('applyJson')
            ->assertSet('jsonError', fn ($error) => str_starts_with($error, 'Invalid JSON:'));

        $this->assertDatabaseHas('fields', ['key' => 'name']);
    }

    public function test_valid_json_updates_builder_state(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['title' => 'Old Title']);
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'name',
            'label' => 'Name',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $schema = app(FormService::class)->compileSchema($form);
        $schema['title'] = 'JSON Updated Title';
        $schema['sections'][0]['fields'][0]['label'] = 'Full Name';

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->set('jsonEditor', json_encode($schema, JSON_THROW_ON_ERROR))
            ->call('applyJson')
            ->assertHasNoErrors()
            ->assertSet('jsonError', null);

        $form->refresh();

        $this->assertSame('JSON Updated Title', $form->title);
        $this->assertDatabaseHas('fields', [
            'key' => 'name',
            'label' => 'Full Name',
        ]);
    }

    public function test_publish_uses_valid_compiled_schema(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'email',
            'label' => 'Email',
            'type' => 'email',
            'sort_order' => 0,
            'is_required' => true,
        ]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('publish')
            ->assertHasNoErrors();

        $form->refresh();

        $this->assertSame('published', $form->status);
        $this->assertNotNull($form->schema);
        $this->assertSame('email', $form->schema['sections'][0]['fields'][0]['key']);
    }

    public function test_invalid_form_cannot_be_published(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('publish')
            ->assertSet('statusType', 'error');

        $this->assertSame('draft', $form->fresh()->status);
        $this->assertNull($form->fresh()->schema);
    }
}
