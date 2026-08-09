<?php

namespace App\Providers;

use App\Contracts\AIProvider;
use App\Services\AI\GeminiAIProvider;
use App\Services\AI\HttpAIProvider;
use App\Services\AI\MockAIProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AIProvider::class, function () {
            return match (config('ai.driver')) {
                'http' => $this->app->make(HttpAIProvider::class),
                'gemini' => $this->app->make(GeminiAIProvider::class),
                default => $this->app->make(MockAIProvider::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('public-form-view', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->ip());
        });

        RateLimiter::for('public-form-submit', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->ip().'|'.$request->route('slug'));
        });

        RateLimiter::for('ai-form', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->user()?->id.'|'.$request->route('form'));
        });

        RateLimiter::for('form-draft', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id.'|'.$request->route('form'));
        });

        RateLimiter::for('form-import', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->user()?->id.'|'.$request->route('form'));
        });
    }
}
