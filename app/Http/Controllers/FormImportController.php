<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFormImportRequest;
use App\Http\Resources\FormImportResource;
use App\Models\Form;
use App\Models\FormImport;
use App\Services\FormImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class FormImportController extends Controller
{
    public function __construct(
        private readonly FormImportService $formImportService,
    ) {}

    public function store(StoreFormImportRequest $request, Form $form): JsonResponse
    {
        $import = $this->formImportService->createImport(
            $request->user(),
            $form,
            $request->file('file'),
        );

        if ($import->status === 'failed') {
            return $this->apiError(
                'Form import failed.',
                422,
                ['form_import' => $this->formatImportResponse($import)],
            );
        }

        return $this->apiSuccess(
            'Form import processed successfully.',
            ['form_import' => $this->formatImportResponse($import)],
            201,
        );
    }

    public function show(Form $form, FormImport $formImport): JsonResponse
    {
        $this->authorize('view', $form);

        $formImport = $this->formImportService->getImport($form, $formImport);

        return $this->apiSuccess('Form import retrieved successfully.', [
            'form_import' => $this->formatImportResponse($formImport),
        ]);
    }

    public function preview(Form $form, FormImport $formImport): JsonResponse
    {
        $this->authorize('view', $form);

        try {
            $preview = $this->formImportService->getPreview($form, $formImport);
        } catch (ValidationException $exception) {
            return $this->apiError(
                'Import preview is not available.',
                422,
                ['errors' => $exception->errors()],
            );
        }

        return $this->apiSuccess('Import preview retrieved successfully.', [
            'form_import' => $this->formatImportResponse($preview['form_import']),
            'preview' => $preview['preview'],
        ]);
    }

    public function commit(Form $form, FormImport $formImport): JsonResponse
    {
        $this->authorize('update', $form);

        try {
            $form = $this->formImportService->commit($form, $formImport);
        } catch (ValidationException $exception) {
            return $this->apiError(
                'Form import cannot be committed.',
                422,
                ['errors' => $exception->errors()],
            );
        }

        return $this->apiSuccess('Form import committed successfully.', $form);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatImportResponse(FormImport $formImport): array
    {
        return (new FormImportResource($formImport))->resolve();
    }
}
