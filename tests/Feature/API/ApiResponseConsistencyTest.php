<?php

namespace Tests\Feature\API;

use App\Models\AIJob;
use App\Models\Form;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiResponseConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private FormService $formService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formService = app(FormService::class);
    }

    public function test_form_creation_returns_expected_success_structure(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/forms', [
            'title' => 'Contact Form',
        ]);

        $response
            ->assertCreated()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'title', 'status'],
            ])
            ->assertJsonPath('success', true);
    }

    public function test_form_retrieval_returns_expected_success_structure(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)
            ->getJson("/api/forms/{$form->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $form->id);
    }

    public function test_section_creation_returns_expected_success_structure(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/sections", [
                'title' => 'Personal Details',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Personal Details');
    }

    public function test_field_creation_returns_expected_success_structure(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Details', 'sort_order' => 0]);

        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/sections/{$section->id}/fields", [
                'key' => 'email',
                'type' => 'email',
                'label' => 'Email',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.key', 'email');
    }

    public function test_ai_generation_returns_202_with_expected_structure(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/ai/generate", [
                'prompt' => 'Create a simple contact form with name and email fields.',
            ])
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'ai_job' => ['id', 'status', 'type'],
                    'generated_form',
                ],
            ]);
    }

    public function test_draft_autosave_returns_expected_success_structure(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}/draft", [
                'draft_revision' => 0,
                'title' => 'Draft Title',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Draft saved successfully.')
            ->assertJsonStructure([
                'data' => ['id', 'draft_revision', 'draft_saved_at'],
            ]);
    }

    public function test_public_form_retrieval_returns_expected_success_structure(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'is_required' => true],
        ]);

        $this->getJson('/api/public/forms/'.$form->slug)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'message',
                'data' => ['slug', 'title', 'schema'],
            ]);
    }

    public function test_public_submission_returns_expected_success_structure(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'is_required' => true],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => ['name' => 'Jane Doe'],
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'message',
                'data' => ['submission_id'],
            ]);
    }

    public function test_unauthenticated_api_request_returns_json_401(): void
    {
        $form = Form::factory()->create();

        $this->getJson("/api/forms/{$form->id}")
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_unauthorized_access_returns_json_403(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();

        $this->actingAs($otherUser)
            ->getJson("/api/forms/{$form->id}")
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson([
                'success' => false,
                'message' => 'This action is unauthorized.',
            ]);
    }

    public function test_missing_scoped_resource_returns_json_404(): void
    {
        $user = User::factory()->create();
        $formA = Form::factory()->for($user)->create();
        $formB = Form::factory()->for($user)->create();
        $sectionOnB = $formB->sections()->create(['title' => 'Foreign', 'sort_order' => 0]);

        $this->actingAs($user)
            ->putJson("/api/forms/{$formA->id}/sections/{$sectionOnB->id}", [
                'title' => 'Hijacked',
            ])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson([
                'success' => false,
                'message' => 'Resource not found.',
            ]);
    }

    public function test_validation_failure_returns_json_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/forms', [])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonStructure([
                'data' => [
                    'errors' => ['title'],
                ],
            ]);
    }

    public function test_rate_limited_api_request_returns_json_429(): void
    {
        RateLimiter::clear('public-form-view');

        $form = $this->createPublishedForm([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
        ]);

        for ($attempt = 0; $attempt < 61; $attempt++) {
            $response = $this->getJson('/api/public/forms/'.$form->slug);
        }

        $response
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson([
                'success' => false,
                'message' => 'Too many requests.',
            ]);
    }

    public function test_ai_apply_validation_failure_returns_safe_json(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $job = AIJob::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'type' => 'generate',
            'status' => 'pending',
            'prompt' => 'Create a contact form.',
        ]);

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/jobs/{$job->id}/apply");

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'AI job cannot be applied.')
            ->assertJsonStructure([
                'data' => ['errors'],
            ]);

        $this->assertStringNotContainsString('stack', strtolower($response->getContent()));
        $this->assertStringNotContainsString('vendor', strtolower($response->getContent()));
    }

    public function test_import_validation_failure_returns_safe_json(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/imports", []);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonStructure([
                'data' => ['errors' => ['file']],
            ]);
    }

    public function test_unexpected_api_exception_does_not_expose_internal_details(): void
    {
        config(['app.debug' => false]);

        Route::get('/api/__test-unexpected-error', function () {
            throw new \RuntimeException('Internal SQL connection secret-path leaked');
        });

        $response = $this->getJson('/api/__test-unexpected-error');

        $response
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson([
                'success' => false,
                'message' => 'An unexpected error occurred.',
            ]);

        $this->assertStringNotContainsString('secret-path', $response->getContent());
        $this->assertStringNotContainsString('SQL', $response->getContent());
    }

    /**
     * @param  list<array{key: string, label: string, type: string, is_required?: bool}>  $fields
     */
    private function createPublishedForm(array $fields): Form
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['status' => 'draft']);
        $section = $form->sections()->create(['title' => 'Main', 'sort_order' => 0]);

        foreach ($fields as $index => $field) {
            $form->fields()->create([
                'section_id' => $section->id,
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'sort_order' => $index,
                'is_required' => $field['is_required'] ?? false,
            ]);
        }

        $this->formService->publishForm($form->fresh());

        return $form->fresh();
    }
}
