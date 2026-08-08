<?php

namespace Tests\Feature\Builder;

use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\User;
use App\Services\FormDraftAutosaveService;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FormDraftAutosaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_autosave_draft_via_api(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['title' => 'Original Title']);

        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}/draft", [
                'draft_revision' => 0,
                'title' => 'Autosaved Title',
                'description' => 'Draft description',
            ])
            ->assertOk()
            ->assertJsonPath('data.draft_revision', 1);

        $form->refresh();

        $this->assertSame('Autosaved Title', $form->title);
        $this->assertSame('Draft description', $form->description);
        $this->assertSame(1, $form->draft_revision);
        $this->assertNotNull($form->draft_saved_at);
    }

    public function test_unauthenticated_user_cannot_autosave(): void
    {
        $form = Form::factory()->create();

        $this->putJson("/api/forms/{$form->id}/draft", [
            'draft_revision' => 0,
            'title' => 'Hacked',
        ])->assertUnauthorized();
    }

    public function test_user_cannot_autosave_another_users_form(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();

        $this->actingAs($otherUser)
            ->putJson("/api/forms/{$form->id}/draft", [
                'draft_revision' => 0,
                'title' => 'Hacked',
            ])
            ->assertForbidden();
    }

    public function test_autosave_does_not_publish_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}/draft", [
                'draft_revision' => 0,
                'title' => 'Draft only',
            ])
            ->assertOk();

        $form->refresh();

        $this->assertSame('draft', $form->status);
        $this->assertNull($form->published_at);
    }

    public function test_autosave_does_not_change_published_schema(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->published()->create();
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'email',
            'label' => 'Email',
            'type' => 'email',
            'sort_order' => 0,
        ]);

        $formService = app(FormService::class);
        $publishedSchema = $formService->compileSchema($form);
        $form->update(['schema' => $publishedSchema]);

        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}/draft", [
                'draft_revision' => 0,
                'title' => 'Updated title only',
            ])
            ->assertOk();

        $form->refresh();

        $this->assertSame($publishedSchema, $form->schema);
    }

    public function test_autosave_does_not_increment_published_version(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->published()->create(['version' => 3]);
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'name',
            'label' => 'Name',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $form->update(['schema' => app(FormService::class)->compileSchema($form)]);

        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}/draft", [
                'draft_revision' => 0,
                'title' => 'Renamed form',
            ])
            ->assertOk();

        $this->assertSame(3, $form->fresh()->version);
    }

    public function test_draft_field_changes_are_persisted(): void
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

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('selectField', $field->id)
            ->set('fieldEditor.label', 'Work Email')
            ->set('fieldEditor.key', 'work_email')
            ->call('autosaveDraft')
            ->assertSet('autosaveStatus', 'saved');

        $field->refresh();

        $this->assertSame('Work Email', $field->label);
        $this->assertSame('work_email', $field->key);
    }

    public function test_incomplete_draft_can_be_autosaved(): void
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

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('selectField', $field->id)
            ->set('fieldEditor.label', 'Temporary label')
            ->set('fieldEditor.key', 'INVALID KEY')
            ->call('autosaveDraft')
            ->assertSet('autosaveStatus', 'saved');

        $field->refresh();

        $this->assertSame('Temporary label', $field->label);
        $this->assertSame('email', $field->key);
    }

    public function test_manual_save_draft_uses_same_persistence_logic(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['title' => 'Before']);

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->set('formTitle', 'Manual Draft Save')
            ->call('saveDraft')
            ->assertSet('autosaveStatus', 'saved');

        $form->refresh();

        $this->assertSame('Manual Draft Save', $form->title);
        $this->assertGreaterThanOrEqual(1, $form->draft_revision);
        $this->assertNotNull($form->draft_saved_at);
    }

    public function test_stale_revision_cannot_overwrite_newer_draft(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['title' => 'Current']);

        app(FormDraftAutosaveService::class)->autosave($form, 0, [
            'title' => 'Server Newer',
        ]);

        $form->refresh();

        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}/draft", [
                'draft_revision' => 0,
                'title' => 'Stale Client',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['draft_revision']);

        $this->assertSame('Server Newer', $form->fresh()->title);
    }

    public function test_recovery_offer_can_be_restored(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['title' => 'Server Title']);
        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $field = $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'name',
            'label' => 'Name',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $compiledSchema = app(FormService::class)->compileSchema($form);
        $compiledSchema['title'] = 'Recovered Title';
        $compiledSchema['sections'][0]['fields'][0]['label'] = 'Recovered Name';

        $snapshot = [
            'formId' => $form->id,
            'timestamp' => now()->addMinute()->toIso8601String(),
            'formTitle' => 'Recovered Title',
            'formDescription' => 'Recovered description',
            'jsonEditor' => json_encode($compiledSchema, JSON_THROW_ON_ERROR),
            'fieldEditor' => [],
            'selectedFieldId' => $field->id,
            'selectedSectionId' => $section->id,
            'activeTab' => 'builder',
            'compiledSchema' => $compiledSchema,
        ];

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('offerRecovery', $snapshot)
            ->assertSet('recoveryOffer.snapshot.formTitle', 'Recovered Title')
            ->call('restoreRecovery')
            ->assertSet('recoveryOffer', null);

        $form->refresh();

        $this->assertSame('Recovered Title', $form->title);
        $this->assertDatabaseHas('fields', [
            'form_id' => $form->id,
            'key' => 'name',
            'label' => 'Recovered Name',
        ]);
    }

    public function test_discard_recovery_clears_offer_state(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('offerRecovery', [
                'formId' => $form->id,
                'timestamp' => now()->addMinute()->toIso8601String(),
                'formTitle' => 'Local only',
            ])
            ->assertSet('recoveryOffer.snapshot.formTitle', 'Local only')
            ->call('discardRecovery')
            ->assertSet('recoveryOffer', null);
    }

    public function test_publish_validation_still_rejects_invalid_structures(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->set('formTitle', 'Incomplete form title')
            ->call('saveDraft')
            ->call('publish')
            ->assertSet('statusType', 'error');

        $this->assertSame('draft', $form->fresh()->status);
        $this->assertNull($form->fresh()->schema);
    }

    public function test_structure_changes_bump_draft_revision(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->call('addSection')
            ->assertSet('draftRevision', 1)
            ->assertSet('autosaveStatus', 'saved');
    }
}
