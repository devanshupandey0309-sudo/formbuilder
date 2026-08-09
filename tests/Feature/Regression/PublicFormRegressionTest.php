<?php

namespace Tests\Feature\Regression;

use App\Livewire\Forms\PublicForm;
use App\Models\Form;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PublicFormRegressionTest extends TestCase
{
    use RefreshDatabase;

    private FormService $formService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formService = app(FormService::class);
    }

    public function test_unpublished_form_is_not_accessible_after_unpublish(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Main', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'name',
            'label' => 'Name',
            'type' => 'text',
            'sort_order' => 0,
            'is_required' => true,
        ]);

        $this->formService->publishForm($form->fresh());
        $slug = $form->fresh()->slug;

        $this->get(route('forms.public', $slug))->assertOk();
        $this->getJson('/api/public/forms/'.$slug)->assertOk();

        $this->formService->unpublishForm($form->fresh());

        $this->get(route('forms.public', $slug))->assertNotFound();
        $this->getJson('/api/public/forms/'.$slug)->assertNotFound();
    }

    public function test_invalid_date_is_rejected(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'start_date',
                'label' => 'Start Date',
                'type' => 'date',
                'is_required' => true,
            ],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'start_date' => '08/08/2026',
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_textarea_submission_succeeds(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'comments',
                'label' => 'Comments',
                'type' => 'textarea',
                'is_required' => true,
            ],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'comments' => 'This is a longer comment with multiple lines.',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('submission_answers', [
            'field_key' => 'comments',
            'value_text' => 'This is a longer comment with multiple lines.',
        ]);
    }

    public function test_success_state_hides_submit_form_from_livewire_ui(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'is_required' => true],
        ]);

        Livewire::test(PublicForm::class, ['slug' => $form->slug])
            ->set('answers.name', 'Jane Doe')
            ->call('submit')
            ->assertSet('submitted', true)
            ->assertSee('Submission received')
            ->assertDontSee('Submit');
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function createPublishedForm(array $fields): Form
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Main', 'sort_order' => 0]);

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
