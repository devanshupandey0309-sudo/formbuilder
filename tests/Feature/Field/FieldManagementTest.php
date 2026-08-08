<?php

namespace Tests\Feature\Field;

use App\Models\Form;
use App\Models\User;
use App\Services\FieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_field(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Details', 'sort_order' => 0]);

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/sections/{$section->id}/fields", [
            'key' => 'full_name',
            'type' => 'text',
            'label' => 'Full Name',
            'placeholder' => 'Enter your name',
            'is_required' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.key', 'full_name')
            ->assertJsonPath('data.sort_order', 0)
            ->assertJsonPath('data.config.placeholder', 'Enter your name');

        $this->assertDatabaseHas('fields', [
            'form_id' => $form->id,
            'section_id' => $section->id,
            'key' => 'full_name',
        ]);
    }

    public function test_user_cannot_create_field_on_another_users_section(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();
        $section = $form->sections()->create(['title' => 'Details', 'sort_order' => 0]);

        $response = $this->actingAs($otherUser)->postJson("/api/forms/{$form->id}/sections/{$section->id}/fields", [
            'key' => 'email',
            'type' => 'email',
            'label' => 'Email',
        ]);

        $response->assertForbidden();
    }

    public function test_field_key_must_be_unique_within_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Details', 'sort_order' => 0]);
        $otherSection = $form->sections()->create(['title' => 'More', 'sort_order' => 1]);

        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'email',
            'label' => 'Email',
            'type' => 'email',
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/sections/{$otherSection->id}/fields", [
            'key' => 'email',
            'type' => 'email',
            'label' => 'Email Again',
        ]);

        $response->assertUnprocessable();
    }

    public function test_supported_field_types_are_accepted(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Details', 'sort_order' => 0]);

        foreach (FieldService::SUPPORTED_TYPES as $index => $type) {
            $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/sections/{$section->id}/fields", [
                'key' => "field_{$type}",
                'type' => $type,
                'label' => ucfirst($type),
            ]);

            $response->assertCreated();
        }
    }

    public function test_invalid_field_type_rejected(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Details', 'sort_order' => 0]);

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/sections/{$section->id}/fields", [
            'key' => 'bad_field',
            'type' => 'file_upload',
            'label' => 'Bad Field',
        ]);

        $response->assertUnprocessable();
    }

    public function test_user_can_update_field(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Details', 'sort_order' => 0]);
        $field = $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'full_name',
            'label' => 'Full Name',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($user)->putJson("/api/forms/{$form->id}/sections/{$section->id}/fields/{$field->id}", [
            'label' => 'Your Full Name',
            'is_required' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.key', 'full_name')
            ->assertJsonPath('data.label', 'Your Full Name');

        $this->assertDatabaseHas('fields', [
            'id' => $field->id,
            'key' => 'full_name',
            'label' => 'Your Full Name',
        ]);
    }

    public function test_updating_field_preserves_key_unless_explicitly_changed(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Details', 'sort_order' => 0]);
        $field = $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'email_address',
            'label' => 'Email',
            'type' => 'email',
            'sort_order' => 0,
        ]);

        $this->actingAs($user)->putJson("/api/forms/{$form->id}/sections/{$section->id}/fields/{$field->id}", [
            'label' => 'Work Email',
        ])->assertOk()->assertJsonPath('data.key', 'email_address');

        $this->actingAs($user)->putJson("/api/forms/{$form->id}/sections/{$section->id}/fields/{$field->id}", [
            'key' => 'work_email',
            'label' => 'Work Email',
        ])->assertOk()->assertJsonPath('data.key', 'work_email');
    }

    public function test_user_can_delete_field(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Details', 'sort_order' => 0]);
        $field = $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'full_name',
            'label' => 'Full Name',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/forms/{$form->id}/sections/{$section->id}/fields/{$field->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('fields', ['id' => $field->id]);
    }

    public function test_fields_can_be_reordered(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Details', 'sort_order' => 0]);

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

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/sections/{$section->id}/fields/reorder", [
            'field_ids' => [$second->id, $first->id],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.0.key', 'second')
            ->assertJsonPath('data.1.key', 'first');

        $this->assertDatabaseHas('fields', ['id' => $second->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('fields', ['id' => $first->id, 'sort_order' => 1]);
    }

    public function test_reorder_rejects_fields_belonging_to_another_section(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Details', 'sort_order' => 0]);
        $otherSection = $form->sections()->create(['title' => 'Other', 'sort_order' => 1]);

        $field = $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'mine',
            'label' => 'Mine',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $foreignField = $form->fields()->create([
            'section_id' => $otherSection->id,
            'key' => 'foreign',
            'label' => 'Foreign',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/sections/{$section->id}/fields/reorder", [
            'field_ids' => [$foreignField->id],
        ]);

        $response->assertUnprocessable();
    }
}
