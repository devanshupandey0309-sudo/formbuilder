<?php

namespace App\Http\Controllers;

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
        $aiJob = $this->generationService->generate(
            $request->user(),
            $form,
            $request->validated('prompt'),
        );

        if ($aiJob->status === 'failed') {
            return $this->error(
                'AI form generation failed.',
                422,
                $this->formatJobResponse($aiJob),
            );
        }

        return $this->success(
            'AI form generation completed successfully.',
            $this->formatJobResponse($aiJob),
            201,
        );
    }

    public function show(Form $form, AIJob $aiJob): JsonResponse
    {
        $this->authorize('view', $form);

        $aiJob = $this->generationService->getJob($form, $aiJob);

        return $this->success('AI job retrieved successfully.', $this->formatJobResponse($aiJob));
    }

    public function apply(Form $form, AIJob $aiJob): JsonResponse
    {
        $this->authorize('update', $form);

        try {
            $form = $this->applyService->apply($form, $aiJob);
        } catch (ValidationException $exception) {
            return $this->error(
                'AI job cannot be applied.',
                422,
                ['errors' => $exception->errors()],
            );
        }

        return $this->success('Generated form applied successfully.', $form);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatJobResponse(AIJob $aiJob): array
    {
        return [
            'ai_job' => $aiJob,
            'generated_form' => $aiJob->validated_output,
        ];
    }

    private function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function error(string $message, int $status, ?array $data = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }
}
