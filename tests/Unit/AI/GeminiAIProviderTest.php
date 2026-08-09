<?php

namespace Tests\Unit\AI;

use App\Exceptions\AI\PermanentAIServiceException;
use App\Exceptions\AI\TransientAIServiceException;
use App\Services\AI\AIPromptContract;
use App\Services\AI\AIOutputValidator;
use App\Services\AI\GeminiAIProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GeminiAIProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.gemini.api_key' => 'test-gemini-key',
            'ai.gemini.model' => 'gemini-2.5-flash',
        ]);
    }

    public function test_missing_api_key_fails_with_clear_error(): void
    {
        config(['ai.gemini.api_key' => '']);

        $this->expectException(PermanentAIServiceException::class);
        $this->expectExceptionMessage('Gemini API key is not configured');

        app(GeminiAIProvider::class)->generateForm('Create a contact form');
    }

    public function test_successful_gemini_response_is_decoded(): void
    {
        $schema = $this->sampleSchema();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiApiResponse($schema)),
        ]);

        $output = app(GeminiAIProvider::class)->generateForm('Create a contact form with name and email');

        $this->assertSame('Contact Form', $output['title']);
        $this->assertSame('name', $output['sections'][0]['fields'][0]['key']);
    }

    public function test_gemini_request_includes_prompt_contract_instructions(): void
    {
        $schema = $this->sampleSchema();

        Http::fake(function ($request) use ($schema) {
            $payload = $request->data();
            $instruction = $payload['contents'][0]['parts'][0]['text'];

            $this->assertStringContainsString('Do not invent fields.', $instruction);
            $this->assertStringContainsString('text, textarea, number, email, date, select, radio, checkbox', $instruction);
            $this->assertStringContainsString('"format":"email"', $instruction);
            $this->assertSame('application/json', $payload['generationConfig']['responseMimeType']);
            $this->assertSame(
                AIPromptContract::geminiResponseSchema(),
                $payload['generationConfig']['responseSchema'],
            );

            return Http::response($this->geminiApiResponse($schema));
        });

        app(GeminiAIProvider::class)->generateForm('Create a contact form with name and email');
    }

    public function test_malformed_gemini_json_response_fails(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{not valid json'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->expectException(PermanentAIServiceException::class);
        $this->expectExceptionMessage('malformed JSON');

        app(GeminiAIProvider::class)->generateForm('Create a form');
    }

    public function test_empty_gemini_response_fails(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [],
            ]),
        ]);

        $this->expectException(PermanentAIServiceException::class);
        $this->expectExceptionMessage('invalid response');

        app(GeminiAIProvider::class)->generateForm('Create a form');
    }

    public function test_gemini_http_401_is_permanent_failure(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 401),
        ]);

        $this->expectException(PermanentAIServiceException::class);
        $this->expectExceptionMessage('credentials were rejected');

        app(GeminiAIProvider::class)->generateForm('Create a form');
    }

    public function test_gemini_http_429_is_transient_failure(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 429),
        ]);

        $this->expectException(TransientAIServiceException::class);

        app(GeminiAIProvider::class)->generateForm('Create a form');
    }

    public function test_gemini_server_error_is_transient_failure(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 503),
        ]);

        $this->expectException(TransientAIServiceException::class);

        app(GeminiAIProvider::class)->generateForm('Create a form');
    }

    public function test_gemini_connection_failure_is_transient(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });

        $this->expectException(TransientAIServiceException::class);
        $this->expectExceptionMessage('unavailable');

        app(GeminiAIProvider::class)->generateForm('Create a form');
    }

    public function test_invalid_structured_output_is_rejected_by_validator(): void
    {
        $schema = $this->sampleSchema();
        $schema['sections'][0]['fields'][] = [
            'key' => 'mobile',
            'label' => 'Mobile',
            'type' => 'phone',
            'required' => true,
            'config' => [],
            'validation' => [],
        ];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiApiResponse($schema)),
        ]);

        $output = app(GeminiAIProvider::class)->generateForm('Create a form');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Unsupported field type 'phone'.");

        app(AIOutputValidator::class)->validate($output);
    }

    public function test_duplicate_field_keys_are_rejected_by_validator(): void
    {
        $schema = $this->sampleSchema();
        $schema['sections'][0]['fields'][] = [
            'key' => 'name',
            'label' => 'Name Duplicate',
            'type' => 'text',
            'required' => true,
            'config' => [],
            'validation' => [],
        ];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiApiResponse($schema)),
        ]);

        $output = app(GeminiAIProvider::class)->generateForm('Create a form');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Duplicate field key');

        app(AIOutputValidator::class)->validate($output);
    }

    public function test_invalid_validation_rules_are_rejected_by_validator(): void
    {
        $schema = $this->sampleSchema();
        $schema['sections'][0]['fields'][1]['validation'] = ['pattern' => '[A-Z]+'];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiApiResponse($schema)),
        ]);

        $output = app(GeminiAIProvider::class)->generateForm('Create a form');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Unsupported validation rule 'pattern'");

        app(AIOutputValidator::class)->validate($output);
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleSchema(): array
    {
        return [
            'title' => 'Contact Form',
            'description' => null,
            'sections' => [
                [
                    'title' => 'Main',
                    'description' => null,
                    'fields' => [
                        [
                            'key' => 'name',
                            'label' => 'Name',
                            'type' => 'text',
                            'required' => true,
                            'config' => [],
                            'validation' => [],
                        ],
                        [
                            'key' => 'email',
                            'label' => 'Email',
                            'type' => 'email',
                            'required' => true,
                            'config' => [],
                            'validation' => ['format' => 'email'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function geminiApiResponse(array $schema): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => json_encode($schema, JSON_THROW_ON_ERROR)],
                        ],
                    ],
                ],
            ],
        ];
    }
}
