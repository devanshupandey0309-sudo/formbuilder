<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FormSchemaValidator
{
    /**
     * @return array<string, mixed>
     */
    public function validateCompiledSchema(array $schema): array
    {
        if (blank($schema['title'] ?? null)) {
            throw ValidationException::withMessages([
                'title' => ['Form schema must include a title.'],
            ]);
        }

        if (! isset($schema['sections']) || ! is_array($schema['sections'])) {
            throw ValidationException::withMessages([
                'sections' => ['Form schema must include a sections array.'],
            ]);
        }

        $seenKeys = [];
        $normalizedSections = [];

        foreach ($schema['sections'] as $sectionIndex => $section) {
            if (! is_array($section)) {
                throw ValidationException::withMessages([
                    "sections.{$sectionIndex}" => ['Each section must be an object.'],
                ]);
            }

            if (blank($section['title'] ?? null)) {
                throw ValidationException::withMessages([
                    "sections.{$sectionIndex}.title" => ['Each section must include a title.'],
                ]);
            }

            if (! isset($section['fields']) || ! is_array($section['fields'])) {
                throw ValidationException::withMessages([
                    "sections.{$sectionIndex}.fields" => ['Each section must include a fields array.'],
                ]);
            }

            $normalizedFields = [];

            foreach ($section['fields'] as $fieldIndex => $field) {
                $normalizedFields[] = $this->validateField(
                    is_array($field) ? $field : [],
                    $sectionIndex,
                    $fieldIndex,
                    $seenKeys,
                );
            }

            $normalizedSections[] = [
                'title' => (string) $section['title'],
                'description' => $section['description'] ?? null,
                'fields' => $normalizedFields,
            ];
        }

        return [
            'title' => (string) $schema['title'],
            'description' => $schema['description'] ?? null,
            'sections' => $normalizedSections,
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $seenKeys
     * @return array<string, mixed>
     */
    private function validateField(array $field, int $sectionIndex, int $fieldIndex, array &$seenKeys): array
    {
        $path = "sections.{$sectionIndex}.fields.{$fieldIndex}";

        if (blank($field['key'] ?? null)) {
            throw ValidationException::withMessages([
                "{$path}.key" => ['Each field must include a key.'],
            ]);
        }

        if (blank($field['label'] ?? null)) {
            throw ValidationException::withMessages([
                "{$path}.label" => ['Each field must include a label.'],
            ]);
        }

        if (blank($field['type'] ?? null)) {
            throw ValidationException::withMessages([
                "{$path}.type" => ['Each field must include a type.'],
            ]);
        }

        $key = Str::snake((string) $field['key']);

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw ValidationException::withMessages([
                "{$path}.key" => ['Field key must start with a letter and contain only lowercase letters, numbers, and underscores.'],
            ]);
        }

        if (in_array($key, $seenKeys, true)) {
            throw ValidationException::withMessages([
                "{$path}.key" => ["Duplicate field key '{$key}' detected."],
            ]);
        }

        $seenKeys[] = $key;

        $type = (string) $field['type'];

        if (! in_array($type, FieldService::SUPPORTED_TYPES, true)) {
            throw ValidationException::withMessages([
                "{$path}.type" => ["Unsupported field type '{$type}'."],
            ]);
        }

        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];

        if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
            $options = $config['options'] ?? null;

            if (! is_array($options) || $options === []) {
                throw ValidationException::withMessages([
                    "{$path}.config.options" => ["Field type '{$type}' requires non-empty options."],
                ]);
            }
        }

        return [
            'key' => $key,
            'label' => (string) $field['label'],
            'type' => $type,
            'required' => (bool) ($field['required'] ?? false),
            'config' => $config === [] ? null : $config,
            'validation' => $validation === [] ? null : $validation,
        ];
    }
}
