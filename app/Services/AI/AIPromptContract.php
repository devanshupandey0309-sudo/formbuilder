<?php

namespace App\Services\AI;

use App\Services\FieldService;
use App\Services\FieldValidationRules;

class AIPromptContract
{
    /**
     * @return list<string>
     */
    public static function supportedFieldTypes(): array
    {
        return FieldService::SUPPORTED_TYPES;
    }

    public static function systemPrompt(): string
    {
        return self::instructionPrompt('', null, 'generate');
    }

    public static function instructionPrompt(string $userPrompt, ?array $currentSchema = null, string $operation = 'generate'): string
    {
        $types = implode(', ', self::supportedFieldTypes());

        $validationRules = <<<'RULES'
Supported validation rules by field type:
- email: {"format":"email"} plus optional min_length, max_length
- date: {"format":"Y-m-d"} plus optional min, max (YYYY-MM-DD)
- number: optional min, max
- text, textarea: optional min_length, max_length
- select, radio, checkbox: use config.options; do not invent unsupported validation keys
RULES;

        $schemaExample = <<<'JSON'
{
  "title": "string",
  "description": "string|null",
  "sections": [
    {
      "title": "string",
      "description": "string|null",
      "fields": [
        {
          "key": "snake_case_key",
          "label": "Human label",
          "type": "supported type",
          "required": true,
          "config": {},
          "validation": {}
        }
      ]
    }
  ]
}
JSON;

        $operationInstruction = $operation === 'edit'
            ? 'Return the complete updated schema for the edit request, not a partial patch.'
            : 'Generate a new form schema for the generate request.';

        $currentSchemaBlock = is_array($currentSchema)
            ? "Current schema JSON:\n".json_encode($currentSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : 'No current schema provided.';

        return <<<PROMPT
You are a form schema generator for a Laravel form builder.

Generate a form based ONLY on the user's requested requirements.
Do not invent fields.
Do not add unrelated sections.
Do not transform a customer form into an employee onboarding form unless the user asked for employee onboarding.
Every requested field should be represented unless it is impossible or ambiguous.
Use only these supported field types: {$types}.
Return only the requested JSON structure.
{$operationInstruction}

{$validationRules}

Required JSON shape:
{$schemaExample}

Rules:
- Field keys must match ^[a-z][a-z0-9_]*$ and be unique across the entire form.
- Labels must preserve the user's requested field meaning.
- Do not wrap JSON in markdown fences or add commentary.
- Do not generate unknown properties.
- Do not generate duplicate keys.
- For select, radio, and checkbox fields, config.options must be a non-empty array.
- Email fields should normally include validation {"format":"email"}.
- Date fields should normally include validation {"format":"Y-m-d"} without arbitrary min/max unless requested.

{$currentSchemaBlock}

User prompt:
{$userPrompt}
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public static function geminiResponseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string', 'nullable' => true],
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string', 'nullable' => true],
                            'fields' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'key' => ['type' => 'string'],
                                        'label' => ['type' => 'string'],
                                        'type' => [
                                            'type' => 'string',
                                            'enum' => self::supportedFieldTypes(),
                                        ],
                                        'required' => ['type' => 'boolean'],
                                        'config' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'options' => [
                                                    'type' => 'array',
                                                    'items' => ['type' => 'string'],
                                                ],
                                                'placeholder' => ['type' => 'string'],
                                            ],
                                        ],
                                        'validation' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'format' => ['type' => 'string'],
                                                'min' => ['type' => 'number'],
                                                'max' => ['type' => 'number'],
                                                'min_length' => ['type' => 'integer'],
                                                'max_length' => ['type' => 'integer'],
                                            ],
                                        ],
                                    ],
                                    'required' => ['key', 'label', 'type', 'required', 'config', 'validation'],
                                ],
                            ],
                        ],
                        'required' => ['title', 'description', 'fields'],
                    ],
                ],
            ],
            'required' => ['title', 'description', 'sections'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function outputContract(): array
    {
        return [
            'title' => 'required string',
            'description' => 'optional string|null',
            'sections' => 'required non-empty array',
            'sections[].title' => 'required string',
            'sections[].description' => 'optional string|null',
            'sections[].fields' => 'required non-empty array',
            'sections[].fields[].key' => 'required snake_case string, globally unique',
            'sections[].fields[].label' => 'required string',
            'sections[].fields[].type' => 'required; one of: '.implode(', ', self::supportedFieldTypes()),
            'sections[].fields[].required' => 'optional boolean',
            'sections[].fields[].config' => 'optional object; options required for select/radio/checkbox',
            'sections[].fields[].validation' => 'optional object; supported keys depend on field type',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function supportedValidationRulesDocumentation(): array
    {
        return [
            'email.format' => 'email',
            'email.min_length' => 'integer',
            'email.max_length' => 'integer',
            'date.format' => 'Y-m-d',
            'date.min' => 'YYYY-MM-DD',
            'date.max' => 'YYYY-MM-DD',
            'number.min' => 'number',
            'number.max' => 'number',
            'text.min_length' => 'integer',
            'text.max_length' => 'integer',
            'textarea.min_length' => 'integer',
            'textarea.max_length' => 'integer',
        ];
    }

    public static function hallucinatedFieldTypeStrategy(): string
    {
        return 'reject';
    }
}
