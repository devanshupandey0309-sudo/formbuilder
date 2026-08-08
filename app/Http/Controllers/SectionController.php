<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReorderSectionsRequest;
use App\Http\Requests\StoreSectionRequest;
use App\Http\Requests\UpdateSectionRequest;
use App\Models\Form;
use App\Models\Section;
use App\Services\SectionService;
use Illuminate\Http\JsonResponse;

class SectionController extends Controller
{
    public function __construct(
        private readonly SectionService $sectionService,
    ) {}

    public function store(StoreSectionRequest $request, Form $form): JsonResponse
    {
        $section = $this->sectionService->createSection($form, $request->validated());

        return $this->success('Section created successfully.', $section, 201);
    }

    public function update(UpdateSectionRequest $request, Form $form, Section $section): JsonResponse
    {
        $section = $this->sectionService->updateSection($section, $request->validated());

        return $this->success('Section updated successfully.', $section);
    }

    public function destroy(Form $form, Section $section): JsonResponse
    {
        $this->authorize('update', $form);

        $this->sectionService->deleteSection($section);

        return $this->success('Section deleted successfully.');
    }

    public function reorder(ReorderSectionsRequest $request, Form $form): JsonResponse
    {
        $this->sectionService->reorderSections($form, $request->validated('section_ids'));

        $sections = $form->sections()->orderBy('sort_order')->get();

        return $this->success('Sections reordered successfully.', $sections);
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
