<?php

namespace App\Services\AI;

use Illuminate\Validation\ValidationException;

class AIResponseParser
{
    /**
     * Normalize provider output into a form-definition array.
     *
     * @return array<string, mixed>
     */
    public function parse(mixed $payload): array
    {
        if (is_array($payload)) {
            return $this->normalizeArrayPayload($payload);
        }

        if (! is_string($payload)) {
            throw ValidationException::withMessages([
                'ai_output' => ['AI response must be a JSON object.'],
            ]);
        }

        $text = trim($payload);

        if ($text === '') {
            throw ValidationException::withMessages([
                'ai_output' => ['AI response was empty.'],
            ]);
        }

        $decoded = json_decode($this->extractJsonString($text), true);

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'ai_output' => ['AI response did not contain valid JSON.'],
            ]);
        }

        return $this->normalizeArrayPayload($decoded);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeArrayPayload(array $payload): array
    {
        foreach (['form_definition', 'generated_form', 'form'] as $wrapperKey) {
            if (isset($payload[$wrapperKey]) && is_array($payload[$wrapperKey])) {
                return $payload[$wrapperKey];
            }
        }

        if (isset($payload['content']) && is_string($payload['content'])) {
            return $this->parse($payload['content']);
        }

        if (isset($payload['raw_text']) && is_string($payload['raw_text'])) {
            return $this->parse($payload['raw_text']);
        }

        return $payload;
    }

    private function extractJsonString(string $text): string
    {
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $text, $matches)) {
            return trim($matches[1]);
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end >= $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }
}
