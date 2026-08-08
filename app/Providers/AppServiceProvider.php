<?php

namespace App\Providers;

use App\Contracts\AIProvider;
use App\Services\AI\HttpAIProvider;
use App\Services\AI\MockAIProvider;
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
                default => $this->app->make(MockAIProvider::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
