<?php

namespace Tests\Feature\Builder;

use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\User;
use App\Services\AI\MockAIProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\InteractsWithAiJobs;
use Tests\TestCase;

class FormBuilderAITest extends TestCase
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

    public function test_ai_panel_can_start_generation(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->set('aiPrompt', 'Create an employee onboarding form with personal information.')
            ->call('startAiGenerate')
            ->assertSet('aiJobStatus', 'pending')
            ->assertSet('activeAiJobId', fn ($id) => $id !== null);

        Queue::assertPushed(\App\Jobs\GenerateAIFormJob::class);
    }

    public function test_processing_status_becomes_visible_after_refresh(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $component = Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->set('aiPrompt', 'Create an employee onboarding form with personal information.')
            ->call('startAiGenerate');

        $this->processPushedAiJobs();

        $component->call('refreshAiJob')
            ->assertSet('aiJobStatus', 'completed');
    }

    public function test_completed_proposal_can_be_applied(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $component = Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->set('aiPrompt', 'Create an employee onboarding form with personal information.')
            ->call('startAiGenerate');

        $this->processPushedAiJobs();
        $component->call('refreshAiJob');

        $component->call('applyAiJob')
            ->assertSet('activeAiJobId', null);

        $this->assertDatabaseHas('fields', ['key' => 'full_name']);
    }

    public function test_failed_generation_displays_safe_error(): void
    {
        MockAIProvider::fake(['sections' => []]);

        Queue::fake();

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $component = Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->set('aiPrompt', 'Create an employee onboarding form with personal information.')
            ->call('startAiGenerate');

        $this->processPushedAiJobs();

        $component->call('refreshAiJob')
            ->assertSet('aiJobStatus', 'failed')
            ->assertSet('aiProposedJson', null);

        $this->assertNotNull($component->get('aiJobError'));
        $this->assertStringNotContainsString('Stack trace', (string) $component->get('aiJobError'));
    }

    public function test_apply_requires_explicit_user_action(): void
    {
        Queue::fake();

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

        Livewire::actingAs($user)
            ->test(FormBuilder::class, ['form' => $form])
            ->set('aiPrompt', 'Create an employee onboarding form with personal information.')
            ->call('startAiGenerate');

        $this->processPushedAiJobs();

        $this->assertDatabaseHas('fields', ['key' => 'existing_field']);
    }
}
