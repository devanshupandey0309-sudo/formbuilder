<?php

namespace App\Services\AI;

use App\Contracts\AIProvider;
use App\Exceptions\AI\PermanentAIServiceException;
use App\Exceptions\AI\TransientAIServiceException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiAIProvider implements AIProvider
{
    /**
     * @param  array<string, mixed>|null  $currentSchema
     * @return array<string, mixed>
     */
    public function generateForm(string $prompt, ?array $currentSchema = null, string $operation = 'generate'): array
    {
        $apiKey = (string) config('ai.gemini.api_key');

        if ($apiKey === '') {
            throw new PermanentAIServiceException('Gemini API key is not configured. Set GEMINI_API_KEY in the environment.');
        }

        $model = (string) config('ai.gemini.model', 'gemini-2.5-flash');
        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            $model,
        );

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => AIPromptContract::instructionPrompt($prompt, $currentSchema, $operation),
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => AIPromptContract::geminiResponseSchema(),
            ],
        ];

        try {
            $response = Http::connectTimeout((int) config('ai.gemini.connect_timeout', 5))
                ->timeout((int) config('ai.gemini.timeout', 30))
                ->acceptJson()
                ->withQueryParameters(['key' => $apiKey])
                ->post($endpoint, $payload);
        } catch (\Throwable $exception) {
            Log::error('Gemini AI request failed.', [
                'message' => $exception->getMessage(),
            ]);

            throw new TransientAIServiceException('Gemini AI service is unavailable.', 0, $exception);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new PermanentAIServiceException('Gemini AI credentials were rejected.');
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new TransientAIServiceException('Gemini AI service request failed.');
        }

        if ($response->clientError()) {
            throw new PermanentAIServiceException('Gemini AI rejected the request.');
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            throw new PermanentAIServiceException('Gemini AI returned an invalid response.');
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new PermanentAIServiceException('Gemini AI returned malformed JSON.');
        }

        return $decoded;
    }
}
