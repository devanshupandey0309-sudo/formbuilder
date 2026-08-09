<?php

namespace App\Http\Controllers;

use App\Http\Requests\EditFormAIRequest;
use App\Http\Requests\GenerateFormAIRequest;
use App\Models\AIJob;
use App\Models\Form;
use App\Services\AIFormApplyService;
use App\Services\AIFormGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AIFormController extends Controller
{
    public function __construct(
        private readonly AIFormGenerationService $generationService,
        private readonly AIFormApplyService $applyService,
    ) {}

    public function generate(GenerateFormAIRequest $request, Form $form): JsonResponse
    {
        $aiJob = $this->generationService->queueGenerate(
            $request->user(),
            $form,
            $request->validated('prompt'),
        );

        return $this->apiSuccess(
            'AI form generation queued successfully.',
            $this->formatJobResponse($aiJob),
            202,
        );
    }

    public function edit(EditFormAIRequest $request, Form $form): JsonResponse
    {
        $aiJob = $this->generationService->queueEdit(
            $request->user(),
            $form,
            $request->validated('prompt'),
        );

        return $this->apiSuccess(
            'AI form edit queued successfully.',
            $this->formatJobResponse($aiJob),
            202,
        );
    }

    public function show(Form $form, AIJob $aiJob): JsonResponse
    {
        $this->authorize('view', $form);

        $aiJob = $this->generationService->getJob($form, $aiJob);

        return $this->apiSuccess('AI job retrieved successfully.', $this->formatJobResponse($aiJob));
    }

    public function apply(Form $form, AIJob $aiJob): JsonResponse
    {
        $this->authorize('update', $form);

        try {
            $form = $this->applyService->apply($form, $aiJob);
        } catch (ValidationException $exception) {
            return $this->apiError(
                'AI job cannot be applied.',
                422,
                ['errors' => $exception->errors()],
            );
        }

        return $this->apiSuccess('Generated form applied successfully.', $form);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatJobResponse(AIJob $aiJob): array
    {
        return [
            'ai_job' => [
                'id' => $aiJob->id,
                'form_id' => $aiJob->form_id,
                'type' => $aiJob->type,
                'status' => $aiJob->status,
                'prompt' => $aiJob->prompt,
                'error_message' => $aiJob->error_message,
                'attempt_count' => $aiJob->attempt_count,
                'started_at' => $aiJob->started_at,
                'completed_at' => $aiJob->completed_at,
                'applied_at' => $aiJob->applied_at,
                'created_at' => $aiJob->created_at,
            ],
            'generated_form' => $aiJob->validated_output,
        ];
    }
}
