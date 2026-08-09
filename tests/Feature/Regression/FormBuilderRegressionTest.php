<?php

namespace Tests\Feature\Regression;

use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FormBuilderRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpublish_moves_form_back_to_draft(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Main', 'sort_order' => 0]);
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
            ->call('unpublish')
            ->assertHasNoErrors();

        $this->assertSame('draft', $form->fresh()->status);
    }

    public function test_needs_republish_after_editing_published_form_structure(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Main', 'sort_order' => 0]);
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
            ->call('addSection')
            ->assertSee('Draft changes are not live yet');

        $form->refresh();
        $this->assertSame('published', $form->status);
        $this->assertNull($form->schema);
    }

    public function test_field_selection_hydrates_editor_state(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Main', 'sort_order' => 0]);
        $field = $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'phone',
            'label' => 'Phone',
            'type' => 'text',
            'sort_order' => 0,
            'is_required' => false,
        ]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('selectField', $field->id)
            ->assertSet('selectedFieldId', $field->id)
            ->assertSet('fieldEditor.label', 'Phone')
            ->assertSet('fieldEditor.key', 'phone')
            ->assertSet('fieldEditor.type', 'text');
    }

    public function test_reload_from_server_clears_conflict_state(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create([
            'title' => 'Server Title',
            'draft_revision' => 2,
        ]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->set('draftRevision', 0)
            ->set('autosaveStatus', 'conflict')
            ->call('reloadFromServer')
            ->assertSet('draftRevision', 2)
            ->assertSet('autosaveStatus', 'saved')
            ->assertSet('formTitle', 'Server Title');
    }
}
