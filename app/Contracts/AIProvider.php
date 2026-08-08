<?php

namespace App\Contracts;

interface AIProvider
{
    /**
     * Generate or edit a structured form definition from a natural-language prompt.
     *
     * @param  array<string, mixed>|null  $currentSchema
     * @return array<string, mixed>
     */
    public function generateForm(
        string $prompt,
        ?array $currentSchema = null,
        string $operation = 'generate',
    ): array;
}
