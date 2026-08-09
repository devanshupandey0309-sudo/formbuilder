<?php

namespace Tests\Feature\Regression;

use App\Models\Form;
use App\Models\User;
use App\Services\AI\AIOutputValidator;
use App\Services\FormHealthService;
use App\Services\AIFormApplyService;
use App\Services\AIFormGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAiJobs;
use Tests\TestCase;

class AIPromptFidelityRegressionTest extends TestCase
{
    use InteractsWithAiJobs;
    use RefreshDatabase;

    private const QA_PROMPT = 'create a form with fields employee name, email, phone number, date of birth';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.driver' => 'mock']);
    }

    public function test_mock_provider_returns_only_requested_fields_for_explicit_prompt(): void
    {
        $output = app(\App\Contracts\AIProvider::class)->generateForm(self::QA_PROMPT);

        $fieldKeys = collect($output['sections'])
            ->flatMap(fn (array $section) => $section['fields'])
            ->pluck('key')
            ->all();

        $this->assertSame(
            ['employee_name', 'email', 'phone_number', 'date_of_birth'],
            $fieldKeys,
        );
        $this->assertSame('Personal Information', $output['sections'][0]['title']);
        $this->assertFalse(collect($output['sections'])->contains(
            fn (array $section) => $section['title'] === 'Employment Details',
        ));
    }

    public function test_validated_output_preserves_requested_labels_and_excludes_unrelated_fields(): void
    {
        $validated = app(AIOutputValidator::class)->validate(
            app(\App\Contracts\AIProvider::class)->generateForm(self::QA_PROMPT),
        );

        $labels = collect($validated['sections'])
            ->flatMap(fn (array $section) => $section['fields'])
            ->pluck('label')
            ->all();

        $keys = collect($validated['sections'])
            ->flatMap(fn (array $section) => $section['fields'])
            ->pluck('key')
            ->all();

        $this->assertSame(
            ['Employee Name', 'Email', 'Phone Number', 'Date of Birth'],
            $labels,
        );
        $this->assertNotContains('department', $keys);
        $this->assertNotContains('joining_date', $keys);
        $this->assertNotContains('Full Name', $labels);
        $this->assertNotContains('Joining Date', $labels);
    }

    public function test_ai_job_for_explicit_prompt_completes_with_prompt_faithful_output(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $job = app(AIFormGenerationService::class)->queueGenerate($user, $form, self::QA_PROMPT);
        $this->processAiJob($job->fresh());

        $job->refresh();

        $this->assertSame('completed', $job->status);
        $this->assertIsArray($job->validated_output);

        $fieldKeys = collect($job->validated_output['sections'])
            ->flatMap(fn (array $section) => $section['fields'])
            ->pluck('key')
            ->all();

        $this->assertSame(
            ['employee_name', 'email', 'phone_number', 'date_of_birth'],
            $fieldKeys,
        );
    }

    public function test_applied_explicit_prompt_form_includes_validation_metadata_and_passes_health_checks(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $job = app(AIFormGenerationService::class)->queueGenerate($user, $form, self::QA_PROMPT);
        $this->processAiJob($job->fresh());

        app(AIFormApplyService::class)->apply($form->fresh(), $job->fresh());

        $form->refresh()->load(['sections.fields']);

        $emailField = $form->fields->firstWhere('key', 'email');
        $dateField = $form->fields->firstWhere('key', 'date_of_birth');

        $this->assertSame(['format' => 'email'], $emailField->validation);
        $this->assertSame(['format' => 'Y-m-d'], $dateField->validation);

        $health = app(FormHealthService::class)->analyze($form);

        $this->assertFalse(collect($health['issues'])->contains(
            fn (array $issue) => $issue['code'] === 'missing_email_validation',
        ));
        $this->assertFalse(collect($health['issues'])->contains(
            fn (array $issue) => $issue['code'] === 'missing_date_validation'
                && ($issue['field_key'] ?? null) === 'date_of_birth',
        ));
    }

    public function test_generic_onboarding_prompt_still_uses_default_mock_fixture(): void
    {
        $output = app(\App\Contracts\AIProvider::class)->generateForm(
            'Create an employee onboarding form with personal information.',
        );

        $this->assertSame('Employee Onboarding Form', $output['title']);
        $this->assertTrue(collect($output['sections'])->contains(
            fn (array $section) => $section['title'] === 'Employment Details',
        ));
        $this->assertTrue(collect($output['sections'])
            ->flatMap(fn (array $section) => $section['fields'])
            ->pluck('key')
            ->contains('department'));
    }

    public function test_customer_registration_prompt_returns_only_requested_fields(): void
    {
        $prompt = 'Create a customer registration form with name, email, phone, country and age.';
        $output = app(\App\Contracts\AIProvider::class)->generateForm($prompt);

        $fieldKeys = collect($output['sections'])
            ->flatMap(fn (array $section) => $section['fields'])
            ->pluck('key')
            ->all();

        $this->assertSame(['name', 'email', 'phone_number', 'country', 'age'], $fieldKeys);
        $this->assertSame('Customer Registration Form', $output['title']);
        $this->assertNotContains('department', $fieldKeys);
        $this->assertNotContains('joining_date', $fieldKeys);
        $this->assertFalse(collect($output['sections'])->contains(
            fn (array $section) => $section['title'] === 'Employment Details',
        ));
    }

    public function test_employee_onboarding_explicit_prompt_returns_only_requested_fields(): void
    {
        $prompt = 'Create an employee onboarding form with employee name, department, joining date, manager email and emergency contact.';
        $output = app(\App\Contracts\AIProvider::class)->generateForm($prompt);

        $fieldKeys = collect($output['sections'])
            ->flatMap(fn (array $section) => $section['fields'])
            ->pluck('key')
            ->all();

        $this->assertSame(
            ['employee_name', 'department', 'joining_date', 'manager_email', 'emergency_contact'],
            $fieldKeys,
        );
        $this->assertSame('Employee Onboarding Form', $output['title']);
        $this->assertNotContains('phone_number', $fieldKeys);
        $this->assertNotContains('date_of_birth', $fieldKeys);
    }
}
