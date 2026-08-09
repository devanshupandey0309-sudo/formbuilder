<?php

namespace Tests\Feature\Regression;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class BootstrapRateLimiterRegressionTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function expectedRateLimiters(): array
    {
        return [
            'public-form-view',
            'public-form-submit',
            'ai-form',
            'form-draft',
            'form-import',
        ];
    }

    public function test_application_bootstraps_without_facade_root_error(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_named_rate_limiters_are_registered_in_service_provider(): void
    {
        foreach ($this->expectedRateLimiters() as $limiter) {
            $this->assertNotNull(
                RateLimiter::limiter($limiter),
                "Expected rate limiter [{$limiter}] to be registered.",
            );
        }
    }

    public function test_bootstrap_app_does_not_register_rate_limiters(): void
    {
        $contents = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertIsString($contents);
        $this->assertStringNotContainsString('RateLimiter::for', $contents);
    }
}
