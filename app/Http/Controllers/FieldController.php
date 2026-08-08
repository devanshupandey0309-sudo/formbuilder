<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReorderFieldsRequest;
use App\Http\Requests\StoreFieldRequest;
use App\Http\Requests\UpdateFieldRequest;
use App\Models\Field;
use App\Models\Form;
use App\Models\Section;
use App\Services\FieldService;
use Illuminate\Http\JsonResponse;

class FieldController extends Controller
{
    public function __construct(
        private readonly FieldService $fieldService,
    ) {}

    public function store(StoreFieldRequest $request, Form $form, Section $section): JsonResponse
    {
        $field = $this->fieldService->createField($form, $section, $request->validated());

        return $this->success('Field created successfully.', $field, 201);
    }

    public function update(UpdateFieldRequest $request, Form $form, Section $section, Field $field): JsonResponse
    {
        $field = $this->fieldService->updateField($field, $request->validated());

        return $this->success('Field updated successfully.', $field);
    }

    public function destroy(Form $form, Section $section, Field $field): JsonResponse
    {
        $this->authorize('update', $form);

        $this->fieldService->deleteField($field);

        return $this->success('Field deleted successfully.');
    }

    public function reorder(ReorderFieldsRequest $request, Form $form, Section $section): JsonResponse
    {
        $this->fieldService->reorderFields($section, $request->validated('field_ids'));

        $fields = $section->fields()->orderBy('sort_order')->get();

        return $this->success('Fields reordered successfully.', $fields);
    }

    private function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}
