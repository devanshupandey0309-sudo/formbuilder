<?php

namespace Tests\Feature\Form;

use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_a_form(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/forms', [
            'title' => 'Contact Form',
            'description' => 'A simple contact form.',
        ]);

        $response
            ->assertCreated()
            ->assertJson([
                'success' => true,
                'message' => 'Form created successfully.',
            ])
            ->assertJsonPath('data.title', 'Contact Form')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1);

        $this->assertDatabaseHas('forms', [
            'user_id' => $user->id,
            'title' => 'Contact Form',
            'status' => 'draft',
            'version' => 1,
        ]);
    }

    public function test_unauthenticated_user_cannot_create_a_form(): void
    {
        $response = $this->postJson('/api/forms', [
            'title' => 'Contact Form',
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_can_update_their_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create([
            'title' => 'Old Title',
            'version' => 2,
        ]);

        $response = $this->actingAs($user)->putJson("/api/forms/{$form->id}", [
            'title' => 'Updated Title',
            'description' => 'Updated description.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated Title')
            ->assertJsonPath('data.version', 2);

        $this->assertDatabaseHas('forms', [
            'id' => $form->id,
            'title' => 'Updated Title',
            'version' => 2,
        ]);
    }

    public function test_user_cannot_update_another_users_form(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();

        $response = $this->actingAs($otherUser)->putJson("/api/forms/{$form->id}", [
            'title' => 'Hacked Title',
        ]);

        $response->assertForbidden();
    }

    public function test_user_cannot_delete_another_users_form(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();

        $response = $this->actingAs($otherUser)->deleteJson("/api/forms/{$form->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('forms', ['id' => $form->id]);
    }

    public function test_valid_form_can_be_published(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create([
            'status' => 'draft',
            'version' => 1,
        ]);

        $section = $form->sections()->create([
            'title' => 'Personal Info',
            'sort_order' => 0,
        ]);

        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'full_name',
            'label' => 'Full Name',
            'type' => 'text',
            'sort_order' => 0,
            'is_required' => true,
        ]);

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/publish");

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.schema.sections.0.fields.0.key', 'full_name');

        $form->refresh();
        $this->assertNotNull($form->published_at);
        $this->assertSame('full_name', $form->schema['sections'][0]['fields'][0]['key']);
    }

    public function test_invalid_empty_form_cannot_be_published(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/publish");

        $response->assertUnprocessable();
        $this->assertDatabaseHas('forms', [
            'id' => $form->id,
            'status' => 'draft',
        ]);
    }
}
