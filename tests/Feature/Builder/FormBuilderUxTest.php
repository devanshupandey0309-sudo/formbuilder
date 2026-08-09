<?php

namespace Tests\Feature\Builder;

use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FormBuilderUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_builder_shows_actionable_empty_state(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('forms.builder', $form))
            ->assertOk()
            ->assertSee('Your form canvas is empty')
            ->assertSee('Add first section');
    }

    public function test_published_form_shows_public_url_and_open_action(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->published()->create();

        $this->actingAs($user)
            ->get(route('forms.builder', $form))
            ->assertOk()
            ->assertSee('Public fill URL')
            ->assertSee('Open public form')
            ->assertSee(route('forms.public', $form->slug));
    }

    public function test_draft_form_shows_publish_guidance(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['status' => 'draft']);

        $this->actingAs($user)
            ->get(route('forms.builder', $form))
            ->assertOk()
            ->assertSee('Publishing compiles the current builder structure')
            ->assertDontSee('Open public form');
    }

    public function test_section_description_can_be_updated_from_builder(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Contact', 'sort_order' => 0]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('updateSectionDescription', $section->id, 'Tell us how to reach you.')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sections', [
            'id' => $section->id,
            'description' => 'Tell us how to reach you.',
        ]);
    }

    public function test_builder_includes_form_navigation_links(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('forms.builder', $form))
            ->assertOk()
            ->assertSee('Builder')
            ->assertSee('Preview')
            ->assertSee('Insights');
    }

    public function test_reload_from_server_refreshes_draft_state(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create([
            'draft_revision' => 3,
            'title' => 'Server Title',
        ]);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->set('draftRevision', 1)
            ->set('autosaveStatus', 'conflict')
            ->call('reloadFromServer')
            ->assertSet('draftRevision', 3)
            ->assertSet('autosaveStatus', 'saved')
            ->assertSet('formTitle', 'Server Title');
    }
}
