<?php

namespace App\Services;

use App\Models\Field;
use App\Models\Form;
use App\Models\Section;
use Illuminate\Support\Collection;

class FormHealthService
{
    private const CATEGORY_STRUCTURE = 'structure';

    private const CATEGORY_FIELDS = 'fields';

    private const CATEGORY_VALIDATION = 'validation';

    private const CATEGORY_REQUIRED = 'required';

    private const CATEGORY_USABILITY = 'usability';

    private const LARGE_SECTION_FIELD_COUNT = 15;

    private const HIGH_REQUIRED_RATIO = 0.7;

    /** @var list<string> */
    private const PLACEHOLDER_USEFUL_TYPES = ['text', 'textarea', 'email', 'number'];

    /** @var list<string> */
    private const OPTION_TYPES = ['select', 'radio', 'checkbox'];

    /** @var list<string> */
    private const GENERIC_LABELS = [
        'new field',
        'field',
        'untitled',
        'untitled field',
        'label',
    ];

    /**
     * @return array{
     *     score: int,
     *     grade: string,
     *     summary: string,
     *     categories: list<array{key: string, label: string, score: int, max: int}>,
     *     issues: list<array{severity: string, code: string, field_key: ?string, section_id: ?int, section_title: ?string, message: string}>,
     *     suggestions: list<string>
     * }
     */
    public function analyze(Form $form): array
    {
        $form->unsetRelation('sections');
        $form->load([
            'sections' => fn ($query) => $query->orderBy('sort_order'),
            'sections.fields' => fn ($query) => $query->orderBy('sort_order'),
        ]);

        /** @var Collection<int, Section> $sections */
        $sections = $form->sections;
        /** @var Collection<int, Field> $fields */
        $fields = $sections->flatMap(fn (Section $section) => $section->fields);

        $issues = [];
        $suggestions = [];

        $structureScore = $this->scoreStructure($form, $sections, $issues, $suggestions);
        $fieldScore = $this->scoreFieldConfiguration($fields, $issues, $suggestions);
        $validationScore = $this->scoreValidation($fields, $issues, $suggestions);
        $requiredScore = $this->scoreRequiredFieldQuality($fields, $issues, $suggestions);
        $usabilityScore = $this->scoreUsability($sections, $fields, $issues, $suggestions);

        $categories = [
            $this->category(self::CATEGORY_STRUCTURE, 'Structure', $structureScore, 20),
            $this->category(self::CATEGORY_FIELDS, 'Field Configuration', $fieldScore, 25),
            $this->category(self::CATEGORY_VALIDATION, 'Validation', $validationScore, 25),
            $this->category(self::CATEGORY_REQUIRED, 'Required Fields', $requiredScore, 15),
            $this->category(self::CATEGORY_USABILITY, 'Usability', $usabilityScore, 15),
        ];

        $score = $this->clamp(
            collect($categories)->sum(fn (array $category) => $category['score']),
            0,
            100,
        );

        $grade = $this->gradeForScore($score);

        return [
            'score' => $score,
            'grade' => $grade,
            'summary' => $this->summaryForGrade($grade, $issues),
            'categories' => $categories,
            'issues' => $issues,
            'suggestions' => array_values(array_unique($suggestions)),
        ];
    }

    /**
     * @param  Collection<int, Section>  $sections
     * @param  list<array<string, mixed>>  $issues
     * @param  list<string>  $suggestions
     */
    private function scoreStructure(Form $form, Collection $sections, array &$issues, array &$suggestions): int
    {
        $score = 20;

        if (blank(trim((string) $form->title))) {
            $score -= 10;
            $this->addIssue($issues, 'error', 'missing_title', 'Form is missing a title.', suggestions: $suggestions, suggestion: 'Add a descriptive form title.');
        }

        if ($sections->isEmpty()) {
            $score -= 10;
            $this->addIssue($issues, 'error', 'missing_sections', 'Form has no sections.', suggestions: $suggestions, suggestion: 'Add at least one section to organize fields.');
        }

        foreach ($sections as $section) {
            if (blank(trim((string) $section->title))) {
                $score -= 2;
                $this->addIssue(
                    $issues,
                    'error',
                    'empty_section_title',
                    'A section is missing a title.',
                    section: $section,
                    suggestions: $suggestions,
                    suggestion: 'Give every section a clear title.',
                );
            }

            if ($section->fields->isEmpty()) {
                $score -= 3;
                $this->addIssue(
                    $issues,
                    'error',
                    'empty_section',
                    sprintf('Section "%s" has no fields.', $section->title ?: 'Untitled'),
                    section: $section,
                    suggestions: $suggestions,
                    suggestion: sprintf('Add fields to "%s" or remove the empty section.', $section->title ?: 'Untitled'),
                );
            }
        }

        if ($sections->isNotEmpty() && ! $this->hasSequentialOrdering($sections->pluck('sort_order')->all())) {
            $score -= 1;
            $this->addIssue(
                $issues,
                'warning',
                'invalid_section_order',
                'Section ordering has gaps or duplicates.',
                suggestions: $suggestions,
                suggestion: 'Reorder sections so they follow a consistent sequence.',
            );
        }

        foreach ($sections as $section) {
            if ($section->fields->isNotEmpty()
                && ! $this->hasSequentialOrdering($section->fields->pluck('sort_order')->all())) {
                $score -= 1;
                $this->addIssue(
                    $issues,
                    'warning',
                    'invalid_field_order',
                    sprintf('Field ordering in "%s" has gaps or duplicates.', $section->title ?: 'Untitled'),
                    section: $section,
                    suggestions: $suggestions,
                    suggestion: sprintf('Reorder fields in "%s".', $section->title ?: 'Untitled'),
                );
            }
        }

        return $this->clamp($score, 0, 20);
    }

    /**
     * @param  Collection<int, Field>  $fields
     * @param  list<array<string, mixed>>  $issues
     * @param  list<string>  $suggestions
     */
    private function scoreFieldConfiguration(Collection $fields, array &$issues, array &$suggestions): int
    {
        $score = 25;

        if ($fields->isEmpty()) {
            return 0;
        }

        $keyCounts = $fields->pluck('key')->countBy();

        foreach ($fields as $field) {
            if (blank($field->key)) {
                $score -= 3;
                $this->addIssue(
                    $issues,
                    'error',
                    'missing_field_key',
                    sprintf('Field "%s" is missing a key.', $field->label ?: 'Untitled'),
                    field: $field,
                    suggestions: $suggestions,
                    suggestion: sprintf('Add a unique key to "%s".', $field->label ?: 'Untitled'),
                );
            } elseif (! preg_match('/^[a-z][a-z0-9_]*$/', $field->key)) {
                $score -= 2;
                $this->addIssue(
                    $issues,
                    'error',
                    'invalid_field_key',
                    sprintf('Field key "%s" has an invalid format.', $field->key),
                    field: $field,
                    suggestions: $suggestions,
                    suggestion: sprintf('Use lowercase letters, numbers, and underscores for "%s".', $field->label ?: $field->key),
                );
            }

            if (blank(trim((string) $field->label))) {
                $score -= 2;
                $this->addIssue(
                    $issues,
                    'error',
                    'missing_field_label',
                    sprintf('Field "%s" is missing a label.', $field->key ?: 'unknown'),
                    field: $field,
                    suggestions: $suggestions,
                    suggestion: sprintf('Add a label to field "%s".', $field->key ?: 'unknown'),
                );
            }

            if (! in_array($field->type, FieldService::SUPPORTED_TYPES, true)) {
                $score -= 3;
                $this->addIssue(
                    $issues,
                    'error',
                    'unsupported_field_type',
                    sprintf('Field "%s" uses unsupported type "%s".', $field->label ?: $field->key, $field->type),
                    field: $field,
                    suggestions: $suggestions,
                    suggestion: sprintf('Change "%s" to a supported field type.', $field->label ?: $field->key),
                );
            }

            if (in_array($field->type, self::OPTION_TYPES, true)) {
                $options = $this->fieldOptions($field);

                if ($options === []) {
                    $score -= 3;
                    $this->addIssue(
                        $issues,
                        'error',
                        'missing_field_options',
                        sprintf('%s field "%s" has no options.', ucfirst($field->type), $field->label ?: $field->key),
                        field: $field,
                        suggestions: $suggestions,
                        suggestion: sprintf('Add options to "%s".', $field->label ?: $field->key),
                    );
                } elseif ($this->hasEmptyOptions($options)) {
                    $score -= 2;
                    $this->addIssue(
                        $issues,
                        'warning',
                        'empty_field_options',
                        sprintf('%s field "%s" contains empty option values.', ucfirst($field->type), $field->label ?: $field->key),
                        field: $field,
                        suggestions: $suggestions,
                        suggestion: sprintf('Remove empty options from "%s".', $field->label ?: $field->key),
                    );
                }
            }
        }

        $duplicateKeys = $keyCounts->filter(fn (int $count) => $count > 1)->keys();

        foreach ($duplicateKeys as $duplicateKey) {
            $score -= 5;
            $this->addIssue(
                $issues,
                'error',
                'duplicate_field_key',
                sprintf('Field key "%s" is duplicated.', $duplicateKey),
                fieldKey: (string) $duplicateKey,
                suggestions: $suggestions,
                suggestion: sprintf('Make field key "%s" unique across the form.', $duplicateKey),
            );
        }

        return $this->clamp($score, 0, 25);
    }

    /**
     * @param  Collection<int, Field>  $fields
     * @param  list<array<string, mixed>>  $issues
     * @param  list<string>  $suggestions
     */
    private function scoreValidation(Collection $fields, array &$issues, array &$suggestions): int
    {
        $score = 25;

        if ($fields->isEmpty()) {
            return 0;
        }

        foreach ($fields as $field) {
            $validation = is_array($field->validation) ? $field->validation : [];

            if ($field->type === 'email' && $validation === []) {
                $score -= 2;
                $this->addIssue(
                    $issues,
                    'warning',
                    'missing_email_validation',
                    sprintf('%s has no explicit validation rules.', $field->label ?: $field->key),
                    field: $field,
                    suggestions: $suggestions,
                    suggestion: sprintf('Add email validation to %s.', $field->label ?: $field->key),
                );
            }

            if ($field->type === 'number' && ! $this->hasNumericValidation($validation)) {
                $score -= 2;
                $this->addIssue(
                    $issues,
                    'warning',
                    'missing_number_validation',
                    sprintf('%s has no numeric validation rules.', $field->label ?: $field->key),
                    field: $field,
                    suggestions: $suggestions,
                    suggestion: sprintf('Add min/max validation to %s.', $field->label ?: $field->key),
                );
            }

            if ($field->type === 'date' && $validation === []) {
                $score -= 1;
                $this->addIssue(
                    $issues,
                    'info',
                    'missing_date_validation',
                    sprintf('%s has no date validation rules.', $field->label ?: $field->key),
                    field: $field,
                    suggestions: $suggestions,
                    suggestion: sprintf('Consider adding date constraints to %s if needed.', $field->label ?: $field->key),
                );
            }

            if ($field->is_required && blank(trim((string) $field->label))) {
                $score -= 2;
                $this->addIssue(
                    $issues,
                    'error',
                    'required_field_missing_label',
                    sprintf('Required field "%s" is missing a label.', $field->key),
                    field: $field,
                    suggestions: $suggestions,
                    suggestion: sprintf('Add a label to required field "%s".', $field->key),
                );
            }
        }

        return $this->clamp($score, 0, 25);
    }

    /**
     * @param  Collection<int, Field>  $fields
     * @param  list<array<string, mixed>>  $issues
     * @param  list<string>  $suggestions
     */
    private function scoreRequiredFieldQuality(Collection $fields, array &$issues, array &$suggestions): int
    {
        $score = 15;

        if ($fields->isEmpty()) {
            return 0;
        }

        $requiredFields = $fields->where('is_required', true);
        $requiredRatio = $requiredFields->count() / max($fields->count(), 1);

        if ($requiredFields->count() > 0 && $requiredRatio >= self::HIGH_REQUIRED_RATIO) {
            $score -= 3;
            $this->addIssue(
                $issues,
                'warning',
                'too_many_required_fields',
                'A high proportion of fields are marked required.',
                suggestions: $suggestions,
                suggestion: 'Review required fields and mark only essential inputs as required.',
            );
        }

        foreach ($requiredFields as $field) {
            if (blank(trim((string) $field->label))) {
                $score -= 2;
            }

            if ($field->type === 'email' && ! $field->is_required) {
                continue;
            }
        }

        foreach ($fields as $field) {
            if ($field->type === 'email' && ! $field->is_required) {
                $score -= 1;
                $this->addIssue(
                    $issues,
                    'info',
                    'optional_email_field',
                    sprintf('%s is optional.', $field->label ?: $field->key),
                    field: $field,
                    suggestions: $suggestions,
                    suggestion: sprintf('Consider making %s required if it is needed for follow-up.', $field->label ?: $field->key),
                );
                break;
            }
        }

        return $this->clamp($score, 0, 15);
    }

    /**
     * @param  Collection<int, Section>  $sections
     * @param  Collection<int, Field>  $fields
     * @param  list<array<string, mixed>>  $issues
     * @param  list<string>  $suggestions
     */
    private function scoreUsability(Collection $sections, Collection $fields, array &$issues, array &$suggestions): int
    {
        $score = 15;

        if ($fields->isEmpty()) {
            return 0;
        }

        foreach ($fields as $field) {
            if (in_array($field->type, self::PLACEHOLDER_USEFUL_TYPES, true)
                && blank($this->fieldPlaceholder($field))) {
                $score -= 1;
                $this->addIssue(
                    $issues,
                    'warning',
                    'missing_placeholder',
                    sprintf('%s has no placeholder.', $field->label ?: $field->key),
                    field: $field,
                    suggestions: $suggestions,
                    suggestion: sprintf('Add a helpful placeholder to %s.', $field->label ?: $field->key),
                );
            }

            if ($this->isGenericLabel($field->label)) {
                $score -= 1;
                $this->addIssue(
                    $issues,
                    'info',
                    'generic_field_label',
                    sprintf('Field label "%s" is generic.', $field->label),
                    field: $field,
                    suggestions: $suggestions,
                    suggestion: sprintf('Use a more descriptive label instead of "%s".', $field->label),
                );
            }
        }

        foreach ($sections as $section) {
            if ($section->fields->count() >= self::LARGE_SECTION_FIELD_COUNT) {
                $score -= 3;
                $this->addIssue(
                    $issues,
                    'warning',
                    'large_section',
                    sprintf('"%s" contains %d fields.', $section->title ?: 'Untitled', $section->fields->count()),
                    section: $section,
                    suggestions: $suggestions,
                    suggestion: sprintf('Consider splitting "%s" into smaller sections.', $section->title ?: 'Untitled'),
                );
            }
        }

        return $this->clamp($score, 0, 15);
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  list<string>  $suggestions
     */
    private function addIssue(
        array &$issues,
        string $severity,
        string $code,
        string $message,
        ?Field $field = null,
        ?Section $section = null,
        ?string $fieldKey = null,
        ?array &$suggestions = null,
        ?string $suggestion = null,
    ): void {
        $issues[] = [
            'severity' => $severity,
            'code' => $code,
            'field_key' => $field?->key ?? $fieldKey,
            'section_id' => $section?->id ?? $field?->section_id,
            'section_title' => $section?->title,
            'message' => $message,
        ];

        if ($suggestion !== null && $suggestions !== null) {
            $suggestions[] = $suggestion;
        }
    }

    /**
     * @return array{key: string, label: string, score: int, max: int}
     */
    private function category(string $key, string $label, int $score, int $max): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'score' => $this->clamp($score, 0, $max),
            'max' => $max,
        ];
    }

    /**
     * @param  list<int>  $orders
     */
    private function hasSequentialOrdering(array $orders): bool
    {
        if ($orders === []) {
            return true;
        }

        sort($orders);

        return $orders === range(0, count($orders) - 1);
    }

    /**
     * @param  array<string, mixed>  $validation
     */
    private function hasNumericValidation(array $validation): bool
    {
        return array_key_exists('min', $validation) || array_key_exists('max', $validation);
    }

    /**
     * @return list<mixed>
     */
    private function fieldOptions(Field $field): array
    {
        $config = is_array($field->config) ? $field->config : [];
        $options = $config['options'] ?? [];

        return is_array($options) ? array_values($options) : [];
    }

    /**
     * @param  list<mixed>  $options
     */
    private function hasEmptyOptions(array $options): bool
    {
        foreach ($options as $option) {
            if (is_array($option)) {
                if (blank(trim((string) ($option['label'] ?? $option['value'] ?? '')))) {
                    return true;
                }

                continue;
            }

            if (blank(trim((string) $option))) {
                return true;
            }
        }

        return false;
    }

    private function fieldPlaceholder(Field $field): ?string
    {
        $config = is_array($field->config) ? $field->config : [];

        return isset($config['placeholder']) ? (string) $config['placeholder'] : null;
    }

    private function isGenericLabel(?string $label): bool
    {
        if ($label === null) {
            return false;
        }

        return in_array(strtolower(trim($label)), self::GENERIC_LABELS, true);
    }

    private function gradeForScore(int $score): string
    {
        return match (true) {
            $score >= 90 => 'Excellent',
            $score >= 75 => 'Good',
            $score >= 60 => 'Needs Improvement',
            $score >= 40 => 'Poor',
            default => 'Critical',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    private function summaryForGrade(string $grade, array $issues): string
    {
        $errorCount = collect($issues)->where('severity', 'error')->count();

        return match ($grade) {
            'Excellent' => 'Your form is in excellent shape and ready to publish.',
            'Good' => 'Your form is in good shape with a few improvements recommended.',
            'Needs Improvement' => 'Your form works but has several areas that should be improved before publishing.',
            'Poor' => 'Your form has significant quality issues that should be addressed.',
            default => $errorCount > 0
                ? 'Your form has critical structural or configuration problems.'
                : 'Your form needs major improvements before it is ready to publish.',
        };
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
