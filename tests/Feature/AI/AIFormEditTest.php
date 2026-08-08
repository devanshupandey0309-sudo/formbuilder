<?php

namespace Tests\Feature\AI;

use App\Models\AIJob;
use App\Models\Form;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithAiJobs;
use Tests\TestCase;

class AIFormEditTest extends TestCase
{
    use InteractsWithAiJobs;
    use RefreshDatabase;

    public function test_edit_endpoint_returns_pending_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Personal', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'phone',
            'label' => 'Phone Number',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/edit", [
            'prompt' => 'Make phone number required',
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('data.ai_job.status', 'pending')
            ->assertJsonPath('data.ai_job.type', 'edit');
    }

    public function test_edit_job_receives_current_schema(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['title' => 'Employee Form']);
        $section = $form->sections()->create(['title' => 'Personal', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'phone',
            'label' => 'Phone Number',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/edit", [
            'prompt' => 'Make phone number required',
        ]);

        $job = AIJob::query()->first();

        $this->assertSame('edit', $job->type);
        $this->assertArrayHasKey('current_schema', $job->input);
        $this->assertSame('Employee Form', $job->input['current_schema']['title']);
    }

    public function test_edit_generates_proposed_complete_schema(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Personal', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'phone',
            'label' => 'Phone Number',
            'type' => 'text',
            'sort_order' => 0,
            'is_required' => false,
        ]);

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/edit", [
            'prompt' => 'Make phone number required',
        ]);

        $this->processPushedAiJobs();

        $job = AIJob::query()->first();

        $this->assertSame('completed', $job->status);
        $this->assertTrue($job->validated_output['sections'][0]['fields'][0]['required']);
    }

    public function test_existing_form_is_not_changed_before_apply(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Personal', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'phone',
            'label' => 'Phone Number',
            'type' => 'text',
            'sort_order' => 0,
            'is_required' => false,
        ]);

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/edit", [
            'prompt' => 'Make phone number required',
        ]);

        $this->processPushedAiJobs();

        $this->assertFalse($form->fields()->first()->is_required);
    }

    public function test_apply_changes_form_only_after_explicit_apply(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Personal', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'phone',
            'label' => 'Phone Number',
            'type' => 'text',
            'sort_order' => 0,
            'is_required' => false,
        ]);

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/edit", [
            'prompt' => 'Make phone number required',
        ]);

        $this->processPushedAiJobs();

        $job = AIJob::query()->first();

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/jobs/{$job->id}/apply")
            ->assertOk();

        $this->assertTrue($form->fields()->first()->fresh()->is_required);
    }

    public function test_invalid_edit_output_cannot_corrupt_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Personal', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'phone',
            'label' => 'Phone Number',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $job = AIJob::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'type' => 'edit',
            'status' => 'failed',
            'prompt' => 'Make phone number required',
            'validated_output' => null,
        ]);

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/jobs/{$job->id}/apply")
            ->assertUnprocessable();

        $this->assertDatabaseHas('fields', ['key' => 'phone', 'label' => 'Phone Number']);
    }

    public function test_edit_adds_emergency_contact_section_in_proposal(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Personal', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'full_name',
            'label' => 'Full Name',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/edit", [
            'prompt' => 'Add an emergency contact section',
        ]);

        $this->processPushedAiJobs();

        $job = AIJob::query()->first();
        $titles = collect($job->validated_output['sections'])->pluck('title')->all();

        $this->assertContains('Emergency Contact', $titles);
        $this->assertDatabaseCount('sections', 1);
    }

    public function test_non_owner_cannot_edit_form_with_ai(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();

        $this->actingAs($otherUser)->postJson("/api/forms/{$form->id}/ai/edit", [
            'prompt' => 'Make phone number required',
        ])->assertForbidden();
    }
}
