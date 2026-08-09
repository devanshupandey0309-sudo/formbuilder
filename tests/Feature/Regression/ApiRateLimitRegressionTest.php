<?php

namespace Tests\Feature\Regression;

use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiRateLimitRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_generation_rate_limit_returns_json_429(): void
    {
        RateLimiter::clear('ai-form');

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $response = null;

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/ai/generate", [
                'prompt' => 'Create a simple employee onboarding form with personal details.',
            ]);
        }

        $response
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson([
                'success' => false,
                'message' => 'Too many requests.',
            ]);
    }

    public function test_public_submission_rate_limit_returns_json_429(): void
    {
        RateLimiter::clear('public-form-submit');

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->published()->create([
            'schema' => [
                'title' => 'Rate Limit Form',
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
        ]);

        $response = null;

        for ($attempt = 0; $attempt < 11; $attempt++) {
            $response = $this->postJson('/api/public/forms/'.$form->slug.'/submit', [
                'answers' => ['name' => 'Attempt '.$attempt],
            ]);
        }

        $response
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Too many requests.');
    }
}
