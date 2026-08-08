<?php

namespace App\Contracts;

interface AIProvider
{
    /**
     * Generate a structured form definition from a natural-language prompt.
     *
     * @return array<string, mixed>
     */
    public function generateForm(string $prompt): array;
}
