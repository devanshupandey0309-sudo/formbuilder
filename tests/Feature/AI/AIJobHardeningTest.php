<?php

namespace Tests\Feature\AI;

use App\Contracts\AIProvider;
use App\Jobs\GenerateAIFormJob;
use App\Models\AIJob;
use App\Models\Form;
use App\Models\User;
use App\Services\AI\MockAIProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\InteractsWithAiJobs;
use Tests\TestCase;

class AIJobHardeningTest extends TestCase
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

    public function test_generate_endpoint_does_not_call_ai_provider(): void
    {
        Queue::fake();

        $this->mock(AIProvider::class, function ($mock): void {
            $mock->shouldNotReceive('generateForm');
        });

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/generate", [
            'prompt' => 'Create an employee onboarding form with personal information.',
        ])->assertStatus(202);

        Queue::assertPushed(GenerateAIFormJob::class);
    }

    public function test_completed_job_is_not_reprocessed(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $job = AIJob::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'type' => 'generate',
            'status' => AIJob::STATUS_COMPLETED,
            'prompt' => 'Create an employee onboarding form with personal information.',
            'attempt_count' => 1,
            'validated_output' => [
                'title' => 'Locked Output',
                'sections' => [],
            ],
            'completed_at' => now(),
        ]);

        (new GenerateAIFormJob($job->id))->handle(app(\App\Services\AIFormGenerationService::class));

        $job->refresh();

        $this->assertSame(AIJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(1, $job->attempt_count);
        $this->assertSame('Locked Output', $job->validated_output['title']);
    }

    public function test_runtime_provider_failure_is_not_retried(): void
    {
        MockAIProvider::fake(null, new RuntimeException('Prompt cannot be empty.'));

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $job = AIJob::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'type' => 'generate',
            'status' => AIJob::STATUS_PENDING,
            'prompt' => 'Create an employee onboarding form with personal information.',
            'input' => ['operation' => 'generate'],
        ]);

        (new GenerateAIFormJob($job->id))->handle(app(\App\Services\AIFormGenerationService::class));

        $job->refresh();

        $this->assertSame(AIJob::STATUS_FAILED, $job->status);
        $this->assertSame(1, $job->attempt_count);
        $this->assertSame('Prompt cannot be empty.', $job->error_message);
    }

    public function test_fastapi_validation_error_is_not_retried(): void
    {
        config(['ai.driver' => 'http', 'ai.service_url' => 'http://ai.test']);

        Http::fake([
            'http://ai.test/generate-form' => Http::response(['detail' => 'Invalid generated schema'], 422),
        ]);

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $job = AIJob::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'type' => 'generate',
            'status' => AIJob::STATUS_PENDING,
            'prompt' => 'Create an employee onboarding form with personal information.',
            'input' => ['operation' => 'generate'],
        ]);

        (new GenerateAIFormJob($job->id))->handle(app(\App\Services\AIFormGenerationService::class));

        $job->refresh();

        $this->assertSame(AIJob::STATUS_FAILED, $job->status);
        $this->assertSame(1, $job->attempt_count);
        $this->assertStringContainsString('Invalid generated schema', (string) $job->error_message);
    }

    public function test_ai_job_cannot_be_applied_twice(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $job = AIJob::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'type' => 'generate',
            'status' => AIJob::STATUS_COMPLETED,
            'prompt' => 'Create an employee onboarding form with personal information.',
            'validated_output' => [
                'title' => 'Applied Form',
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
            ],
            'completed_at' => now(),
        ]);

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/jobs/{$job->id}/apply")
            ->assertOk();

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/jobs/{$job->id}/apply")
            ->assertStatus(422)
            ->assertJsonPath('data.errors.ai_job.0', 'This AI job has already been applied.');

        $this->assertNotNull($job->fresh()->applied_at);
    }
}
