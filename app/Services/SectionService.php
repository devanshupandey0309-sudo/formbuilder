<?php

namespace App\Services;

use App\Models\Form;
use App\Models\Section;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SectionService
{
    public function createSection(Form $form, array $data): Section
    {
        return DB::transaction(function () use ($form, $data) {
            $maxSortOrder = $form->sections()->max('sort_order');
            $sortOrder = $maxSortOrder === null ? 0 : $maxSortOrder + 1;

            $section = $form->sections()->create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'sort_order' => $sortOrder,
                'settings' => $data['settings'] ?? null,
            ]);

            $this->markFormSchemaStale($form);

            return $section;
        });
    }

    public function updateSection(Section $section, array $data): Section
    {
        $section->update([
            'title' => $data['title'] ?? $section->title,
            'description' => array_key_exists('description', $data)
                ? $data['description']
                : $section->description,
            'settings' => array_key_exists('settings', $data)
                ? $data['settings']
                : $section->settings,
        ]);

        $this->markFormSchemaStale($section->form);

        return $section->fresh();
    }

    public function deleteSection(Section $section): void
    {
        DB::transaction(function () use ($section) {
            $form = $section->form;
            $section->delete();
            $this->markFormSchemaStale($form);
        });
    }

    /**
     * @param  list<int>  $sectionIds
     */
    public function reorderSections(Form $form, array $sectionIds): void
    {
        DB::transaction(function () use ($form, $sectionIds) {
            $this->assertSectionIdsBelongToForm($form, $sectionIds);

            foreach ($sectionIds as $index => $sectionId) {
                Section::query()
                    ->where('form_id', $form->id)
                    ->whereKey($sectionId)
                    ->update(['sort_order' => $index]);
            }

            $this->markFormSchemaStale($form);
        });
    }

    /**
     * @param  list<int>  $sectionIds
     */
    private function assertSectionIdsBelongToForm(Form $form, array $sectionIds): void
    {
        if (count($sectionIds) !== count(array_unique($sectionIds))) {
            throw ValidationException::withMessages([
                'section_ids' => ['Section IDs must not contain duplicates.'],
            ]);
        }

        $formSectionCount = $form->sections()->count();

        if (count($sectionIds) !== $formSectionCount) {
            throw ValidationException::withMessages([
                'section_ids' => ['All sections for this form must be included in the reorder list.'],
            ]);
        }

        $matchingCount = $form->sections()->whereIn('id', $sectionIds)->count();

        if ($matchingCount !== count($sectionIds)) {
            throw ValidationException::withMessages([
                'section_ids' => ['One or more sections do not belong to this form.'],
            ]);
        }
    }

    private function markFormSchemaStale(Form $form): void
    {
        if ($form->schema !== null) {
            $form->update(['schema' => null]);
        }
    }
}
