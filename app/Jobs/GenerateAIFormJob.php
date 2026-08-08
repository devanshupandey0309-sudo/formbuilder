<?php

namespace App\Jobs;

use App\Exceptions\AI\TransientAIServiceException;
use App\Models\AIJob;
use App\Services\AIFormGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateAIFormJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 30];

    public function __construct(
        public int $aiJobId,
    ) {}

    public function handle(AIFormGenerationService $generationService): void
    {
        $aiJob = AIJob::query()->find($this->aiJobId);

        if ($aiJob === null || in_array($aiJob->status, ['completed', 'failed'], true)) {
            return;
        }

        try {
            $generationService->processJob($aiJob);
        } catch (TransientAIServiceException $exception) {
            Log::warning('AI job transient failure.', [
                'ai_job_id' => $aiJob->id,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $aiJob = AIJob::query()->find($this->aiJobId);

        if ($aiJob === null || $aiJob->status === 'completed') {
            return;
        }

        $aiJob->update([
            'status' => 'failed',
            'error_message' => 'AI form generation failed.',
            'completed_at' => now(),
        ]);

        Log::error('AI job failed after retries.', [
            'ai_job_id' => $aiJob->id,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
