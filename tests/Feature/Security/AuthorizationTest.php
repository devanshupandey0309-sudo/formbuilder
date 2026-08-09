<?php

namespace Tests\Feature\Security;

use App\Models\Form;
use App\Models\FormImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_view_another_users_form(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create(['title' => 'Private Form']);

        $response = $this->actingAs($otherUser)->getJson("/api/forms/{$form->id}");

        $response->assertForbidden();
        $this->assertStringNotContainsString('Private Form', $response->getContent());
    }

    public function test_form_index_only_returns_authenticated_users_forms(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownedForm = Form::factory()->for($user)->create(['title' => 'My Form']);
        Form::factory()->for($otherUser)->create(['title' => 'Other Form']);

        $response = $this->actingAs($user)->getJson('/api/forms');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownedForm->id);

        $this->assertStringNotContainsString('Other Form', $response->getContent());
    }

    public function test_user_cannot_publish_another_users_form(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create(['status' => 'draft']);

        $section = $form->sections()->create(['title' => 'Section', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'name',
            'label' => 'Name',
            'type' => 'text',
            'sort_order' => 0,
            'is_required' => true,
        ]);

        $this->actingAs($otherUser)->postJson("/api/forms/{$form->id}/publish")
            ->assertForbidden();

        $this->assertDatabaseHas('forms', [
            'id' => $form->id,
            'status' => 'draft',
        ]);
    }

    public function test_user_cannot_unpublish_another_users_form(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->published()->create();

        $this->actingAs($otherUser)->postJson("/api/forms/{$form->id}/unpublish")
            ->assertForbidden();

        $this->assertDatabaseHas('forms', [
            'id' => $form->id,
            'status' => 'published',
        ]);
    }

    public function test_section_from_another_form_returns_404_via_scoped_binding(): void
    {
        $user = User::factory()->create();
        $formA = Form::factory()->for($user)->create();
        $formB = Form::factory()->for($user)->create();

        $sectionOnB = $formB->sections()->create(['title' => 'Foreign Section', 'sort_order' => 0]);

        $this->actingAs($user)->putJson("/api/forms/{$formA->id}/sections/{$sectionOnB->id}", [
            'title' => 'Hijacked',
        ])->assertNotFound();

        $this->assertDatabaseHas('sections', [
            'id' => $sectionOnB->id,
            'title' => 'Foreign Section',
        ]);
    }

    public function test_section_delete_from_another_form_returns_404_via_scoped_binding(): void
    {
        $user = User::factory()->create();
        $formA = Form::factory()->for($user)->create();
        $formB = Form::factory()->for($user)->create();

        $sectionOnB = $formB->sections()->create(['title' => 'Protected', 'sort_order' => 0]);

        $this->actingAs($user)->deleteJson("/api/forms/{$formA->id}/sections/{$sectionOnB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('sections', ['id' => $sectionOnB->id]);
    }

    public function test_field_from_another_form_returns_404_via_scoped_binding(): void
    {
        $user = User::factory()->create();
        $formA = Form::factory()->for($user)->create();
        $formB = Form::factory()->for($user)->create();

        $sectionA = $formA->sections()->create(['title' => 'Section A', 'sort_order' => 0]);
        $sectionB = $formB->sections()->create(['title' => 'Section B', 'sort_order' => 0]);

        $fieldOnB = $formB->fields()->create([
            'section_id' => $sectionB->id,
            'key' => 'secret_field',
            'label' => 'Secret Field',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($user)->putJson(
            "/api/forms/{$formA->id}/sections/{$sectionA->id}/fields/{$fieldOnB->id}",
            ['label' => 'Hijacked'],
        );

        $response->assertNotFound();
        $this->assertStringNotContainsString('Secret Field', $response->getContent());

        $this->assertDatabaseHas('fields', [
            'id' => $fieldOnB->id,
            'label' => 'Secret Field',
        ]);
    }

    public function test_field_from_another_section_returns_404_via_scoped_binding(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $sectionA = $form->sections()->create(['title' => 'Section A', 'sort_order' => 0]);
        $sectionB = $form->sections()->create(['title' => 'Section B', 'sort_order' => 1]);

        $fieldOnB = $form->fields()->create([
            'section_id' => $sectionB->id,
            'key' => 'other_section_field',
            'label' => 'Other Section Field',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $this->actingAs($user)->putJson(
            "/api/forms/{$form->id}/sections/{$sectionA->id}/fields/{$fieldOnB->id}",
            ['label' => 'Hijacked'],
        )->assertNotFound();

        $this->assertDatabaseHas('fields', [
            'id' => $fieldOnB->id,
            'label' => 'Other Section Field',
        ]);
    }

    public function test_field_delete_from_another_form_returns_404_via_scoped_binding(): void
    {
        $user = User::factory()->create();
        $formA = Form::factory()->for($user)->create();
        $formB = Form::factory()->for($user)->create();

        $sectionA = $formA->sections()->create(['title' => 'Section A', 'sort_order' => 0]);
        $sectionB = $formB->sections()->create(['title' => 'Section B', 'sort_order' => 0]);

        $fieldOnB = $formB->fields()->create([
            'section_id' => $sectionB->id,
            'key' => 'protected',
            'label' => 'Protected',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $this->actingAs($user)->deleteJson(
            "/api/forms/{$formA->id}/sections/{$sectionA->id}/fields/{$fieldOnB->id}",
        )->assertNotFound();

        $this->assertDatabaseHas('fields', ['id' => $fieldOnB->id]);
    }

    public function test_user_cannot_create_field_using_section_from_another_form(): void
    {
        $user = User::factory()->create();
        $formA = Form::factory()->for($user)->create();
        $formB = Form::factory()->for($user)->create();

        $sectionOnB = $formB->sections()->create(['title' => 'Foreign', 'sort_order' => 0]);

        $this->actingAs($user)->postJson("/api/forms/{$formA->id}/sections/{$sectionOnB->id}/fields", [
            'key' => 'injected',
            'type' => 'text',
            'label' => 'Injected',
        ])->assertNotFound();

        $this->assertDatabaseMissing('fields', ['key' => 'injected']);
    }

    public function test_other_users_form_with_own_section_id_returns_404_via_scoped_binding(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $ownerForm = Form::factory()->for($owner)->create();
        $attackerForm = Form::factory()->for($attacker)->create();

        $attackerSection = $attackerForm->sections()->create(['title' => 'Attacker Section', 'sort_order' => 0]);

        $this->actingAs($attacker)->putJson(
            "/api/forms/{$ownerForm->id}/sections/{$attackerSection->id}",
            ['title' => 'Hijacked'],
        )->assertNotFound();

        $this->assertDatabaseHas('sections', [
            'id' => $attackerSection->id,
            'title' => 'Attacker Section',
        ]);
    }

    public function test_ai_job_from_another_form_returns_404_via_scoped_binding(): void
    {
        $user = User::factory()->create();
        $formA = Form::factory()->for($user)->create();
        $formB = Form::factory()->for($user)->create();

        $job = $formA->aiJobs()->create([
            'user_id' => $user->id,
            'type' => 'generate',
            'status' => 'completed',
            'prompt' => 'Create a contact form',
            'validated_output' => ['title' => 'Secret Form'],
        ]);

        $response = $this->actingAs($user)->getJson("/api/forms/{$formB->id}/ai/jobs/{$job->id}");

        $response->assertNotFound();
        $this->assertStringNotContainsString('Secret Form', $response->getContent());
    }

    public function test_import_from_another_form_returns_404_and_does_not_leak_data(): void
    {
        $user = User::factory()->create();
        $formA = Form::factory()->for($user)->create();
        $formB = Form::factory()->for($user)->create();

        $import = FormImport::create([
            'user_id' => $user->id,
            'form_id' => $formA->id,
            'source_type' => 'xlsx',
            'original_filename' => 'secret.xlsx',
            'file_path' => 'form-imports/secret-path.xlsx',
            'status' => 'preview_ready',
            'preview_data' => ['title' => 'Leaked Import Title', 'sections' => []],
        ]);

        $response = $this->actingAs($user)->getJson("/api/forms/{$formB->id}/imports/{$import->id}");

        $response
            ->assertNotFound()
            ->assertJsonMissingPath('data.file_path');

        $this->assertStringNotContainsString('secret-path.xlsx', $response->getContent());
        $this->assertStringNotContainsString('Leaked Import Title', $response->getContent());
    }

    public function test_draft_autosave_cannot_target_another_users_form(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create(['draft_revision' => 1]);

        $this->actingAs($otherUser)->putJson("/api/forms/{$form->id}/draft", [
            'draft_revision' => 1,
            'title' => 'Hijacked Title',
        ])->assertForbidden();

        $this->assertDatabaseHas('forms', [
            'id' => $form->id,
            'title' => $form->title,
        ]);
    }

    public function test_health_endpoint_denies_other_users_and_does_not_leak_form_data(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create(['title' => 'Confidential Health Form']);

        $section = $form->sections()->create(['title' => 'Sensitive Section', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'ssn',
            'label' => 'SSN',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($otherUser)->getJson("/api/forms/{$form->id}/health");

        $response->assertForbidden();
        $this->assertStringNotContainsString('Confidential Health Form', $response->getContent());
        $this->assertStringNotContainsString('ssn', strtolower($response->getContent()));
    }

    public function test_insights_endpoint_denies_other_users(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();

        $this->actingAs($otherUser)->getJson("/api/forms/{$form->id}/insights")
            ->assertForbidden();
    }
}
