<?php

namespace Tests\Feature\PublicForm;

use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicFormSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private FormService $formService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formService = app(FormService::class);
    }

    public function test_public_user_can_retrieve_published_form(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'is_required' => true],
        ]);

        $response = $this->getJson('/api/public/forms/'.$form->slug);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', $form->slug)
            ->assertJsonPath('data.schema.sections.0.fields.0.key', 'full_name')
            ->assertJsonMissingPath('data.user_id');
    }

    public function test_draft_form_cannot_be_retrieved_publicly(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create([
            'slug' => 'draft-form',
            'status' => 'draft',
        ]);

        $this->getJson('/api/public/forms/'.$form->slug)->assertNotFound();
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->getJson('/api/public/forms/does-not-exist')->assertNotFound();
    }

    public function test_valid_submission_succeeds(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'is_required' => true],
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'is_required' => true],
            ['key' => 'age', 'label' => 'Age', 'type' => 'number'],
        ]);

        $response = $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'full_name' => 'John Doe',
                'email' => 'john@example.com',
                'age' => 25,
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.submission_id', Submission::query()->value('id'));
    }

    public function test_required_field_validation_works(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'is_required' => true],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [],
        ])->assertUnprocessable();
    }

    public function test_unknown_field_key_is_rejected(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text'],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'full_name' => 'John Doe',
                'unknown_field' => 'value',
            ],
        ])->assertUnprocessable();
    }

    public function test_invalid_email_is_rejected(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'is_required' => true],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'email' => 'not-an-email',
            ],
        ])->assertUnprocessable();
    }

    public function test_invalid_number_is_rejected(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'age', 'label' => 'Age', 'type' => 'number', 'is_required' => true],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'age' => 'not-a-number',
            ],
        ])->assertUnprocessable();
    }

    public function test_invalid_select_option_is_rejected(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'country',
                'label' => 'Country',
                'type' => 'select',
                'is_required' => true,
                'config' => ['options' => ['USA', 'Canada']],
            ],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'country' => 'Mexico',
            ],
        ])->assertUnprocessable();
    }

    public function test_invalid_radio_option_is_rejected(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'size',
                'label' => 'Size',
                'type' => 'radio',
                'is_required' => true,
                'config' => ['options' => ['small', 'large']],
            ],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'size' => 'medium',
            ],
        ])->assertUnprocessable();
    }

    public function test_checkbox_validation_works(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'interests',
                'label' => 'Interests',
                'type' => 'checkbox',
                'is_required' => true,
                'config' => ['options' => ['sports', 'music']],
            ],
            [
                'key' => 'agree_terms',
                'label' => 'Agree to terms',
                'type' => 'checkbox',
                'is_required' => true,
            ],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'interests' => ['invalid'],
                'agree_terms' => true,
            ],
        ])->assertUnprocessable();

        $response = $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'interests' => ['sports', 'music'],
                'agree_terms' => true,
            ],
        ]);

        $response->assertCreated();
    }

    public function test_submission_is_persisted(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'is_required' => true],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'full_name' => 'Jane Doe',
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('submissions', [
            'form_id' => $form->id,
            'status' => 'completed',
        ]);
    }

    public function test_submission_answers_are_persisted(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'is_required' => true],
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'is_required' => true],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'full_name' => 'Jane Doe',
                'email' => 'jane@example.com',
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('submission_answers', [
            'field_key' => 'full_name',
            'value_text' => 'Jane Doe',
        ]);

        $this->assertDatabaseHas('submission_answers', [
            'field_key' => 'email',
            'value_text' => 'jane@example.com',
        ]);
    }

    public function test_field_key_is_persisted_correctly(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'email_address', 'label' => 'Email Address', 'type' => 'email', 'is_required' => true],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'email_address' => 'user@example.com',
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('submission_answers', [
            'field_key' => 'email_address',
            'field_label' => 'Email Address',
        ]);
    }

    public function test_form_version_is_persisted_correctly(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'is_required' => true],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'full_name' => 'Jane Doe',
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('submissions', [
            'form_id' => $form->id,
            'form_version' => $form->fresh()->version,
        ]);
    }

    public function test_schema_snapshot_is_persisted_correctly(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'is_required' => true],
        ]);

        $publishedSchema = $form->fresh()->schema;

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'full_name' => 'Jane Doe',
            ],
        ])->assertCreated();

        $submission = Submission::query()->first();

        $this->assertSame($publishedSchema, $submission->schema_snapshot);
    }

    public function test_failed_submission_does_not_create_partial_data(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'is_required' => true],
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'is_required' => true],
        ]);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'full_name' => 'Jane Doe',
                'email' => 'invalid-email',
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('submissions', 0);
        $this->assertDatabaseCount('submission_answers', 0);
    }

    public function test_submission_validates_against_published_schema_snapshot(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['slug' => 'contact-form-v1']);
        $section = $form->sections()->create(['title' => 'Main', 'sort_order' => 0]);

        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'full_name',
            'label' => 'Full Name',
            'type' => 'text',
            'sort_order' => 0,
            'is_required' => true,
        ]);

        $this->formService->publishForm($form->fresh());
        $publishedSchema = $form->fresh()->schema;

        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'phone',
            'label' => 'Phone',
            'type' => 'text',
            'sort_order' => 1,
            'is_required' => true,
        ]);

        $this->assertSame($publishedSchema, $form->fresh()->schema);

        $response = $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'full_name' => 'Jane Doe',
            ],
        ]);

        $response->assertCreated();

        $submission = Submission::query()->first();
        $this->assertSame($publishedSchema, $submission->schema_snapshot);
        $this->assertSame($publishedSchema['version'], $submission->form_version);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function createPublishedForm(array $fields, ?User $user = null): Form
    {
        $user ??= User::factory()->create();

        $form = Form::factory()->for($user)->create([
            'slug' => 'contact-form-'.fake()->unique()->numerify('####'),
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
