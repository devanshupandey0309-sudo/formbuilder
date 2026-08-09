<?php

namespace Tests\Unit\AI;

use App\Services\AI\AIOutputValidator;
use App\Services\AI\AIResponseParser;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AIResponseParserTest extends TestCase
{
    public function test_parses_raw_json_object(): void
    {
        $payload = [
            'title' => 'Contact Form',
            'sections' => [
                [
                    'title' => 'Main',
                    'fields' => [
                        ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ],
                ],
            ],
        ];

        $parsed = app(AIResponseParser::class)->parse($payload);

        $this->assertSame('Contact Form', $parsed['title']);
    }

    public function test_extracts_json_from_markdown_fence(): void
    {
        $payload = <<<'TEXT'
Here is the form you requested:

```json
{
  "title": "Markdown Form",
  "sections": [
    {
      "title": "Main",
      "fields": [
        {"key": "email", "label": "Email", "type": "email"}
      ]
    }
  ]
}
```
TEXT;

        $parsed = app(AIResponseParser::class)->parse($payload);

        $this->assertSame('Markdown Form', $parsed['title']);
    }

    public function test_unwraps_generated_form_key(): void
    {
        $parsed = app(AIResponseParser::class)->parse([
            'generated_form' => [
                'title' => 'Wrapped Form',
                'sections' => [
                    [
                        'title' => 'Main',
                        'fields' => [
                            ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('Wrapped Form', $parsed['title']);
    }

    public function test_empty_response_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(AIResponseParser::class)->parse('   ');
    }
}
