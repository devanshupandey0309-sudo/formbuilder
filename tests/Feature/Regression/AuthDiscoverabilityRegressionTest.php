<?php

namespace Tests\Feature\Regression;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthDiscoverabilityRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_shows_registration_link(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSeeText("Don't have an account?")
            ->assertSeeText('Register')
            ->assertSee(route('register'), false);
    }

    public function test_guest_can_access_registration_page(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Already registered?', false);
    }

    public function test_root_to_login_to_register_navigation_works(): void
    {
        $loginRedirect = $this->get('/')->assertRedirect(route('login'));

        $this->get($loginRedirect->headers->get('Location'))
            ->assertOk()
            ->assertSee(route('register'), false);

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Name', false)
            ->assertSee('Email', false);
    }

    public function test_authenticated_user_is_redirected_from_registration(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('register'))
            ->assertRedirect(route('dashboard', absolute: false));
    }
}
