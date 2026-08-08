<?php

namespace App\Services;

use App\Models\AIJob;
use App\Models\Form;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AIFormApplyService
{
    public function apply(Form $form, AIJob $aiJob): Form
    {
        if ($aiJob->form_id !== $form->id) {
            abort(404);
        }

        if ($aiJob->status !== 'completed') {
            throw ValidationException::withMessages([
                'ai_job' => ['Only completed AI jobs can be applied.'],
            ]);
        }

        if (empty($aiJob->validated_output)) {
            throw ValidationException::withMessages([
                'ai_job' => ['The AI job does not contain validated output to apply.'],
            ]);
        }

        /** @var array<string, mixed> $output */
        $output = $aiJob->validated_output;

        return DB::transaction(function () use ($form, $output) {
            $form->fields()->delete();
            $form->sections()->delete();

            $form->update([
                'title' => $output['title'],
                'description' => $output['description'] ?? null,
                'schema' => null,
                'status' => 'draft',
            ]);

            foreach ($output['sections'] as $sectionIndex => $sectionData) {
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
                        'validation' => null,
                        'is_required' => (bool) ($fieldData['required'] ?? false),
                    ]);
                }
            }

            return $form->fresh(['sections.fields']);
        });
    }
}
