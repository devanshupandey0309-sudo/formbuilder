<?php

namespace Tests\Feature\PublicForm;

use App\Livewire\Forms\PublicForm;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PublicFormWebTest extends TestCase
{
    use RefreshDatabase;

    private FormService $formService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formService = app(FormService::class);
    }

    public function test_published_form_renders_at_public_url(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'is_required' => true],
        ]);

        $this->get(route('forms.public', $form->slug))
            ->assertOk()
            ->assertSee('Full Name')
            ->assertSee($form->title);
    }

    public function test_draft_form_returns_404_at_public_url(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create([
            'slug' => 'draft-only-form',
            'status' => 'draft',
        ]);

        $this->get(route('forms.public', $form->slug))->assertNotFound();
    }

    public function test_unknown_slug_returns_404_at_public_url(): void
    {
        $this->get(route('forms.public', 'does-not-exist'))->assertNotFound();
    }

    public function test_livewire_submission_succeeds_and_shows_success_state(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'is_required' => true],
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'is_required' => true],
        ]);

        Livewire::test(PublicForm::class, ['slug' => $form->slug])
            ->set('answers.full_name', 'Jane Doe')
            ->set('answers.email', 'jane@example.com')
            ->call('submit')
            ->assertSet('submitted', true)
            ->assertSee('Submission received')
            ->assertSee('Thank you! Your submission has been received.')
            ->assertDontSee('Submit');

        $this->assertDatabaseCount('submissions', 1);
    }

    public function test_livewire_validation_errors_are_displayed(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'is_required' => true],
        ]);

        Livewire::test(PublicForm::class, ['slug' => $form->slug])
            ->call('submit')
            ->assertSet('submitted', false)
            ->assertSee('Please fix the validation errors below.')
            ->assertHasErrors(['answers.full_name']);

        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_boolean_checkbox_field_renders_on_public_form(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'agree_terms',
                'label' => 'I agree to the terms',
                'type' => 'checkbox',
                'is_required' => true,
            ],
        ]);

        Livewire::test(PublicForm::class, ['slug' => $form->slug])
            ->assertSee('I agree to the terms')
            ->set('answers.agree_terms', true)
            ->call('submit')
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('submission_answers', [
            'field_key' => 'agree_terms',
            'value_text' => '1',
        ]);
    }

    public function test_section_description_renders_on_public_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $form->sections()->create([
            'title' => 'Contact Details',
            'description' => 'Tell us how to reach you.',
            'sort_order' => 0,
        ]);

        $section = $form->sections()->first();
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'email',
            'label' => 'Email',
            'type' => 'email',
            'sort_order' => 0,
            'is_required' => true,
        ]);

        $this->formService->publishForm($form->fresh());

        Livewire::test(PublicForm::class, ['slug' => $form->fresh()->slug])
            ->assertSee('Contact Details')
            ->assertSee('Tell us how to reach you.');
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function createPublishedForm(array $fields): Form
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create([
            'slug' => 'public-form-'.fake()->unique()->numerify('####'),
        ]);

        $section = $form->sections()->create([
            'title' => 'Main',
            'sort_order' => 0,
        ]);

        foreach ($fields as $index => $field) {
            $form->fields()->create([
                'section_id' => $section->id,
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'sort_order' => $index,
                'is_required' => $field['is_required'] ?? false,
                'config' => $field['config'] ?? null,
            ]);
        }

        $this->formService->publishForm($form->fresh());

        return $form->fresh();
    }
}
