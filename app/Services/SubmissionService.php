<?php

namespace App\Services;

use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SubmissionService
{
    /**
     * @throws ModelNotFoundException
     */
    public function getPublishedFormBySlug(string $slug): Form
    {
        $form = Form::query()->where('slug', $slug)->first();

        if ($form === null || $form->status !== 'published') {
            throw (new ModelNotFoundException)->setModel(Form::class, [$slug]);
        }

        if (empty($form->schema)) {
            throw ValidationException::withMessages([
                'form' => ['This form does not have a published schema available.'],
            ]);
        }

        return $form;
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function submit(Form $form, array $answers, ?string $ipAddress = null, ?string $userAgent = null): Submission
    {
        return DB::transaction(function () use ($form, $answers, $ipAddress, $userAgent) {
            /** @var array<string, mixed> $schema */
            $schema = $form->schema;

            $validatedAnswers = $this->validateAnswersAgainstSchema($schema, $answers);
            $fieldModels = $this->resolveFieldModels($form, array_keys($validatedAnswers));

            $submission = Submission::create([
                'form_id' => $form->id,
                'form_version' => $schema['version'] ?? $form->version,
                'schema_snapshot' => $schema,
                'status' => 'completed',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'submitted_at' => now(),
            ]);

            foreach ($validatedAnswers as $fieldKey => $normalizedValue) {
                $fieldDefinition = $this->flattenSchemaFields($schema)[$fieldKey];
                $fieldModel = $fieldModels[$fieldKey] ?? null;

                SubmissionAnswer::create([
                    'submission_id' => $submission->id,
                    'field_id' => $fieldModel?->id,
                    'field_key' => $fieldKey,
                    'field_label' => $fieldDefinition['label'] ?? null,
                    'value_text' => is_array($normalizedValue)
                        ? null
                        : $this->stringifyValue($normalizedValue),
                    'value_json' => is_array($normalizedValue) ? $normalizedValue : null,
                ]);
            }

            return $submission->fresh(['submissionAnswers']);
        });
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function validateAnswersAgainstSchema(array $schema, array $answers): array
    {
        $fields = $this->flattenSchemaFields($schema);
        $errors = [];

        $unknownKeys = array_diff(array_keys($answers), array_keys($fields));
        if ($unknownKeys !== []) {
            $errors['answers'] = ['Unknown field key(s): '.implode(', ', $unknownKeys).'.'];
        }

        foreach ($fields as $key => $field) {
            $isRequired = (bool) ($field['required'] ?? false);
            $hasValue = array_key_exists($key, $answers);

            if ($isRequired && ! $hasValue) {
                $errors["answers.{$key}"] = ["The {$key} field is required."];

                continue;
            }

            if (! $hasValue) {
                continue;
            }

            $fieldError = $this->validateFieldValue($key, $answers[$key], $field);

            if ($fieldError !== null) {
                $errors["answers.{$key}"] = [$fieldError];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $validated = [];

        foreach ($fields as $key => $field) {
            if (! array_key_exists($key, $answers)) {
                continue;
            }

            $validated[$key] = $this->normalizeFieldValue($answers[$key], $field);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, array<string, mixed>>
     */
    private function flattenSchemaFields(array $schema): array
    {
        $fields = [];

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (! empty($field['key'])) {
                    $fields[$field['key']] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function validateFieldValue(string $key, mixed $value, array $field): ?string
    {
        if ($this->isBlankValue($value)) {
            if ((bool) ($field['required'] ?? false)) {
                return "The {$key} field is required.";
            }

            return null;
        }

        return match ($field['type']) {
            'text', 'textarea' => is_string($value) || is_numeric($value)
                ? null
                : "The {$key} field must be a string.",
            'number' => is_numeric($value)
                ? null
                : "The {$key} field must be a number.",
            'email' => filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false
                ? null
                : "The {$key} field must be a valid email address.",
            'date' => Validator::make(
                ['value' => $value],
                ['value' => 'date_format:Y-m-d']
            )->passes()
                ? null
                : "The {$key} field must be a valid date (Y-m-d).",
            'select', 'radio' => $this->validateOptionValue($key, $value, $field),
            'checkbox' => $this->validateCheckboxValue($key, $value, $field),
            default => "The {$key} field has an unsupported type.",
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function validateOptionValue(string $key, mixed $value, array $field): ?string
    {
        $allowedValues = $this->getAllowedOptionValues($field);

        if ($allowedValues === []) {
            return "The {$key} field does not have any configured options.";
        }

        if (! in_array((string) $value, $allowedValues, true)) {
            return "The selected value for {$key} is invalid.";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function validateCheckboxValue(string $key, mixed $value, array $field): ?string
    {
        $allowedValues = $this->getAllowedOptionValues($field);

        if ($allowedValues === []) {
            return is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true)
                ? null
                : "The {$key} field must be a boolean value.";
        }

        $values = is_array($value) ? $value : [$value];

        foreach ($values as $item) {
            if (! in_array((string) $item, $allowedValues, true)) {
                return "One or more selected values for {$key} are invalid.";
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function normalizeFieldValue(mixed $value, array $field): mixed
    {
        if ($field['type'] === 'number') {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        if ($field['type'] === 'checkbox') {
            $allowedValues = $this->getAllowedOptionValues($field);

            if ($allowedValues === []) {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }

            return is_array($value) ? array_values($value) : $value;
        }

        return is_string($value) ? $value : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function getAllowedOptionValues(array $field): array
    {
        $options = $field['config']['options'] ?? [];
        $values = [];

        foreach ($options as $option) {
            if (is_array($option)) {
                $values[] = (string) ($option['value'] ?? $option['label'] ?? '');
            } else {
                $values[] = (string) $option;
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $fieldKeys
     * @return array<string, Field>
     */
    private function resolveFieldModels(Form $form, array $fieldKeys): array
    {
        return Field::query()
            ->where('form_id', $form->id)
            ->whereIn('key', $fieldKeys)
            ->get()
            ->keyBy('key')
            ->all();
    }

    private function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private function isBlankValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        return is_array($value) && $value === [];
    }
}
