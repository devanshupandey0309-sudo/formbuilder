<?php

namespace App\Services\AI;

use App\Contracts\AIProvider;
use RuntimeException;

class MockAIProvider implements AIProvider
{
    /** @var array<string, mixed>|null */
    private static ?array $response = null;

    private static ?\Throwable $exception = null;

    /**
     * @param  array<string, mixed>|null  $response
     */
    public static function fake(?array $response = null, ?\Throwable $exception = null): void
    {
        self::$response = $response;
        self::$exception = $exception;
    }

    public static function reset(): void
    {
        self::$response = null;
        self::$exception = null;
    }

    /**
     * @param  array<string, mixed>|null  $currentSchema
     * @return array<string, mixed>
     */
    public function generateForm(
        string $prompt,
        ?array $currentSchema = null,
        string $operation = 'generate',
    ): array {
        if (self::$exception !== null) {
            throw self::$exception;
        }

        if (self::$response !== null) {
            return self::$response;
        }

        if (trim($prompt) === '') {
            throw new RuntimeException('Prompt cannot be empty.');
        }

        if ($operation === 'edit' && is_array($currentSchema)) {
            return $this->applyMockEdit($prompt, $currentSchema);
        }

        $explicitForm = $this->buildFromExplicitFieldPrompt($prompt);
        if ($explicitForm !== null) {
            return $explicitForm;
        }

        return $this->defaultGeneratedForm();
    }

    /**
     * Deterministic prompt-aware generation for explicit field requests.
     *
     * The mock provider is not a real LLM. When a prompt explicitly names fields,
     * return only those fields instead of the generic onboarding fixture.
     *
     * @return array<string, mixed>|null
     */
    private function buildFromExplicitFieldPrompt(string $prompt): ?array
    {
        $normalizedPrompt = strtolower($prompt);
        $matches = $this->matchExplicitFields($normalizedPrompt);

        if (! $this->shouldUseExplicitFieldMode($prompt, $matches)) {
            return null;
        }

        usort($matches, fn (array $left, array $right) => $left['position'] <=> $right['position']);

        $fields = [];
        $seenKeys = [];

        foreach ($matches as $match) {
            $definition = $match['definition'];

            if (in_array($definition['key'], $seenKeys, true)) {
                continue;
            }

            $seenKeys[] = $definition['key'];
            $fields[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'type' => $definition['type'],
                'required' => true,
                'config' => $definition['config'] ?? [],
            ];
        }

        return [
            'title' => $this->resolveExplicitFormTitle($normalizedPrompt),
            'description' => 'Generated from explicitly requested fields.',
            'sections' => [
                [
                    'title' => $this->resolveExplicitSectionTitle($normalizedPrompt, $fields),
                    'description' => null,
                    'fields' => $fields,
                ],
            ],
        ];
    }

    /**
     * @return list<array{position: int, definition: array<string, mixed>}>
     */
    private function matchExplicitFields(string $normalizedPrompt): array
    {
        $matches = [];
        $occupiedRanges = [];

        $catalog = $this->explicitFieldCatalog();

        usort($catalog, function (array $left, array $right): int {
            $leftMax = max(array_map(static fn (string $alias): int => strlen($alias), $left['aliases']));
            $rightMax = max(array_map(static fn (string $alias): int => strlen($alias), $right['aliases']));

            return $rightMax <=> $leftMax;
        });

        foreach ($catalog as $definition) {
            $aliases = $definition['aliases'];
            usort($aliases, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

            foreach ($aliases as $alias) {
                $pattern = '/\b'.preg_quote(strtolower($alias), '/').'\b/u';

                if (preg_match($pattern, $normalizedPrompt, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                $start = $match[0][1];
                $end = $start + strlen($match[0][0]);

                $overlaps = false;

                foreach ($occupiedRanges as [$occupiedStart, $occupiedEnd]) {
                    if ($start < $occupiedEnd && $end > $occupiedStart) {
                        $overlaps = true;

                        break;
                    }
                }

                if ($overlaps) {
                    continue;
                }

                $occupiedRanges[] = [$start, $end];
                $matches[] = [
                    'position' => $start,
                    'definition' => $definition,
                ];

                break;
            }
        }

        return $matches;
    }

    /**
     * @param  list<array{position: int, definition: array<string, mixed>}>  $matches
     */
    private function shouldUseExplicitFieldMode(string $prompt, array $matches): bool
    {
        if ($matches === []) {
            return false;
        }

        if (preg_match('/\bfields?\b/i', $prompt)) {
            return true;
        }

        if (preg_match('/\bwith\b/i', $prompt) && count($matches) >= 2) {
            return true;
        }

        if (preg_match('/\b(customer registration|employee onboarding)\b/i', $prompt) && count($matches) >= 2) {
            return true;
        }

        return false;
    }

    private function resolveExplicitFormTitle(string $normalizedPrompt): string
    {
        if (str_contains($normalizedPrompt, 'customer registration')) {
            return 'Customer Registration Form';
        }

        if (str_contains($normalizedPrompt, 'employee onboarding')) {
            return 'Employee Onboarding Form';
        }

        if (str_contains($normalizedPrompt, 'employee')) {
            return 'Employee Information Form';
        }

        if (str_contains($normalizedPrompt, 'customer')) {
            return 'Customer Registration Form';
        }

        return 'Generated Form';
    }

    /**
     * @return list<array{aliases: list<string>, key: string, label: string, type: string, config?: array<string, mixed>}>
     */
    private function explicitFieldCatalog(): array
    {
        return [
            [
                'aliases' => ['emergency contact'],
                'key' => 'emergency_contact',
                'label' => 'Emergency Contact',
                'type' => 'text',
            ],
            [
                'aliases' => ['manager email'],
                'key' => 'manager_email',
                'label' => 'Manager Email',
                'type' => 'email',
            ],
            [
                'aliases' => ['date of birth', 'dob'],
                'key' => 'date_of_birth',
                'label' => 'Date of Birth',
                'type' => 'date',
            ],
            [
                'aliases' => ['phone number', 'phone'],
                'key' => 'phone_number',
                'label' => 'Phone Number',
                'type' => 'text',
            ],
            [
                'aliases' => ['employee name'],
                'key' => 'employee_name',
                'label' => 'Employee Name',
                'type' => 'text',
            ],
            [
                'aliases' => ['joining date'],
                'key' => 'joining_date',
                'label' => 'Joining Date',
                'type' => 'date',
            ],
            [
                'aliases' => ['email address', 'email'],
                'key' => 'email',
                'label' => 'Email',
                'type' => 'email',
            ],
            [
                'aliases' => ['department'],
                'key' => 'department',
                'label' => 'Department',
                'type' => 'select',
                'config' => [
                    'options' => ['Engineering', 'Sales', 'HR'],
                ],
            ],
            [
                'aliases' => ['full name'],
                'key' => 'full_name',
                'label' => 'Full Name',
                'type' => 'text',
            ],
            [
                'aliases' => ['country'],
                'key' => 'country',
                'label' => 'Country',
                'type' => 'text',
            ],
            [
                'aliases' => ['age'],
                'key' => 'age',
                'label' => 'Age',
                'type' => 'number',
            ],
            [
                'aliases' => ['name'],
                'key' => 'name',
                'label' => 'Name',
                'type' => 'text',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function resolveExplicitSectionTitle(string $normalizedPrompt, array $fields): string
    {
        if (str_contains($normalizedPrompt, 'employee onboarding') || str_contains($normalizedPrompt, 'employment') || collect($fields)->contains(fn (array $field) => in_array($field['key'], ['department', 'joining_date', 'manager_email', 'emergency_contact'], true))) {
            return 'Employee Onboarding';
        }

        if (str_contains($normalizedPrompt, 'customer registration')) {
            return 'Registration Details';
        }

        return 'Personal Information';
    }

    /**
     * @param  array<string, mixed>  $currentSchema
     * @return array<string, mixed>
     */
    private function applyMockEdit(string $prompt, array $currentSchema): array
    {
        $schema = $currentSchema;

        if (stripos($prompt, 'emergency contact') !== false) {
            $schema['sections'][] = [
                'title' => 'Emergency Contact',
                'description' => null,
                'fields' => [
                    [
                        'key' => 'emergency_contact_name',
                        'label' => 'Emergency Contact Name',
                        'type' => 'text',
                        'required' => true,
                        'config' => [],
                    ],
                    [
                        'key' => 'emergency_contact_phone',
                        'label' => 'Emergency Contact Phone',
                        'type' => 'text',
                        'required' => true,
                        'config' => [],
                    ],
                ],
            ];
        }

        if (stripos($prompt, 'phone') !== false && stripos($prompt, 'required') !== false) {
            foreach ($schema['sections'] as &$section) {
                foreach ($section['fields'] as &$field) {
                    if (($field['key'] ?? '') === 'phone' || str_contains(strtolower($field['label'] ?? ''), 'phone')) {
                        $field['required'] = true;
                    }
                }
            }
            unset($section, $field);
        }

        if (stripos($prompt, 'date of birth') !== false) {
            $schema['sections'][0]['fields'][] = [
                'key' => 'date_of_birth',
                'label' => 'Date of Birth',
                'type' => 'date',
                'required' => true,
                'config' => [],
            ];
        }

        if (stripos($prompt, 'consent') !== false) {
            $schema['sections'][] = [
                'title' => 'Consent',
                'description' => null,
                'fields' => [
                    [
                        'key' => 'consent',
                        'label' => 'I agree to the terms',
                        'type' => 'checkbox',
                        'required' => true,
                        'config' => [
                            'options' => ['I agree'],
                        ],
                    ],
                ],
            ];
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultGeneratedForm(): array
    {
        return [
            'title' => 'Employee Onboarding Form',
            'description' => 'Employee onboarding information',
            'sections' => [
                [
                    'title' => 'Personal Information',
                    'description' => null,
                    'fields' => [
                        [
                            'key' => 'full_name',
                            'label' => 'Full Name',
                            'type' => 'text',
                            'required' => true,
                            'config' => [],
                        ],
                        [
                            'key' => 'email',
                            'label' => 'Email',
                            'type' => 'email',
                            'required' => true,
                            'config' => [],
                        ],
                        [
                            'key' => 'phone',
                            'label' => 'Phone Number',
                            'type' => 'text',
                            'required' => false,
                            'config' => [],
                        ],
                    ],
                ],
                [
                    'title' => 'Employment Details',
                    'description' => 'Department and start date',
                    'fields' => [
                        [
                            'key' => 'department',
                            'label' => 'Department',
                            'type' => 'select',
                            'required' => true,
                            'config' => [
                                'options' => ['Engineering', 'Sales', 'HR'],
                            ],
                        ],
                        [
                            'key' => 'joining_date',
                            'label' => 'Joining Date',
                            'type' => 'date',
                            'required' => true,
                            'config' => [],
                        ],
                    ],
                ],
            ],
        ];
    }
}
