<?php

namespace Tests\Unit;

use App\Models\Form;
use App\Models\User;
use App\Services\FormHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private FormHealthService $formHealthService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formHealthService = app(FormHealthService::class);
    }

    public function test_healthy_form_gets_high_score(): void
    {
        $form = $this->createHealthyForm();

        $result = $this->formHealthService->analyze($form);

        $this->assertGreaterThanOrEqual(90, $result['score']);
        $this->assertSame('Excellent', $result['grade']);
    }

    public function test_empty_form_gets_low_score(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create([
            'title' => '   ',
        ]);

        $result = $this->formHealthService->analyze($form);

        $this->assertLessThan(40, $result['score']);
        $this->assertContains($result['grade'], ['Poor', 'Critical']);
    }

    public function test_missing_title_is_detected(): void
    {
        $form = $this->createHealthyForm(['title' => '   ']);

        $result = $this->formHealthService->analyze($form);

        $this->assertTrue(collect($result['issues'])->contains(
            fn (array $issue) => $issue['code'] === 'missing_title' && $issue['severity'] === 'error',
        ));
    }

    public function test_missing_sections_are_detected(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['title' => 'No Sections Form']);

        $result = $this->formHealthService->analyze($form);

        $this->assertTrue(collect($result['issues'])->contains(
            fn (array $issue) => $issue['code'] === 'missing_sections',
        ));
    }

    public function test_empty_section_is_detected(): void
    {
        $form = $this->createHealthyForm();
        $form->sections()->create(['title' => 'Empty Section', 'sort_order' => 1]);

        $result = $this->formHealthService->analyze($form->fresh(['sections.fields']));

        $this->assertTrue(collect($result['issues'])->contains(
            fn (array $issue) => $issue['code'] === 'empty_section',
        ));
    }

    public function test_missing_field_label_is_detected(): void
    {
        $form = $this->createHealthyForm();
        $section = $form->sections()->first();
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'no_label',
            'label' => '   ',
            'type' => 'text',
            'sort_order' => 2,
            'config' => ['placeholder' => 'Example'],
        ]);

        $result = $this->formHealthService->analyze($form->fresh(['sections.fields']));

        $this->assertTrue(collect($result['issues'])->contains(
            fn (array $issue) => $issue['code'] === 'missing_field_label',
        ));
    }

    public function test_invalid_field_type_is_detected(): void
    {
        $form = $this->createHealthyForm();
        $field = $form->fields()->first();
        $field->update(['type' => 'unsupported']);

        $result = $this->formHealthService->analyze($form->fresh());

        $this->assertTrue(collect($result['issues'])->contains(
            fn (array $issue) => $issue['code'] === 'unsupported_field_type',
        ));
    }

    public function test_duplicate_field_keys_are_detected(): void
    {
        $fields = collect([
            new \App\Models\Field(['key' => 'email', 'label' => 'Email', 'type' => 'email']),
            new \App\Models\Field(['key' => 'email', 'label' => 'Email Duplicate', 'type' => 'email']),
        ]);

        $issues = [];
        $suggestions = [];
        $method = new \ReflectionMethod(FormHealthService::class, 'scoreFieldConfiguration');
        $method->invokeArgs($this->formHealthService, [&$fields, &$issues, &$suggestions]);

        $this->assertTrue(collect($issues)->contains(
            fn (array $issue) => $issue['code'] === 'duplicate_field_key',
        ));
    }

    public function test_missing_options_for_select_are_detected(): void
    {
        $form = $this->createHealthyForm();
        $section = $form->sections()->first();
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'department',
            'label' => 'Department',
            'type' => 'select',
            'sort_order' => 2,
            'config' => null,
        ]);

        $result = $this->formHealthService->analyze($form->fresh(['sections.fields']));

        $this->assertTrue(collect($result['issues'])->contains(
            fn (array $issue) => $issue['code'] === 'missing_field_options',
        ));
    }

    public function test_email_without_validation_generates_recommendation(): void
    {
        $form = $this->createHealthyForm();
        $section = $form->sections()->first();
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'email',
            'label' => 'Email',
            'type' => 'email',
            'sort_order' => 2,
            'config' => ['placeholder' => 'you@example.com'],
            'validation' => null,
        ]);

        $result = $this->formHealthService->analyze($form->fresh(['sections.fields']));

        $this->assertTrue(collect($result['issues'])->contains(
            fn (array $issue) => $issue['code'] === 'missing_email_validation',
        ));
        $this->assertTrue(collect($result['suggestions'])->contains(
            fn (string $suggestion) => str_contains($suggestion, 'Add email validation to Email'),
        ));
    }

    public function test_number_without_validation_generates_recommendation(): void
    {
        $form = $this->createHealthyForm();
        $section = $form->sections()->first();
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'age',
            'label' => 'Age',
            'type' => 'number',
            'sort_order' => 2,
            'config' => ['placeholder' => '18'],
            'validation' => null,
        ]);

        $result = $this->formHealthService->analyze($form->fresh(['sections.fields']));

        $this->assertTrue(collect($result['issues'])->contains(
            fn (array $issue) => $issue['code'] === 'missing_number_validation',
        ));
    }

    public function test_required_field_quality_is_evaluated(): void
    {
        $form = $this->createHealthyForm();
        $fields = $form->fields;
        $fields->each(fn ($field) => $field->update(['is_required' => true]));

        $result = $this->formHealthService->analyze($form->fresh());

        $this->assertTrue(
            collect($result['categories'])->firstWhere('key', 'required')['score'] < 15
            || collect($result['issues'])->contains(fn (array $issue) => $issue['code'] === 'too_many_required_fields'),
        );
    }

    public function test_missing_placeholder_generates_usability_recommendation(): void
    {
        $form = $this->createHealthyForm();
        $field = $form->fields()->first();
        $field->update(['config' => null]);

        $result = $this->formHealthService->analyze($form->fresh());

        $this->assertTrue(collect($result['issues'])->contains(
            fn (array $issue) => $issue['code'] === 'missing_placeholder',
        ));
    }

    public function test_large_section_generates_usability_warning(): void
    {
        $form = $this->createHealthyForm();
        $section = $form->sections()->first();

        for ($index = 2; $index <= 16; $index++) {
            $form->fields()->create([
                'section_id' => $section->id,
                'key' => 'field_'.$index,
                'label' => 'Field '.$index,
                'type' => 'text',
                'sort_order' => $index,
                'config' => ['placeholder' => 'Value'],
            ]);
        }

        $result = $this->formHealthService->analyze($form->fresh(['sections.fields']));

        $this->assertTrue(collect($result['issues'])->contains(
            fn (array $issue) => $issue['code'] === 'large_section',
        ));
    }

    public function test_grade_mapping_works_at_boundaries(): void
    {
        $this->assertSame('Excellent', $this->formHealthService->analyze($this->createHealthyForm())['grade']);

        $user = User::factory()->create();
        $criticalForm = Form::factory()->for($user)->create(['title' => '']);
        $result = $this->formHealthService->analyze($criticalForm);

        $this->assertContains($result['grade'], ['Critical', 'Poor', 'Needs Improvement']);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    public function test_score_never_goes_below_zero(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['title' => '']);
        $section = $form->sections()->create(['title' => '', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'bad_key',
            'label' => '',
            'type' => 'text',
            'sort_order' => 0,
        ]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'select_field',
            'label' => '',
            'type' => 'select',
            'sort_order' => 1,
        ]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'number_field',
            'label' => 'Age',
            'type' => 'number',
            'sort_order' => 2,
        ]);

        $result = $this->formHealthService->analyze($form);

        $this->assertGreaterThanOrEqual(0, $result['score']);
        foreach ($result['categories'] as $category) {
            $this->assertGreaterThanOrEqual(0, $category['score']);
            $this->assertLessThanOrEqual($category['max'], $category['score']);
        }
    }

    public function test_score_never_exceeds_one_hundred(): void
    {
        $result = $this->formHealthService->analyze($this->createHealthyForm());

        $this->assertLessThanOrEqual(100, $result['score']);
    }

    public function test_health_analysis_does_not_modify_form(): void
    {
        $form = $this->createHealthyForm();
        $original = $form->fresh()->toArray();

        $this->formHealthService->analyze($form);

        $this->assertSame($original, $form->fresh()->toArray());
    }

    public function test_checkbox_without_options_is_detected(): void
    {
        $form = $this->createHealthyForm();
        $section = $form->sections()->first();
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'interests',
            'label' => 'Interests',
            'type' => 'checkbox',
            'sort_order' => 2,
        ]);

        $result = $this->formHealthService->analyze($form->fresh(['sections.fields']));

        $this->assertTrue(collect($result['issues'])->contains(
            fn (array $issue) => $issue['code'] === 'missing_field_options',
        ));
    }

    public function test_select_with_empty_options_is_detected(): void
    {
        $form = $this->createHealthyForm();
        $section = $form->sections()->first();
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'status',
            'label' => 'Status',
            'type' => 'select',
            'sort_order' => 2,
            'config' => ['options' => ['', 'Active']],
        ]);

        $result = $this->formHealthService->analyze($form->fresh(['sections.fields']));

        $this->assertTrue(collect($result['issues'])->contains(
            fn (array $issue) => $issue['code'] === 'empty_field_options',
        ));
    }

    public function test_optional_fields_without_validation_do_not_fail_analysis(): void
    {
        $form = $this->createHealthyForm();

        $result = $this->formHealthService->analyze($form);

        $this->assertGreaterThanOrEqual(75, $result['score']);
    }

    public function test_published_and_draft_forms_use_same_analyzer(): void
    {
        $draftForm = $this->createHealthyForm();
        $publishedForm = $this->createHealthyForm(['status' => 'published']);

        $draftResult = $this->formHealthService->analyze($draftForm);
        $publishedResult = $this->formHealthService->analyze($publishedForm);

        $this->assertSame($draftResult['score'], $publishedResult['score']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createHealthyForm(array $overrides = []): Form
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(array_merge([
            'title' => 'Employee Onboarding',
            'description' => 'Collect new hire details.',
        ], $overrides));

        $section = $form->sections()->create([
            'title' => 'Personal Details',
            'sort_order' => 0,
        ]);

        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'full_name',
            'label' => 'Full Name',
            'type' => 'text',
            'sort_order' => 0,
            'is_required' => true,
            'config' => ['placeholder' => 'Jane Doe'],
        ]);

        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'notes',
            'label' => 'Additional Notes',
            'type' => 'textarea',
            'sort_order' => 1,
            'is_required' => false,
            'config' => ['placeholder' => 'Anything else we should know?'],
        ]);

        return $form->fresh(['sections.fields']);
    }
}
