<?php

namespace Tests\Feature\AI;

use App\Jobs\GenerateAIFormJob;
use App\Models\AIJob;
use App\Models\Form;
use App\Models\User;
use App\Services\AI\MockAIProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\InteractsWithAiJobs;
use Tests\TestCase;

class AIFormGenerationTest extends TestCase
{
    use InteractsWithAiJobs;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MockAIProvider::reset();
        config(['ai.driver' => 'mock']);
    }

    protected function tearDown(): void
    {
        MockAIProvider::reset();

        parent::tearDown();
    }

    public function test_authenticated_owner_can_generate(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/generate", [
            'prompt' => 'Create an employee onboarding form with personal information.',
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ai_job.status', 'pending');

        Queue::assertPushed(GenerateAIFormJob::class);
        $this->processPushedAiJobs();

        $this->assertSame('completed', AIJob::query()->value('status'));
    }

    public function test_unauthenticated_user_is_rejected(): void
    {
        $form = Form::factory()->create();

        $this->postJson("/api/forms/{$form->id}/ai/generate", [
            'prompt' => 'Create an employee onboarding form with personal information.',
        ])->assertUnauthorized();
    }

    public function test_another_users_form_is_rejected(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();

        $this->actingAs($otherUser)->postJson("/api/forms/{$form->id}/ai/generate", [
            'prompt' => 'Create an employee onboarding form with personal information.',
        ])->assertForbidden();
    }

    public function test_valid_prompt_is_accepted(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/generate", [
            'prompt' => 'Create a contact form with name and email fields.',
        ])->assertStatus(202);
    }

    public function test_ai_job_is_created(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/generate", [
            'prompt' => 'Create an employee onboarding form with personal information.',
        ]);

        $this->processPushedAiJobs();

        $this->assertDatabaseHas('ai_jobs', [
            'user_id' => $user->id,
            'form_id' => $form->id,
            'type' => 'generate',
            'status' => 'completed',
        ]);
    }

    public function test_successful_provider_response_marks_job_completed(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $job = AIJob::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'type' => 'generate',
            'status' => 'pending',
            'prompt' => 'Create an employee onboarding form with personal information.',
            'input' => ['operation' => 'generate'],
        ]);

        $this->processAiJob($job);

        $job->refresh();

        $this->assertSame('completed', $job->status);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->completed_at);
    }

    public function test_raw_response_is_stored(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/generate", [
            'prompt' => 'Create an employee onboarding form with personal information.',
        ]);

        $this->processPushedAiJobs();

        $job = AIJob::query()->first();

        $this->assertIsArray($job->raw_output);
        $this->assertSame('Employee Onboarding Form', $job->raw_output['title']);
    }

    public function test_validated_output_is_stored(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/generate", [
            'prompt' => 'Create an employee onboarding form with personal information.',
        ]);

        $this->processPushedAiJobs();

        $job = AIJob::query()->first();

        $this->assertIsArray($job->validated_output);
        $this->assertSame('full_name', $job->validated_output['sections'][0]['fields'][0]['key']);
    }

    public function test_malformed_ai_response_fails(): void
    {
        MockAIProvider::fake(['sections' => []]);

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $job = AIJob::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'type' => 'generate',
            'status' => 'pending',
            'prompt' => 'Create an employee onboarding form with personal information.',
            'input' => ['operation' => 'generate'],
        ]);

        $this->processAiJob($job);

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertNotNull($job->fresh()->error_message);
    }

    public function test_unsupported_field_type_fails(): void
    {
        MockAIProvider::fake([
            'title' => 'Bad Form',
            'sections' => [
                [
                    'title' => 'Main',
                    'fields' => [
                        [
                            'key' => 'upload',
                            'label' => 'Upload',
                            'type' => 'file_upload',
                            'required' => false,
                            'config' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $job = AIJob::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'type' => 'generate',
            'status' => 'pending',
            'prompt' => 'Create an employee onboarding form with personal information.',
            'input' => ['operation' => 'generate'],
        ]);

        $this->processAiJob($job);

        $this->assertSame('failed', $job->fresh()->status);
    }

    public function test_duplicate_field_key_fails(): void
    {
        MockAIProvider::fake([
            'title' => 'Duplicate Keys',
            'sections' => [
                [
                    'title' => 'Main',
                    'fields' => [
                        [
                            'key' => 'email',
                            'label' => 'Email 1',
                            'type' => 'email',
                            'required' => true,
                            'config' => [],
                        ],
                        [
                            'key' => 'email',
                            'label' => 'Email 2',
                            'type' => 'email',
                            'required' => true,
                            'config' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $job = AIJob::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'type' => 'generate',
            'status' => 'pending',
            'prompt' => 'Create an employee onboarding form with personal information.',
            'input' => ['operation' => 'generate'],
        ]);

        $this->processAiJob($job);

        $this->assertSame('failed', $job->fresh()->status);
    }

    public function test_missing_title_fails(): void
    {
        MockAIProvider::fake([
            'sections' => [
                [
                    'title' => 'Main',
                    'fields' => [
                        [
                            'key' => 'name',
                            'label' => 'Name',
                            'type' => 'text',
                            'required' => true,
                            'config' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $job = $this->createPendingJob($user, $form);

        $this->processAiJob($job);

        $this->assertSame('failed', $job->fresh()->status);
    }

    public function test_missing_section_fails(): void
    {
        MockAIProvider::fake([
            'title' => 'No Sections',
            'sections' => [],
        ]);

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $job = $this->createPendingJob($user, $form);

        $this->processAiJob($job);

        $this->assertSame('failed', $job->fresh()->status);
    }

    public function test_missing_field_label_fails(): void
    {
        MockAIProvider::fake([
            'title' => 'Missing Label',
            'sections' => [
                [
                    'title' => 'Main',
                    'fields' => [
                        [
                            'key' => 'name',
                            'label' => '',
                            'type' => 'text',
                            'required' => true,
                            'config' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $job = $this->createPendingJob($user, $form);

        $this->processAiJob($job);

        $this->assertSame('failed', $job->fresh()->status);
    }

    public function test_invalid_options_fail(): void
    {
        MockAIProvider::fake([
            'title' => 'Bad Options',
            'sections' => [
                [
                    'title' => 'Main',
                    'fields' => [
                        [
                            'key' => 'department',
                            'label' => 'Department',
                            'type' => 'select',
                            'required' => true,
                            'config' => ['options' => []],
                        ],
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $job = $this->createPendingJob($user, $form);

        $this->processAiJob($job);

        $this->assertSame('failed', $job->fresh()->status);
    }

    public function test_provider_exception_results_in_failed_job(): void
    {
        MockAIProvider::fake(null, new RuntimeException('Provider unavailable'));

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $job = $this->createPendingJob($user, $form);

        $jobInstance = new GenerateAIFormJob($job->id);

        try {
            $jobInstance->handle(app(\App\Services\AIFormGenerationService::class));
        } catch (\App\Exceptions\AI\TransientAIServiceException) {
        }

        try {
            $jobInstance->handle(app(\App\Services\AIFormGenerationService::class));
        } catch (\App\Exceptions\AI\TransientAIServiceException) {
        }

        $jobInstance->failed(new RuntimeException('Provider unavailable'));

        $job->refresh();

        $this->assertSame('failed', $job->status);
        $this->assertSame('AI form generation failed.', $job->error_message);
    }

    public function test_failed_generation_does_not_persist_form_structure(): void
    {
        MockAIProvider::fake(['sections' => []]);

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Existing', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'existing_field',
            'label' => 'Existing Field',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $job = $this->createPendingJob($user, $form);
        $this->processAiJob($job);

        $this->assertDatabaseHas('fields', ['key' => 'existing_field']);
        $this->assertDatabaseCount('sections', 1);
    }

    public function test_owner_can_retrieve_ai_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/generate", [
            'prompt' => 'Create an employee onboarding form with personal information.',
        ]);

        $this->processPushedAiJobs();

        $job = AIJob::query()->first();

        $this->actingAs($user)->getJson("/api/forms/{$form->id}/ai/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.ai_job.id', $job->id);
    }

    public function test_completed_ai_job_can_be_applied(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['status' => 'published', 'schema' => ['version' => 1]]);

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/generate", [
            'prompt' => 'Create an employee onboarding form with personal information.',
        ]);

        $this->processPushedAiJobs();

        $job = AIJob::query()->first();

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/jobs/{$job->id}/apply");

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $form->refresh();

        $this->assertSame('draft', $form->status);
        $this->assertNull($form->schema);
        $this->assertSame('Employee Onboarding Form', $form->title);
        $this->assertDatabaseHas('fields', ['key' => 'full_name']);
        $this->assertDatabaseHas('fields', ['key' => 'department']);
    }

    public function test_failed_job_cannot_be_applied(): void
    {
        MockAIProvider::fake(['sections' => []]);

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $job = $this->createPendingJob($user, $form);
        $this->processAiJob($job);

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/jobs/{$job->id}/apply")
            ->assertUnprocessable();
    }

    public function test_pending_job_cannot_be_applied(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $job = AIJob::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'type' => 'generate',
            'status' => 'pending',
            'prompt' => 'Pending prompt',
        ]);

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/jobs/{$job->id}/apply")
            ->assertUnprocessable();
    }

    public function test_apply_operation_is_transactional(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Existing', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'existing_field',
            'label' => 'Existing Field',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        Queue::fake();

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/generate", [
            'prompt' => 'Create an employee onboarding form with personal information.',
        ]);

        $this->processPushedAiJobs();

        $job = AIJob::query()->first();

        $job->update([
            'validated_output' => null,
            'status' => 'completed',
        ]);

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/jobs/{$job->id}/apply")
            ->assertUnprocessable();

        $this->assertDatabaseHas('fields', ['key' => 'existing_field']);
        $this->assertDatabaseCount('sections', 1);
    }

    private function createPendingJob(User $user, Form $form): AIJob
    {
        return AIJob::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'type' => 'generate',
            'status' => 'pending',
            'prompt' => 'Create an employee onboarding form with personal information.',
            'input' => ['operation' => 'generate'],
        ]);
    }
}
