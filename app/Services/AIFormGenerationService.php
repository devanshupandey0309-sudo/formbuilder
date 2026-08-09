<?php

namespace App\Services;

use App\Contracts\AIProvider;
use App\Exceptions\AI\PermanentAIServiceException;
use App\Exceptions\AI\TransientAIServiceException;
use App\Jobs\GenerateAIFormJob;
use App\Models\AIJob;
use App\Models\Form;
use App\Models\User;
use App\Services\AI\AIOutputValidator;
use App\Services\AI\AIResponseParser;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AIFormGenerationService
{
    public function __construct(
        private readonly AIProvider $provider,
        private readonly AIOutputValidator $validator,
        private readonly AIResponseParser $responseParser,
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
        $aiJob->refresh();

        if ($aiJob->isTerminal()) {
            return;
        }

        $updated = AIJob::query()
            ->whereKey($aiJob->id)
            ->whereNotIn('status', AIJob::terminalStatuses())
            ->update([
                'status' => AIJob::STATUS_PROCESSING,
                'started_at' => $aiJob->started_at ?? now(),
                'attempt_count' => $aiJob->attempt_count + 1,
            ]);

        if ($updated === 0) {
            return;
        }

        $aiJob->refresh();
        $rawOutput = null;

        try {
            /** @var array<string, mixed> $input */
            $input = $aiJob->input ?? [];
            $operation = (string) ($input['operation'] ?? $aiJob->type);
            $currentSchema = $input['current_schema'] ?? null;

            $providerOutput = $this->provider->generateForm(
                $aiJob->prompt,
                is_array($currentSchema) ? $currentSchema : null,
                $operation,
            );

            $rawOutput = $this->responseParser->parse($providerOutput);
            $validatedOutput = $this->validator->validate($rawOutput);

            $aiJob->update([
                'status' => AIJob::STATUS_COMPLETED,
                'raw_output' => $rawOutput,
                'validated_output' => $validatedOutput,
                'error_message' => null,
                'completed_at' => now(),
            ]);
        } catch (TransientAIServiceException $exception) {
            throw $exception;
        } catch (ValidationException|PermanentAIServiceException|RuntimeException $exception) {
            $this->markJobFailed($aiJob, $rawOutput, $this->resolveErrorMessage($exception));
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
            'status' => AIJob::STATUS_PENDING,
            'prompt' => $prompt,
            'input' => $input,
        ]);

        GenerateAIFormJob::dispatch($aiJob->id);

        return $aiJob;
    }

    /**
     * @param  array<string, mixed>|null  $rawOutput
     */
    private function markJobFailed(AIJob $aiJob, ?array $rawOutput, string $errorMessage): void
    {
        $aiJob->update([
            'status' => AIJob::STATUS_FAILED,
            'raw_output' => is_array($rawOutput) ? $rawOutput : null,
            'validated_output' => null,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    private function resolveErrorMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return collect($exception->errors())->flatten()->first()
                ?? 'Generated form output failed validation.';
        }

        if ($exception instanceof PermanentAIServiceException || $exception instanceof RuntimeException) {
            return $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'AI form generation failed.';
        }

        return 'AI form generation failed.';
    }
}
