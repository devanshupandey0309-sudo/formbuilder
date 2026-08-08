<?php

namespace App\Contracts;

interface FormImportParser
{
    /**
     * Parse an uploaded document into a normalized import structure.
     *
     * @return array<string, mixed>
     */
    public function parse(string $path): array;
}
