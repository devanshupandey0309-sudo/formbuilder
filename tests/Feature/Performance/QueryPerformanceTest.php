<?php

namespace Tests\Feature\Performance;

use App\Livewire\Forms\FormBuilder;
use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\User;
use App\Services\FormService;
use App\Services\SubmissionInsightService;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class QueryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_builder_loads_form_structure_without_n_plus_one(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Main', 'sort_order' => 0]);

        for ($i = 0; $i < 5; $i++) {
            $form->fields()->create([
                'section_id' => $section->id,
                'key' => 'field_'.$i,
                'label' => 'Field '.$i,
                'type' => 'text',
                'sort_order' => $i,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($user)->test(FormBuilder::class, ['form' => $form->fresh()]);

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(10, $queryCount);
    }

    public function test_public_form_lookup_uses_single_form_query(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->published()->create([
            'schema' => [
                'title' => 'Published',
                'sections' => [],
            ],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(SubmissionService::class)->getPublishedFormBySlug($form->slug);

        $queries = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains($query['query'], 'forms'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(1, $queries);
    }

    public function test_insights_query_count_stays_bounded_with_multiple_fields(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->published()->create();
        $section = $form->sections()->create(['title' => 'Main', 'sort_order' => 0]);

        $fields = [
            ['key' => 'department', 'label' => 'Department', 'type' => 'select', 'options' => ['HR', 'IT', 'Finance']],
            ['key' => 'skills', 'label' => 'Skills', 'type' => 'checkbox', 'options' => ['PHP', 'SQL', 'JS']],
            ['key' => 'rating', 'label' => 'Score', 'type' => 'number'],
            ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
        ];

        foreach ($fields as $index => $field) {
            $form->fields()->create([
                'section_id' => $section->id,
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'sort_order' => $index,
                'config' => isset($field['options']) ? ['options' => $field['options']] : null,
            ]);
        }

        $form->update([
            'schema' => app(FormService::class)->compileSchema($form->fresh()),
        ]);

        $submission = Submission::create([
            'form_id' => $form->id,
            'form_version' => $form->version,
            'schema_snapshot' => $form->schema,
            'status' => 'completed',
            'submitted_at' => now(),
        ]);

        SubmissionAnswer::create([
            'submission_id' => $submission->id,
            'field_key' => 'department',
            'field_label' => 'Department',
            'value_text' => 'HR',
        ]);
        SubmissionAnswer::create([
            'submission_id' => $submission->id,
            'field_key' => 'skills',
            'field_label' => 'Skills',
            'value_json' => ['PHP', 'SQL'],
        ]);
        SubmissionAnswer::create([
            'submission_id' => $submission->id,
            'field_key' => 'rating',
            'field_label' => 'Score',
            'value_text' => '8',
        ]);
        SubmissionAnswer::create([
            'submission_id' => $submission->id,
            'field_key' => 'name',
            'field_label' => 'Name',
            'value_text' => 'Alex',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(SubmissionInsightService::class)->getInsights($form->fresh());

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(12, $queryCount);
    }

    public function test_compile_schema_reuses_loaded_relations(): void
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
        ]);

        $form = $form->fresh();
        $form->load([
            'sections' => fn ($query) => $query->orderBy('sort_order'),
            'sections.fields' => fn ($query) => $query->orderBy('sort_order'),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(FormService::class)->compileSchema($form);
        app(FormService::class)->compileSchema($form);

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queryCount);
    }
}
