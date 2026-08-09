<?php

namespace Tests\Feature\Regression;

use App\Models\AIJob;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_owner_cannot_apply_another_users_ai_job(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();

        $job = AIJob::create([
            'user_id' => $owner->id,
            'form_id' => $form->id,
            'type' => 'generate',
            'status' => 'completed',
            'prompt' => 'Create a contact form with name and email fields.',
            'validated_output' => [
                'title' => 'Contact Form',
                'sections' => [
                    [
                        'title' => 'Main',
                        'fields' => [
                            [
                                'key' => 'name',
                                'label' => 'Name',
                                'type' => 'text',
                                'required' => true,
                                'config' => [],
                            ],
                        ],
                    ],
                ],
            ],
            'completed_at' => now(),
        ]);

        $this->actingAs($otherUser)
            ->postJson("/api/forms/{$form->id}/ai/jobs/{$job->id}/apply")
            ->assertForbidden();

        $this->assertSame(0, $form->fresh()->sections()->count());
        $this->assertNull($job->fresh()->applied_at);
    }

    public function test_ai_apply_on_wrong_form_returns_not_found(): void
    {
        $user = User::factory()->create();
        $formA = Form::factory()->for($user)->create();
        $formB = Form::factory()->for($user)->create();

        $job = AIJob::create([
            'user_id' => $user->id,
            'form_id' => $formA->id,
            'type' => 'generate',
            'status' => 'completed',
            'prompt' => 'Create a contact form with name and email fields.',
            'validated_output' => [
                'title' => 'Secret Form',
                'sections' => [
                    [
                        'title' => 'Main',
                        'fields' => [
                            [
                                'key' => 'name',
                                'label' => 'Name',
                                'type' => 'text',
                                'required' => true,
                                'config' => [],
                            ],
                        ],
                    ],
                ],
            ],
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$formB->id}/ai/jobs/{$job->id}/apply");

        $response->assertNotFound();
        $this->assertStringNotContainsString('Secret Form', $response->getContent());
        $this->assertNull($job->fresh()->applied_at);
    }

    public function test_public_form_web_page_does_not_expose_owner_identifiers(): void
    {
        $owner = User::factory()->create(['email' => 'owner-secret@example.com']);
        $form = Form::factory()->for($owner)->published()->create([
            'schema' => [
                'title' => 'Public Survey',
                'sections' => [
                    [
                        'title' => 'Main',
                        'fields' => [
                            [
                                'key' => 'feedback',
                                'label' => 'Feedback',
                                'type' => 'textarea',
                                'required' => false,
                                'config' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->get(route('forms.public', $form->slug));

        $response
            ->assertOk()
            ->assertSee('Public Survey');

        $content = $response->getContent();
        $this->assertStringNotContainsString('owner-secret@example.com', $content);
        $this->assertStringNotContainsString('"user_id"', $content);
        $this->assertStringNotContainsString('user_id', $content);
    }
}
