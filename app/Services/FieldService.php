<?php

namespace App\Services;

use App\Models\Field;
use App\Models\Form;
use App\Models\Section;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldService
{
    public const SUPPORTED_TYPES = [
        'text',
        'textarea',
        'number',
        'email',
        'date',
        'select',
        'radio',
        'checkbox',
    ];

    public function createField(Form $form, Section $section, array $data): Field
    {
        return DB::transaction(function () use ($form, $section, $data) {
            $maxSortOrder = $section->fields()->max('sort_order');
            $sortOrder = $maxSortOrder === null ? 0 : $maxSortOrder + 1;

            $field = $section->fields()->create([
                'form_id' => $form->id,
                'key' => $data['key'],
                'label' => $data['label'],
                'type' => $data['type'],
                'sort_order' => $sortOrder,
                'config' => $this->buildConfig($data),
                'validation' => $data['validation'] ?? null,
                'is_required' => $data['is_required'] ?? false,
            ]);

            $this->markFormSchemaStale($form);

            return $field;
        });
    }

    public function updateField(Field $field, array $data): Field
    {
        $updates = [
            'label' => $data['label'] ?? $field->label,
            'type' => $data['type'] ?? $field->type,
            'is_required' => array_key_exists('is_required', $data)
                ? $data['is_required']
                : $field->is_required,
        ];

        if (array_key_exists('key', $data)) {
            $updates['key'] = $data['key'];
        }

        if (array_key_exists('validation', $data)) {
            $updates['validation'] = $data['validation'];
        }

        if (array_key_exists('config', $data) || array_key_exists('placeholder', $data)) {
            $updates['config'] = $this->buildConfig($data, $field->config ?? []);
        }

        $field->update($updates);

        $this->markFormSchemaStale($field->form);

        return $field->fresh();
    }

    public function deleteField(Field $field): void
    {
        DB::transaction(function () use ($field) {
            $form = $field->form;
            $field->delete();
            $this->markFormSchemaStale($form);
        });
    }

    /**
     * @param  list<int>  $fieldIds
     */
    public function reorderFields(Section $section, array $fieldIds): void
    {
        DB::transaction(function () use ($section, $fieldIds) {
            $this->assertFieldIdsBelongToSection($section, $fieldIds);

            foreach ($fieldIds as $index => $fieldId) {
                Field::query()
                    ->where('section_id', $section->id)
                    ->whereKey($fieldId)
                    ->update(['sort_order' => $index]);
            }

            $this->markFormSchemaStale($section->form);
        });
    }

    /**
     * @param  list<int>  $fieldIds
     */
    private function assertFieldIdsBelongToSection(Section $section, array $fieldIds): void
    {
        if (count($fieldIds) !== count(array_unique($fieldIds))) {
            throw ValidationException::withMessages([
                'field_ids' => ['Field IDs must not contain duplicates.'],
            ]);
        }

        $sectionFieldCount = $section->fields()->count();

        if (count($fieldIds) !== $sectionFieldCount) {
            throw ValidationException::withMessages([
                'field_ids' => ['All fields for this section must be included in the reorder list.'],
            ]);
        }

        $matchingCount = $section->fields()->whereIn('id', $fieldIds)->count();

        if ($matchingCount !== count($fieldIds)) {
            throw ValidationException::withMessages([
                'field_ids' => ['One or more fields do not belong to this section.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>|null
     */
    private function buildConfig(array $data, array $existing = []): ?array
    {
        $config = array_key_exists('config', $data)
            ? ($data['config'] ?? [])
            : $existing;

        if (array_key_exists('placeholder', $data)) {
            if ($data['placeholder'] === null || $data['placeholder'] === '') {
                unset($config['placeholder']);
            } else {
                $config['placeholder'] = $data['placeholder'];
            }
        }

        return $config === [] ? null : $config;
    }

    private function markFormSchemaStale(Form $form): void
    {
        if ($form->schema !== null) {
            $form->update(['schema' => null]);
        }
    }
}
