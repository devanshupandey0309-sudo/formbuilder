<?php

namespace Tests\Unit;

use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\User;
use App\Services\FormService;
use App\Services\SubmissionInsightService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubmissionInsightServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubmissionInsightService $service;

    private FormService $formService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SubmissionInsightService::class);
        $this->formService = app(FormService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_zero_submissions_return_sensible_values(): void
    {
        $form = $this->createPublishedForm([]);

        $insights = $this->service->getInsights($form);

        $this->assertSame(0, $insights['overview']['total_submissions']);
        $this->assertSame(0, $insights['overview']['today']);
        $this->assertSame(0, $insights['overview']['last_7_days']);
        $this->assertSame(0, $insights['overview']['last_30_days']);
        $this->assertSame(0.0, $insights['overview']['average_per_day']);
        $this->assertNull($insights['overview']['first_submission_at']);
        $this->assertNull($insights['overview']['latest_submission_at']);
        $this->assertCount(30, $insights['trend']);
        $this->assertTrue(collect($insights['insights'])->contains(
            fn (array $item) => $item['code'] === 'no_submissions',
        ));
    }

    public function test_overview_calculations(): void
    {
        Carbon::setTestNow('2026-08-08 12:00:00');

        $form = $this->createPublishedForm([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'is_required' => true],
        ]);

        $this->createSubmission($form, now()->subDays(10), ['name' => 'Alice']);
        $this->createSubmission($form, now()->subDays(2), ['name' => 'Bob']);
        $this->createSubmission($form, now(), ['name' => 'Carol']);

        $overview = $this->service->getInsights($form)['overview'];

        $this->assertSame(3, $overview['total_submissions']);
        $this->assertSame(1, $overview['today']);
        $this->assertSame(2, $overview['last_7_days']);
        $this->assertSame(3, $overview['last_30_days']);
        $this->assertNotNull($overview['first_submission_at']);
        $this->assertNotNull($overview['latest_submission_at']);
    }

    public function test_last_seven_days_count(): void
    {
        Carbon::setTestNow('2026-08-08 12:00:00');

        $form = $this->createPublishedForm([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'is_required' => true],
        ]);

        $this->createSubmission($form, now()->subDays(8), ['name' => 'Old']);
        $this->createSubmission($form, now()->subDays(6), ['name' => 'Recent']);
        $this->createSubmission($form, now(), ['name' => 'Today']);

        $overview = $this->service->getInsights($form)['overview'];

        $this->assertSame(2, $overview['last_7_days']);
    }

    public function test_last_thirty_days_count(): void
    {
        Carbon::setTestNow('2026-08-08 12:00:00');

        $form = $this->createPublishedForm([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'is_required' => true],
        ]);

        $this->createSubmission($form, now()->subDays(31), ['name' => 'Too old']);
        $this->createSubmission($form, now()->subDays(20), ['name' => 'Included']);
        $this->createSubmission($form, now(), ['name' => 'Today']);

        $overview = $this->service->getInsights($form)['overview'];

        $this->assertSame(2, $overview['last_30_days']);
    }

    public function test_trend_aggregation_includes_zero_days(): void
    {
        Carbon::setTestNow('2026-08-08 12:00:00');

        $form = $this->createPublishedForm([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'is_required' => true],
        ]);

        $this->createSubmission($form, now()->subDays(2), ['name' => 'A']);
        $this->createSubmission($form, now()->subDays(2), ['name' => 'B']);
        $this->createSubmission($form, now(), ['name' => 'C']);

        $trend = $this->service->getInsights($form)['trend'];

        $this->assertCount(30, $trend);
        $this->assertSame(2, collect($trend)->firstWhere('date', now()->subDays(2)->toDateString())['count']);
        $this->assertSame(0, collect($trend)->firstWhere('date', now()->subDays(1)->toDateString())['count']);
        $this->assertSame(1, collect($trend)->firstWhere('date', now()->toDateString())['count']);
    }

    public function test_field_response_counts_and_rates(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'is_required' => true],
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'text'],
        ]);

        $this->createSubmission($form, now(), ['name' => 'Alice', 'phone' => '111']);
        $this->createSubmission($form, now(), ['name' => 'Bob']);

        $fields = collect($this->service->getInsights($form)['fields'])->keyBy('field_key');

        $this->assertSame(2, $fields['name']['total_responses']);
        $this->assertSame(100.0, $fields['name']['response_rate']);
        $this->assertSame(1, $fields['phone']['total_responses']);
        $this->assertSame(50.0, $fields['phone']['response_rate']);
    }

    public function test_select_distribution_is_calculated(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'department',
                'label' => 'Department',
                'type' => 'select',
                'config' => ['options' => ['Engineering', 'Operations', 'HR']],
            ],
        ]);

        $this->createSubmission($form, now(), ['department' => 'Engineering']);
        $this->createSubmission($form, now(), ['department' => 'Engineering']);
        $this->createSubmission($form, now(), ['department' => 'HR']);

        $field = collect($this->service->getInsights($form)['fields'])->firstWhere('field_key', 'department');

        $this->assertCount(2, $field['distribution']);
        $this->assertSame('Engineering', $field['distribution'][0]['option']);
        $this->assertSame(2, $field['distribution'][0]['count']);
        $this->assertSame(66.7, $field['distribution'][0]['percentage']);
    }

    public function test_checkbox_multi_value_distribution(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'skills',
                'label' => 'Skills',
                'type' => 'checkbox',
                'config' => ['options' => ['PHP', 'SQL', 'Design']],
            ],
        ]);

        $this->createSubmission($form, now(), ['skills' => ['PHP', 'SQL']]);
        $this->createSubmission($form, now(), ['skills' => ['PHP']]);
        $this->createSubmission($form, now(), ['skills' => ['Design']]);

        $field = collect($this->service->getInsights($form)['fields'])->firstWhere('field_key', 'skills');
        $distribution = collect($field['distribution'])->keyBy('option');

        $this->assertSame(2, $distribution['PHP']['count']);
        $this->assertSame(1, $distribution['SQL']['count']);
        $this->assertSame(1, $distribution['Design']['count']);
    }

    public function test_numeric_summary_is_calculated(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'age', 'label' => 'Age', 'type' => 'number'],
        ]);

        $this->createSubmission($form, now(), ['age' => 20]);
        $this->createSubmission($form, now(), ['age' => 30]);
        $this->createSubmission($form, now(), ['age' => 40]);

        $field = collect($this->service->getInsights($form)['fields'])->firstWhere('field_key', 'age');

        $this->assertSame(20.0, $field['numeric_summary']['min']);
        $this->assertSame(40.0, $field['numeric_summary']['max']);
        $this->assertSame(30.0, $field['numeric_summary']['average']);
    }

    public function test_recommendation_generation_for_low_response_rate(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'is_required' => true],
            ['key' => 'phone', 'label' => 'Phone Number', 'type' => 'text'],
        ]);

        $this->createSubmission($form, now(), ['name' => 'Alice']);
        $this->createSubmission($form, now(), ['name' => 'Bob']);
        $this->createSubmission($form, now(), ['name' => 'Carol', 'phone' => '123']);
        $this->createSubmission($form, now(), ['name' => 'Dan']);

        $insights = $this->service->getInsights($form)['insights'];

        $this->assertTrue(collect($insights)->contains(
            fn (array $item) => $item['code'] === 'low_response_rate'
                && str_contains($item['message'], 'Phone Number'),
        ));
    }

    public function test_highly_concentrated_option_recommendation(): void
    {
        $form = $this->createPublishedForm([
            [
                'key' => 'department',
                'label' => 'Department',
                'type' => 'select',
                'config' => ['options' => ['Engineering', 'Operations', 'HR']],
            ],
        ]);

        for ($index = 0; $index < 7; $index++) {
            $this->createSubmission($form, now(), ['department' => 'Engineering']);
        }

        $this->createSubmission($form, now(), ['department' => 'HR']);

        $insights = $this->service->getInsights($form)['insights'];

        $this->assertTrue(collect($insights)->contains(
            fn (array $item) => $item['code'] === 'concentrated_option'
                && str_contains($item['message'], 'Engineering'),
        ));
    }

    public function test_high_required_completion_success_recommendation(): void
    {
        $form = $this->createPublishedForm([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'is_required' => true],
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'is_required' => true],
        ]);

        $this->createSubmission($form, now(), ['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->createSubmission($form, now(), ['name' => 'Bob', 'email' => 'bob@example.com']);

        $insights = $this->service->getInsights($form)['insights'];

        $this->assertTrue(collect($insights)->contains(
            fn (array $item) => $item['code'] === 'high_required_completion',
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function createPublishedForm(array $fields): Form
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create([
            'slug' => 'insights-form-'.fake()->unique()->numerify('####'),
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

        if ($fields === []) {
            $form->fields()->create([
                'section_id' => $section->id,
                'key' => 'placeholder',
                'label' => 'Placeholder',
                'type' => 'text',
                'sort_order' => 0,
            ]);
        }

        $this->formService->publishForm($form->fresh());

        return $form->fresh();
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function createSubmission(Form $form, Carbon $submittedAt, array $answers): Submission
    {
        $submission = Submission::create([
            'form_id' => $form->id,
            'form_version' => $form->version,
            'schema_snapshot' => $form->schema,
            'status' => 'completed',
            'submitted_at' => $submittedAt,
        ]);

        foreach ($answers as $key => $value) {
            SubmissionAnswer::create([
                'submission_id' => $submission->id,
                'field_id' => $form->fields()->where('key', $key)->value('id'),
                'field_key' => $key,
                'field_label' => $form->fields()->where('key', $key)->value('label'),
                'value_text' => is_array($value) ? null : (string) $value,
                'value_json' => is_array($value) ? $value : null,
            ]);
        }

        return $submission;
    }
}
