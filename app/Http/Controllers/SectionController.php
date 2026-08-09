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

        return $this->apiSuccess('Section created successfully.', $section, 201);
    }

    public function update(UpdateSectionRequest $request, Form $form, Section $section): JsonResponse
    {
        $section = $this->sectionService->updateSection($form, $section, $request->validated());

        return $this->apiSuccess('Section updated successfully.', $section);
    }

    public function destroy(Form $form, Section $section): JsonResponse
    {
        $this->authorize('update', $form);

        $this->sectionService->deleteSection($form, $section);

        return $this->apiSuccess('Section deleted successfully.');
    }

    public function reorder(ReorderSectionsRequest $request, Form $form): JsonResponse
    {
        $this->sectionService->reorderSections($form, $request->validated('section_ids'));

        $sections = $form->sections()->orderBy('sort_order')->get();

        return $this->apiSuccess('Sections reordered successfully.', $sections);
    }
}
