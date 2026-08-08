<?php

namespace App\Services;

use App\Contracts\AIProvider;
use App\Exceptions\AI\TransientAIServiceException;
use App\Jobs\GenerateAIFormJob;
use App\Models\AIJob;
use App\Models\Form;
use App\Models\User;
use App\Services\AI\AIOutputValidator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class AIFormGenerationService
{
    public function __construct(
        private readonly AIProvider $provider,
        private readonly AIOutputValidator $validator,
        private readonly FormService $formService,
    ) {}

    public function queueGenerate(User $user, Form $form, string $prompt): AIJob
    {
        return $this->queueJob($user, $form, 'generate', $prompt, [
            'form_id' => $form->id,
            'operation' => 'generate',
        ]);
    }

    public function queueEdit(User $user, Form $form, string $prompt): AIJob
    {
        return $this->queueJob($user, $form, 'edit', $prompt, [
            'form_id' => $form->id,
            'operation' => 'edit',
            'current_schema' => $this->formService->compileSchema($form),
        ]);
    }

    public function getJob(Form $form, AIJob $aiJob): AIJob
    {
        if ($aiJob->form_id !== $form->id) {
            abort(404);
        }

        return $aiJob;
    }

    public function processJob(AIJob $aiJob): void
    {
        if (in_array($aiJob->status, ['completed', 'failed'], true)) {
            return;
        }

        $aiJob->update([
            'status' => 'processing',
            'started_at' => $aiJob->started_at ?? now(),
            'attempt_count' => $aiJob->attempt_count + 1,
        ]);

        $rawOutput = null;

        try {
            /** @var array<string, mixed> $input */
            $input = $aiJob->input ?? [];
            $operation = (string) ($input['operation'] ?? $aiJob->type);
            $currentSchema = $input['current_schema'] ?? null;

            $rawOutput = $this->provider->generateForm(
                $aiJob->prompt,
                is_array($currentSchema) ? $currentSchema : null,
                $operation,
            );

            $validatedOutput = $this->validator->validate($rawOutput);

            $aiJob->update([
                'status' => 'completed',
                'raw_output' => $rawOutput,
                'validated_output' => $validatedOutput,
                'error_message' => null,
                'completed_at' => now(),
            ]);
        } catch (ValidationException $exception) {
            $aiJob->update([
                'status' => 'failed',
                'raw_output' => is_array($rawOutput) ? $rawOutput : null,
                'validated_output' => null,
                'error_message' => $this->resolveErrorMessage($exception),
                'completed_at' => now(),
            ]);
        } catch (TransientAIServiceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Unexpected AI job processing failure.', [
                'ai_job_id' => $aiJob->id,
                'message' => $exception->getMessage(),
            ]);

            throw new TransientAIServiceException('AI service is unavailable.', 0, $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function queueJob(User $user, Form $form, string $type, string $prompt, array $input): AIJob
    {
        $aiJob = AIJob::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'type' => $type,
            'status' => 'pending',
            'prompt' => $prompt,
            'input' => $input,
        ]);

        GenerateAIFormJob::dispatch($aiJob->id);

        return $aiJob;
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
