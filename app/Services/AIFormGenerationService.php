<?php

namespace App\Services;

use App\Contracts\AIProvider;
use App\Models\AIJob;
use App\Models\Form;
use App\Models\User;
use App\Services\AI\AIOutputValidator;
use Throwable;

class AIFormGenerationService
{
    public function __construct(
        private readonly AIProvider $provider,
        private readonly AIOutputValidator $validator,
    ) {}

    public function generate(User $user, Form $form, string $prompt): AIJob
    {
        $aiJob = AIJob::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'type' => 'generate',
            'status' => 'pending',
            'prompt' => $prompt,
            'input' => [
                'form_id' => $form->id,
            ],
        ]);

        return $this->processJob($aiJob);
    }

    public function getJob(Form $form, AIJob $aiJob): AIJob
    {
        if ($aiJob->form_id !== $form->id) {
            abort(404);
        }

        return $aiJob;
    }

    private function processJob(AIJob $aiJob): AIJob
    {
        $aiJob->update([
            'status' => 'processing',
            'started_at' => now(),
            'attempt_count' => $aiJob->attempt_count + 1,
        ]);

        $rawOutput = null;

        try {
            $rawOutput = $this->provider->generateForm($aiJob->prompt);
            $validatedOutput = $this->validator->validate($rawOutput);

            $aiJob->update([
                'status' => 'completed',
                'raw_output' => $rawOutput,
                'validated_output' => $validatedOutput,
                'error_message' => null,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $aiJob->update([
                'status' => 'failed',
                'raw_output' => is_array($rawOutput) ? $rawOutput : null,
                'validated_output' => null,
                'error_message' => $this->resolveErrorMessage($exception),
                'completed_at' => now(),
            ]);
        }

        return $aiJob->fresh();
    }

    private function resolveErrorMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return collect($exception->errors())->flatten()->first()
                ?? 'Generated form output failed validation.';
        }

        return 'AI form generation failed.';
    }
}
