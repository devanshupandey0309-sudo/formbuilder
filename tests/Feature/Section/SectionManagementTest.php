<?php

namespace Tests\Feature\Section;

use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_section(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/sections", [
            'title' => 'Personal Details',
            'description' => 'Tell us about yourself.',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'Personal Details')
            ->assertJsonPath('data.sort_order', 0);

        $this->assertDatabaseHas('sections', [
            'form_id' => $form->id,
            'title' => 'Personal Details',
            'sort_order' => 0,
        ]);
    }

    public function test_user_cannot_create_section_on_another_users_form(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();

        $response = $this->actingAs($otherUser)->postJson("/api/forms/{$form->id}/sections", [
            'title' => 'Unauthorized Section',
        ]);

        $response->assertForbidden();
    }

    public function test_user_can_update_section(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create([
            'title' => 'Old Title',
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($user)->putJson("/api/forms/{$form->id}/sections/{$section->id}", [
            'title' => 'Updated Title',
            'description' => 'Updated description.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated Title');

        $this->assertDatabaseHas('sections', [
            'id' => $section->id,
            'title' => 'Updated Title',
        ]);
    }

    public function test_user_cannot_update_another_users_section(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();
        $section = $form->sections()->create([
            'title' => 'Owner Section',
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($otherUser)->putJson("/api/forms/{$form->id}/sections/{$section->id}", [
            'title' => 'Hacked Title',
        ]);

        $response->assertForbidden();
    }

    public function test_user_can_delete_section(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create([
            'title' => 'To Delete',
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/forms/{$form->id}/sections/{$section->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('sections', ['id' => $section->id]);
    }

    public function test_user_cannot_delete_another_users_section(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();
        $section = $form->sections()->create([
            'title' => 'Protected Section',
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($otherUser)->deleteJson("/api/forms/{$form->id}/sections/{$section->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('sections', ['id' => $section->id]);
    }

    public function test_sections_can_be_reordered(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $first = $form->sections()->create(['title' => 'First', 'sort_order' => 0]);
        $second = $form->sections()->create(['title' => 'Second', 'sort_order' => 1]);
        $third = $form->sections()->create(['title' => 'Third', 'sort_order' => 2]);

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/sections/reorder", [
            'section_ids' => [$third->id, $first->id, $second->id],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Third')
            ->assertJsonPath('data.1.title', 'First')
            ->assertJsonPath('data.2.title', 'Second');

        $this->assertDatabaseHas('sections', ['id' => $third->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('sections', ['id' => $first->id, 'sort_order' => 1]);
        $this->assertDatabaseHas('sections', ['id' => $second->id, 'sort_order' => 2]);
    }

    public function test_reorder_rejects_sections_belonging_to_another_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $otherForm = Form::factory()->for($user)->create();

        $section = $form->sections()->create(['title' => 'Mine', 'sort_order' => 0]);
        $foreignSection = $otherForm->sections()->create(['title' => 'Foreign', 'sort_order' => 0]);

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/sections/reorder", [
            'section_ids' => [$foreignSection->id],
        ]);

        $response->assertUnprocessable();
    }
}
