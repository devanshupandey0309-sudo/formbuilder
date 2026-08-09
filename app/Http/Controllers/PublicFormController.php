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
            return $this->apiError('Form not found.', 404);
        } catch (ValidationException $exception) {
            return $this->apiError(
                'Form not available.',
                422,
                ['errors' => $exception->errors()],
            );
        }

        return $this->apiSuccess('Form retrieved successfully.', [
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
            return $this->apiError('Form not found.', 404);
        } catch (ValidationException $exception) {
            return $this->apiError(
                'Submission validation failed.',
                422,
                ['errors' => $exception->errors()],
            );
        }

        return $this->apiSuccess('Submission created successfully.', [
            'submission_id' => $submission->id,
        ], 201);
    }
}
