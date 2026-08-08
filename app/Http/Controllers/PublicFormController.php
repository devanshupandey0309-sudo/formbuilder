<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitFormRequest;
use App\Services\SubmissionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class PublicFormController extends Controller
{
    public function __construct(
        private readonly SubmissionService $submissionService,
    ) {}

    public function show(string $slug): JsonResponse
    {
        try {
            $form = $this->submissionService->getPublishedFormBySlug($slug);
        } catch (ModelNotFoundException) {
            return $this->error('Form not found.', 404);
        } catch (ValidationException $exception) {
            return $this->error(
                $exception->getMessage(),
                422,
                $exception->errors(),
            );
        }

        return $this->success('Form retrieved successfully.', [
            'slug' => $form->slug,
            'title' => $form->schema['title'] ?? $form->title,
            'description' => $form->schema['description'] ?? $form->description,
            'version' => $form->schema['version'] ?? $form->version,
            'published_at' => $form->published_at,
            'schema' => $form->schema,
        ]);
    }

    public function submit(SubmitFormRequest $request, string $slug): JsonResponse
    {
        try {
            $form = $this->submissionService->getPublishedFormBySlug($slug);

            $submission = $this->submissionService->submit(
                $form,
                $request->validated('answers'),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (ModelNotFoundException) {
            return $this->error('Form not found.', 404);
        } catch (ValidationException $exception) {
            return $this->error(
                'Submission validation failed.',
                422,
                ['errors' => $exception->errors()],
            );
        }

        return $this->success('Submission created successfully.', [
            'submission_id' => $submission->id,
        ], 201);
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
