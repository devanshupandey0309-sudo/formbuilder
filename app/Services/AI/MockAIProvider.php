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

        return $this->defaultGeneratedForm();
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
