<?php

namespace Tests\Feature\Regression;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RootRouteRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_does_not_render_laravel_welcome_page(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertDontSee('Vibrant Ecosystem', false);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_verified_user_is_redirected_to_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    public function test_authenticated_unverified_user_is_redirected_to_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_login_and_dashboard_routes_continue_to_work(): void
    {
        $user = User::factory()->create();

        $this->get(route('login'))->assertOk();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }
}
