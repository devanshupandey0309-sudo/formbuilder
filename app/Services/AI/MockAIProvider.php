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

    public function generateForm(string $prompt): array
    {
        if (self::$exception !== null) {
            throw self::$exception;
        }

        if (self::$response !== null) {
            return self::$response;
        }

        if (trim($prompt) === '') {
            throw new RuntimeException('Prompt cannot be empty.');
        }

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
