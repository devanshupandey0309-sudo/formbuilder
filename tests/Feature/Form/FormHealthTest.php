<?php

namespace Tests\Feature\Form;

use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_owner_can_view_form_health(): void
    {
        $user = User::factory()->create();
        $form = $this->createSampleForm($user);

        $response = $this->actingAs($user)->getJson("/api/forms/{$form->id}/health");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'score',
                    'grade',
                    'summary',
                    'categories' => [
                        ['key', 'label', 'score', 'max'],
                    ],
                    'issues',
                    'suggestions',
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_view_form_health(): void
    {
        $form = Form::factory()->create();

        $this->getJson("/api/forms/{$form->id}/health")
            ->assertUnauthorized();
    }

    public function test_user_cannot_view_another_users_form_health(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = $this->createSampleForm($owner);

        $this->actingAs($otherUser)
            ->getJson("/api/forms/{$form->id}/health")
            ->assertForbidden();
    }

    public function test_health_endpoint_does_not_modify_form(): void
    {
        $user = User::factory()->create();
        $form = $this->createSampleForm($user);
        $original = $form->fresh()->toArray();

        $this->actingAs($user)->getJson("/api/forms/{$form->id}/health")->assertOk();

        $this->assertSame($original, $form->fresh()->toArray());
    }

    public function test_api_response_contains_expected_category_keys(): void
    {
        $user = User::factory()->create();
        $form = $this->createSampleForm($user);

        $response = $this->actingAs($user)->getJson("/api/forms/{$form->id}/health");

        $categoryKeys = collect($response->json('data.categories'))->pluck('key')->all();

        $this->assertSame(
            ['structure', 'fields', 'validation', 'required', 'usability'],
            $categoryKeys,
        );
    }

    private function createSampleForm(User $user): Form
    {
        $form = Form::factory()->for($user)->create(['title' => 'Sample Form']);
        $section = $form->sections()->create(['title' => 'Main', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'name',
            'label' => 'Name',
            'type' => 'text',
            'sort_order' => 0,
            'config' => ['placeholder' => 'Your name'],
        ]);

        return $form;
    }
}
