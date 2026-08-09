<?php

namespace Tests\Feature\PublicForm;

use App\Models\Form;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicFormValidationMetadataTest extends TestCase
{
    use RefreshDatabase;

    private FormService $formService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formService = app(FormService::class);
    }

    public function test_valid_email_with_format_validation_is_accepted(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'email',
                'label' => 'Email',
                'type' => 'email',
                'is_required' => true,
                'validation' => ['format' => 'email'],
            ],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => ['email' => 'user@example.com'],
        ])->assertCreated();
    }

    public function test_invalid_email_with_format_validation_is_rejected(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'email',
                'label' => 'Email',
                'type' => 'email',
                'is_required' => true,
                'validation' => ['format' => 'email'],
            ],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => ['email' => 'not-an-email'],
        ])->assertUnprocessable();
    }

    public function test_valid_date_with_format_validation_is_accepted(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'birth_date',
                'label' => 'Birth Date',
                'type' => 'date',
                'is_required' => true,
                'validation' => ['format' => 'Y-m-d'],
            ],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => ['birth_date' => '1990-05-15'],
        ])->assertCreated();
    }

    public function test_invalid_date_format_is_rejected(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'birth_date',
                'label' => 'Birth Date',
                'type' => 'date',
                'is_required' => true,
                'validation' => ['format' => 'Y-m-d'],
            ],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => ['birth_date' => '15/05/1990'],
        ])->assertUnprocessable();
    }

    public function test_date_min_and_max_validation_are_enforced(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'start_date',
                'label' => 'Start Date',
                'type' => 'date',
                'is_required' => true,
                'validation' => [
                    'format' => 'Y-m-d',
                    'min' => '2024-01-01',
                    'max' => '2024-12-31',
                ],
            ],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => ['start_date' => '2023-12-31'],
        ])->assertUnprocessable();

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => ['start_date' => '2025-01-01'],
        ])->assertUnprocessable();

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => ['start_date' => '2024-06-01'],
        ])->assertCreated();
    }

    public function test_number_min_and_max_validation_are_enforced(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'age',
                'label' => 'Age',
                'type' => 'number',
                'is_required' => true,
                'validation' => ['min' => 18, 'max' => 65],
            ],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => ['age' => 17],
        ])->assertUnprocessable();

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => ['age' => 66],
        ])->assertUnprocessable();

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => ['age' => 30],
        ])->assertCreated();
    }

    public function test_text_length_validation_is_enforced(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'summary',
                'label' => 'Summary',
                'type' => 'text',
                'is_required' => true,
                'validation' => ['min_length' => 3, 'max_length' => 10],
            ],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => ['summary' => 'ab'],
        ])->assertUnprocessable();

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => ['summary' => 'this is too long'],
        ])->assertUnprocessable();

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => ['summary' => 'valid'],
        ])->assertCreated();
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function createPublishedForm(array $fields, ?User $user = null): Form
    {
        $user ??= User::factory()->create();

        $form = Form::factory()->for($user)->create([
            'slug' => 'validation-form-'.fake()->unique()->numerify('####'),
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
                'validation' => $field['validation'] ?? null,
            ]);
        }

        $this->formService->publishForm($form->fresh());

        return $form->fresh();
    }
}
