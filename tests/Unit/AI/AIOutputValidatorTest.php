<?php

namespace Tests\Unit\AI;

use App\Services\AI\AIOutputValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AIOutputValidatorTest extends TestCase
{
    public function test_hallucinated_field_type_is_rejected(): void
    {
        $validator = app(AIOutputValidator::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Unsupported field type 'phone'.");

        $validator->validate([
            'title' => 'Bad Form',
            'sections' => [
                [
                    'title' => 'Contact',
                    'fields' => [
                        [
                            'key' => 'mobile',
                            'label' => 'Mobile',
                            'type' => 'phone',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_valid_schema_is_normalized(): void
    {
        $validator = app(AIOutputValidator::class);

        $validated = $validator->validate([
            'title' => 'Good Form',
            'sections' => [
                [
                    'title' => 'Main',
                    'fields' => [
                        [
                            'key' => 'Full Name',
                            'label' => 'Full Name',
                            'type' => 'text',
                            'required' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('full_name', $validated['sections'][0]['fields'][0]['key']);
        $this->assertTrue($validated['sections'][0]['fields'][0]['required']);
    }

    public function test_email_and_date_fields_receive_default_validation_metadata(): void
    {
        $validator = app(AIOutputValidator::class);

        $validated = $validator->validate([
            'title' => 'Contact Form',
            'sections' => [
                [
                    'title' => 'Main',
                    'fields' => [
                        [
                            'key' => 'email',
                            'label' => 'Email',
                            'type' => 'email',
                            'required' => true,
                        ],
                        [
                            'key' => 'birthday',
                            'label' => 'Date of Birth',
                            'type' => 'date',
                            'required' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(['format' => 'email'], $validated['sections'][0]['fields'][0]['validation']);
        $this->assertSame(['format' => 'Y-m-d'], $validated['sections'][0]['fields'][1]['validation']);
    }

    public function test_existing_validation_metadata_is_preserved(): void
    {
        $validator = app(AIOutputValidator::class);

        $validated = $validator->validate([
            'title' => 'Number Form',
            'sections' => [
                [
                    'title' => 'Main',
                    'fields' => [
                        [
                            'key' => 'age',
                            'label' => 'Age',
                            'type' => 'number',
                            'required' => false,
                            'validation' => ['min' => 18, 'max' => 120],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(['min' => 18, 'max' => 120], $validated['sections'][0]['fields'][0]['validation']);
    }
}
