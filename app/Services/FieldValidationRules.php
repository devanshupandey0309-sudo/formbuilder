<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FieldValidationRules
{
    /**
     * @return list<string>
     */
    public static function allowedKeysForType(string $type): array
    {
        return match ($type) {
            'email' => ['format', 'min_length', 'max_length'],
            'date' => ['format', 'min', 'max'],
            'number' => ['min', 'max'],
            'text', 'textarea' => ['min_length', 'max_length'],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultsForType(string $type): array
    {
        return match ($type) {
            'email' => ['format' => 'email'],
            'date' => ['format' => 'Y-m-d'],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultEditorValidationState(string $type): array
    {
        return match ($type) {
            'email' => [
                'validation_format_enabled' => true,
                'validation_min_length' => '',
                'validation_max_length' => '',
                'validation_min' => '',
                'validation_max' => '',
            ],
            'date' => [
                'validation_format_enabled' => true,
                'validation_min_length' => '',
                'validation_max_length' => '',
                'validation_min' => '',
                'validation_max' => '',
            ],
            'number' => [
                'validation_format_enabled' => false,
                'validation_min_length' => '',
                'validation_max_length' => '',
                'validation_min' => '',
                'validation_max' => '',
            ],
            'text', 'textarea' => [
                'validation_format_enabled' => false,
                'validation_min_length' => '',
                'validation_max_length' => '',
                'validation_min' => '',
                'validation_max' => '',
            ],
            default => [
                'validation_format_enabled' => false,
                'validation_min_length' => '',
                'validation_max_length' => '',
                'validation_min' => '',
                'validation_max' => '',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $validation
     * @return array<string, mixed>
     */
    public static function normalize(string $type, array $validation): array
    {
        $normalized = self::sanitizeForType($type, $validation);

        if ($normalized !== []) {
            return $normalized;
        }

        return self::defaultsForType($type);
    }

    /**
     * @param  array<string, mixed>  $validation
     * @return array<string, mixed>
     */
    public static function sanitizeForType(string $type, array $validation): array
    {
        $allowed = self::allowedKeysForType($type);
        $sanitized = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $validation)) {
                continue;
            }

            $value = $validation[$key];

            if ($value === null || $value === '') {
                continue;
            }

            $sanitized[$key] = match ($key) {
                'format' => (string) $value,
                'min', 'max' => is_numeric($value) ? $value + 0 : $value,
                'min_length', 'max_length' => (int) $value,
                default => $value,
            };
        }

        if ($type === 'email' && isset($sanitized['format']) && $sanitized['format'] !== 'email') {
            unset($sanitized['format']);
        }

        if ($type === 'date' && isset($sanitized['format']) && $sanitized['format'] !== 'Y-m-d') {
            unset($sanitized['format']);
        }

        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $validation
     */
    public static function assertSupported(string $type, array $validation, string $path): void
    {
        $allowed = self::allowedKeysForType($type);

        foreach ($validation as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages([
                    "{$path}.validation.{$key}" => ["Unsupported validation rule '{$key}' for field type '{$type}'."],
                ]);
            }
        }

        if ($type === 'email' && isset($validation['format']) && $validation['format'] !== 'email') {
            throw ValidationException::withMessages([
                "{$path}.validation.format" => ['Email fields only support format "email".'],
            ]);
        }

        if ($type === 'date' && isset($validation['format']) && $validation['format'] !== 'Y-m-d') {
            throw ValidationException::withMessages([
                "{$path}.validation.format" => ['Date fields only support format "Y-m-d".'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function editorStateFromField(string $type, ?array $validation): array
    {
        $validation = is_array($validation) ? $validation : [];
        $state = self::defaultEditorValidationState($type);

        if ($type === 'email') {
            $state['validation_format_enabled'] = ($validation['format'] ?? null) === 'email';
            $state['validation_min_length'] = $validation['min_length'] ?? '';
            $state['validation_max_length'] = $validation['max_length'] ?? '';
        }

        if ($type === 'date') {
            $state['validation_format_enabled'] = ($validation['format'] ?? null) === 'Y-m-d';
            $state['validation_min'] = $validation['min'] ?? '';
            $state['validation_max'] = $validation['max'] ?? '';
        }

        if ($type === 'number') {
            $state['validation_min'] = $validation['min'] ?? '';
            $state['validation_max'] = $validation['max'] ?? '';
        }

        if (in_array($type, ['text', 'textarea'], true)) {
            $state['validation_min_length'] = $validation['min_length'] ?? '';
            $state['validation_max_length'] = $validation['max_length'] ?? '';
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $editor
     * @return array<string, mixed>
     */
    public static function validationFromEditor(array $editor): array
    {
        $type = (string) ($editor['type'] ?? 'text');
        $validation = [];

        if ($type === 'email') {
            if ((bool) ($editor['validation_format_enabled'] ?? false)) {
                $validation['format'] = 'email';
            }

            if (($editor['validation_min_length'] ?? '') !== '') {
                $validation['min_length'] = (int) $editor['validation_min_length'];
            }

            if (($editor['validation_max_length'] ?? '') !== '') {
                $validation['max_length'] = (int) $editor['validation_max_length'];
            }
        }

        if ($type === 'date') {
            if ((bool) ($editor['validation_format_enabled'] ?? false)) {
                $validation['format'] = 'Y-m-d';
            }

            if (($editor['validation_min'] ?? '') !== '') {
                $validation['min'] = (string) $editor['validation_min'];
            }

            if (($editor['validation_max'] ?? '') !== '') {
                $validation['max'] = (string) $editor['validation_max'];
            }
        }

        if ($type === 'number') {
            if (($editor['validation_min'] ?? '') !== '') {
                $validation['min'] = $editor['validation_min'] + 0;
            }

            if (($editor['validation_max'] ?? '') !== '') {
                $validation['max'] = $editor['validation_max'] + 0;
            }
        }

        if (in_array($type, ['text', 'textarea'], true)) {
            if (($editor['validation_min_length'] ?? '') !== '') {
                $validation['min_length'] = (int) $editor['validation_min_length'];
            }

            if (($editor['validation_max_length'] ?? '') !== '') {
                $validation['max_length'] = (int) $editor['validation_max_length'];
            }
        }

        return self::normalize($type, $validation);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public static function validateSubmissionValue(string $key, mixed $value, array $field): ?string
    {
        $type = (string) ($field['type'] ?? 'text');
        $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];
        $validation = self::normalize($type, $validation);
        $label = (string) ($field['label'] ?? $key);

        $baseError = match ($type) {
            'text', 'textarea' => is_string($value) || is_numeric($value)
                ? null
                : "The {$label} field must be a string.",
            'number' => is_numeric($value)
                ? null
                : "The {$label} field must be a number.",
            'email' => filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false
                ? null
                : "The {$label} field must be a valid email address.",
            'date' => self::validateDateFormat((string) $value, $validation['format'] ?? 'Y-m-d')
                ? null
                : "The {$label} field must be a valid date (Y-m-d).",
            default => null,
        };

        if ($baseError !== null) {
            return $baseError;
        }

        if (in_array($type, ['text', 'textarea'], true)) {
            $length = mb_strlen((string) $value);

            if (isset($validation['min_length']) && $length < (int) $validation['min_length']) {
                return "The {$label} field must be at least {$validation['min_length']} characters.";
            }

            if (isset($validation['max_length']) && $length > (int) $validation['max_length']) {
                return "The {$label} field must not exceed {$validation['max_length']} characters.";
            }
        }

        if ($type === 'number' && is_numeric($value)) {
            $numericValue = $value + 0;

            if (isset($validation['min']) && $numericValue < $validation['min']) {
                return "The {$label} field must be at least {$validation['min']}.";
            }

            if (isset($validation['max']) && $numericValue > $validation['max']) {
                return "The {$label} field must not exceed {$validation['max']}.";
            }
        }

        if ($type === 'date') {
            if (isset($validation['min']) && strcmp((string) $value, (string) $validation['min']) < 0) {
                return "The {$label} field must be on or after {$validation['min']}.";
            }

            if (isset($validation['max']) && strcmp((string) $value, (string) $validation['max']) > 0) {
                return "The {$label} field must be on or before {$validation['max']}.";
            }
        }

        if ($type === 'email') {
            $length = mb_strlen((string) $value);

            if (isset($validation['min_length']) && $length < (int) $validation['min_length']) {
                return "The {$label} field must be at least {$validation['min_length']} characters.";
            }

            if (isset($validation['max_length']) && $length > (int) $validation['max_length']) {
                return "The {$label} field must not exceed {$validation['max_length']} characters.";
            }
        }

        return null;
    }

    private static function validateDateFormat(string $value, string $format): bool
    {
        return Validator::make(
            ['value' => $value],
            ['value' => 'date_format:'.$format],
        )->passes();
    }
}
