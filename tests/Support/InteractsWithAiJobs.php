<?php

namespace Tests\Support;

use App\Jobs\GenerateAIFormJob;
use App\Models\AIJob;
use App\Services\AIFormGenerationService;
use Illuminate\Support\Facades\Queue;

trait InteractsWithAiJobs
{
    protected function processPushedAiJobs(): void
    {
        $jobs = Queue::pushed(GenerateAIFormJob::class);

        foreach ($jobs as $job) {
            app()->call([$job, 'handle']);
        }
    }

    protected function processAiJob(AIJob $aiJob): AIJob
    {
        app(AIFormGenerationService::class)->processJob($aiJob);

        return $aiJob->fresh();
    }
}
