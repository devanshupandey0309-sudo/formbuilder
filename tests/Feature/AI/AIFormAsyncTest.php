<?php

namespace Tests\Feature\AI;

use App\Exceptions\AI\TransientAIServiceException;
use App\Jobs\GenerateAIFormJob;
use App\Models\AIJob;
use App\Models\Form;
use App\Models\User;
use App\Services\AI\HttpAIProvider;
use App\Services\AI\MockAIProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\InteractsWithAiJobs;
use Tests\TestCase;

class AIFormAsyncTest extends TestCase
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

    public function test_generate_endpoint_returns_immediately_with_pending_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/generate", [
            'prompt' => 'Create an employee onboarding form with personal information.',
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('data.ai_job.status', 'pending')
            ->assertJsonPath('data.generated_form', null);

        $this->assertSame('pending', AIJob::query()->value('status'));
    }

    public function test_queue_job_is_dispatched(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/generate", [
            'prompt' => 'Create an employee onboarding form with personal information.',
        ]);

        Queue::assertPushed(GenerateAIFormJob::class, 1);
    }

    public function test_job_transitions_pending_to_processing_to_completed(): void
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
        $this->assertSame(1, $job->attempt_count);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->completed_at);
    }

    public function test_job_changes_to_failed_on_permanent_validation_failure(): void
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

        $queuedJob = new GenerateAIFormJob($job->id);
        $queuedJob->handle(app(\App\Services\AIFormGenerationService::class));

        $job->refresh();

        $this->assertSame('failed', $job->status);
        $this->assertNotNull($job->error_message);
    }

    public function test_transient_failure_can_be_retried(): void
    {
        MockAIProvider::fake(null, new TransientAIServiceException('Service unavailable'));

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

        $queuedJob = new GenerateAIFormJob($job->id);

        try {
            $queuedJob->handle(app(\App\Services\AIFormGenerationService::class));
        } catch (TransientAIServiceException) {
        }

        $job->refresh();
        $this->assertSame(1, $job->attempt_count);

        MockAIProvider::reset();

        $queuedJob->handle(app(\App\Services\AIFormGenerationService::class));

        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame(2, $job->fresh()->attempt_count);
    }

    public function test_fastapi_network_failure_is_treated_as_transient(): void
    {
        config(['ai.driver' => 'http']);

        Http::fake(function () {
            throw new RuntimeException('Connection refused');
        });

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

        $queuedJob = new GenerateAIFormJob($job->id);

        $this->expectException(TransientAIServiceException::class);
        $queuedJob->handle(app(\App\Services\AIFormGenerationService::class));
    }

    public function test_http_provider_returns_validated_structure(): void
    {
        config(['ai.driver' => 'http', 'ai.service_url' => 'http://ai.test']);

        Http::fake([
            'http://ai.test/generate-form' => Http::response([
                'title' => 'Employee Onboarding Form',
                'description' => 'Employee onboarding information',
                'sections' => [
                    [
                        'title' => 'Personal Information',
                        'description' => null,
                        'fields' => [
                            [
                                'key' => 'full_name',
                                'label' => 'Full Name',
                                'type' => 'text',
                                'required' => true,
                                'config' => [],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $provider = app(HttpAIProvider::class);
        $output = $provider->generateForm('Create an employee onboarding form with personal information.');

        $this->assertSame('Employee Onboarding Form', $output['title']);
    }

    public function test_non_owner_cannot_view_job(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();

        $job = AIJob::create([
            'user_id' => $owner->id,
            'form_id' => $form->id,
            'type' => 'generate',
            'status' => 'completed',
            'prompt' => 'Create an employee onboarding form with personal information.',
        ]);

        $this->actingAs($otherUser)->getJson("/api/forms/{$form->id}/ai/jobs/{$job->id}")
            ->assertForbidden();
    }

    public function test_ai_job_cannot_be_accessed_through_another_form(): void
    {
        $user = User::factory()->create();
        $formA = Form::factory()->for($user)->create();
        $formB = Form::factory()->for($user)->create();

        $job = AIJob::create([
            'user_id' => $user->id,
            'form_id' => $formA->id,
            'type' => 'generate',
            'status' => 'completed',
            'prompt' => 'Create an employee onboarding form with personal information.',
        ]);

        $this->actingAs($user)->getJson("/api/forms/{$formB->id}/ai/jobs/{$job->id}")
            ->assertNotFound();
    }
}
