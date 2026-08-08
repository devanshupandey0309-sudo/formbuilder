<?php

namespace App\Services;

use App\Models\Form;
use Illuminate\Support\Facades\DB;

class FormStructureApplyService
{
    /**
     * @param  array<string, mixed>  $structure
     */
    public function apply(Form $form, array $structure): Form
    {
        return DB::transaction(function () use ($form, $structure) {
            $form->fields()->delete();
            $form->sections()->delete();

            $form->update([
                'title' => $structure['title'],
                'description' => $structure['description'] ?? null,
                'schema' => null,
                'status' => 'draft',
            ]);

            foreach ($structure['sections'] as $sectionIndex => $sectionData) {
                $section = $form->sections()->create([
                    'title' => $sectionData['title'],
                    'description' => $sectionData['description'] ?? null,
                    'sort_order' => $sectionIndex,
                    'settings' => null,
                ]);

                foreach ($sectionData['fields'] as $fieldIndex => $fieldData) {
                    $form->fields()->create([
                        'section_id' => $section->id,
                        'key' => $fieldData['key'],
                        'label' => $fieldData['label'],
                        'type' => $fieldData['type'],
                        'sort_order' => $fieldIndex,
                        'config' => $fieldData['config'] ?? null,
                        'validation' => $fieldData['validation'] ?? null,
                        'is_required' => (bool) ($fieldData['required'] ?? false),
                    ]);
                }
            }

            return $form->fresh(['sections.fields']);
        });
    }
}
