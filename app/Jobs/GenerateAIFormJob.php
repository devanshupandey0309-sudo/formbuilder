<?php

namespace App\Jobs;

use App\Exceptions\AI\PermanentAIServiceException;
use App\Exceptions\AI\TransientAIServiceException;
use App\Models\AIJob;
use App\Services\AIFormGenerationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GenerateAIFormJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 30];

    public int $uniqueFor = 3600;

    public function __construct(
        public int $aiJobId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->aiJobId;
    }

    public function handle(AIFormGenerationService $generationService): void
    {
        $aiJob = AIJob::query()->find($this->aiJobId);

        if ($aiJob === null || $aiJob->isTerminal()) {
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
        } catch (PermanentAIServiceException|RuntimeException $exception) {
            Log::warning('AI job permanent failure.', [
                'ai_job_id' => $aiJob->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $aiJob = AIJob::query()->find($this->aiJobId);

        if ($aiJob === null || $aiJob->status === AIJob::STATUS_COMPLETED) {
            return;
        }

        if ($aiJob->status === AIJob::STATUS_FAILED && filled($aiJob->error_message)) {
            return;
        }

        $aiJob->update([
            'status' => AIJob::STATUS_FAILED,
            'error_message' => filled($aiJob->error_message)
                ? $aiJob->error_message
                : ($exception?->getMessage() ?: 'AI form generation failed.'),
            'completed_at' => $aiJob->completed_at ?? now(),
        ]);

        Log::error('AI job failed after retries.', [
            'ai_job_id' => $aiJob->id,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
