<?php

namespace Tests\Feature\Form;

use App\Livewire\Forms\FormInsights;
use App\Models\Form;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubmissionInsightTest extends TestCase
{
    use RefreshDatabase;

    private FormService $formService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formService = app(FormService::class);
    }

    public function test_authenticated_owner_can_view_insights(): void
    {
        $user = User::factory()->create();
        $form = $this->createSampleForm($user);

        $this->actingAs($user)
            ->getJson("/api/forms/{$form->id}/insights")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'overview' => [
                        'total_submissions',
                        'today',
                        'last_7_days',
                        'last_30_days',
                        'average_per_day',
                        'first_submission_at',
                        'latest_submission_at',
                    ],
                    'trend',
                    'fields',
                    'insights',
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_view_insights(): void
    {
        $form = Form::factory()->create();

        $this->getJson("/api/forms/{$form->id}/insights")
            ->assertUnauthorized();
    }

    public function test_another_user_cannot_view_insights(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = $this->createSampleForm($owner);

        $this->actingAs($otherUser)
            ->getJson("/api/forms/{$form->id}/insights")
            ->assertForbidden();
    }

    public function test_insights_do_not_expose_raw_answer_data(): void
    {
        $user = User::factory()->create();
        $form = $this->createSampleForm($user);

        $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
            'answers' => [
                'name' => 'Secret Name',
            ],
        ])->assertCreated();

        $response = $this->actingAs($user)->getJson("/api/forms/{$form->id}/insights");

        $payload = json_encode($response->json());

        $this->assertStringNotContainsString('Secret Name', $payload);
        $this->assertArrayNotHasKey('value_text', $response->json('data'));
        $this->assertArrayNotHasKey('answers', $response->json('data'));
    }

    public function test_form_with_no_submissions_returns_valid_empty_insights(): void
    {
        $user = User::factory()->create();
        $form = $this->createSampleForm($user);

        $response = $this->actingAs($user)->getJson("/api/forms/{$form->id}/insights");

        $response
            ->assertOk()
            ->assertJsonPath('data.overview.total_submissions', 0)
            ->assertJsonPath('data.overview.today', 0)
            ->assertJsonCount(30, 'data.trend');

        $this->assertTrue(collect($response->json('data.insights'))->contains(
            fn (array $item) => $item['code'] === 'no_submissions',
        ));
    }

    public function test_owner_can_open_insights_page(): void
    {
        $user = User::factory()->create();
        $form = $this->createSampleForm($user);

        $this->actingAs($user)
            ->get(route('forms.insights', $form))
            ->assertOk()
            ->assertSeeLivewire(FormInsights::class)
            ->assertSee('Submission insights');
    }

    private function createSampleForm(User $user): Form
    {
        $form = Form::factory()->for($user)->create([
            'slug' => 'insights-'.fake()->unique()->numerify('####'),
        ]);

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

        return $form->fresh();
    }
}
