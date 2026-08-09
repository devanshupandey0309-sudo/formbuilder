<?php

namespace Tests\Feature\Regression;

use App\Services\SubmissionInsightService;
use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InsightsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_batched_insights_match_expected_field_analytics(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->published()->create();
        $section = $form->sections()->create(['title' => 'Main', 'sort_order' => 0]);

        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'department',
            'label' => 'Department',
            'type' => 'select',
            'sort_order' => 0,
            'config' => ['options' => ['HR', 'IT']],
        ]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'skills',
            'label' => 'Skills',
            'type' => 'checkbox',
            'sort_order' => 1,
            'config' => ['options' => ['PHP', 'SQL']],
        ]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'score',
            'label' => 'Score',
            'type' => 'number',
            'sort_order' => 2,
        ]);

        $form->update(['schema' => app(FormService::class)->compileSchema($form->fresh())]);

        foreach (['HR', 'IT'] as $index => $department) {
            $submission = Submission::create([
                'form_id' => $form->id,
                'form_version' => $form->version,
                'schema_snapshot' => $form->schema,
                'status' => 'completed',
                'submitted_at' => now()->subDays($index),
            ]);

            SubmissionAnswer::create([
                'submission_id' => $submission->id,
                'field_key' => 'department',
                'value_text' => $department,
            ]);
            SubmissionAnswer::create([
                'submission_id' => $submission->id,
                'field_key' => 'skills',
                'value_json' => [$department === 'HR' ? 'PHP' : 'SQL'],
            ]);
            SubmissionAnswer::create([
                'submission_id' => $submission->id,
                'field_key' => 'score',
                'value_text' => (string) (8 + $index),
            ]);
        }

        $insights = app(SubmissionInsightService::class)->getInsights($form->fresh());

        $this->assertSame(2, $insights['overview']['total_submissions']);

        $department = collect($insights['fields'])->firstWhere('field_key', 'department');
        $this->assertSame(2, $department['total_responses']);
        $this->assertCount(2, $department['distribution']);
        $this->assertSame('HR', $department['distribution'][0]['option']);

        $skills = collect($insights['fields'])->firstWhere('field_key', 'skills');
        $this->assertSame(1, $skills['distribution'][0]['count']);

        $score = collect($insights['fields'])->firstWhere('field_key', 'score');
        $this->assertSame(8.0, $score['numeric_summary']['min']);
        $this->assertSame(9.0, $score['numeric_summary']['max']);
    }

    public function test_performance_indexes_exist_after_migration(): void
    {
        $this->assertTrue(Schema::hasIndex('submission_answers', 'submission_answers_field_key_index'));
        $this->assertTrue(Schema::hasIndex('form_imports', 'form_imports_form_id_status_index'));
    }
}
