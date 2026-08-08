<?php

namespace App\Services\AI;

use App\Contracts\AIProvider;
use App\Exceptions\AI\TransientAIServiceException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HttpAIProvider implements AIProvider
{
    /**
     * @param  array<string, mixed>|null  $currentSchema
     * @return array<string, mixed>
     */
    public function generateForm(string $prompt, ?array $currentSchema = null, string $operation = 'generate'): array
    {
        $baseUrl = rtrim((string) config('ai.service_url'), '/');

        try {
            $response = Http::connectTimeout((int) config('ai.connect_timeout'))
                ->timeout((int) config('ai.timeout'))
                ->acceptJson()
                ->post("{$baseUrl}/generate-form", [
                    'prompt' => $prompt,
                    'current_schema' => $currentSchema,
                    'operation' => $operation,
                ]);
        } catch (\Throwable $exception) {
            Log::error('FastAPI AI service request failed.', [
                'message' => $exception->getMessage(),
            ]);

            throw new TransientAIServiceException('AI service is unavailable.', 0, $exception);
        }

        if ($response->failed()) {
            Log::warning('FastAPI AI service returned an error response.', [
                'status' => $response->status(),
            ]);

            throw new TransientAIServiceException('AI service request failed.');
        }

        /** @var array<string, mixed>|null $payload */
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new TransientAIServiceException('AI service returned an invalid response.');
        }

        return $payload;
    }
}
