<?php

namespace App\Services\Import;

use App\Contracts\FormImportParser;
use App\Services\FieldService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class XlsxFormParser implements FormImportParser
{
    /** @var list<string> */
    private const EXPECTED_HEADERS = ['section', 'field', 'type', 'required', 'options'];

    public function parse(string $path): array
    {
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => ['The spreadsheet is empty.'],
            ]);
        }

        $headers = array_map(
            fn ($header) => Str::of((string) $header)->lower()->trim()->toString(),
            $rows[0] ?? [],
        );

        foreach (self::EXPECTED_HEADERS as $expectedHeader) {
            if (! in_array($expectedHeader, $headers, true)) {
                throw ValidationException::withMessages([
                    'file' => ['The spreadsheet must include headers: Section, Field, Type, Required, Options.'],
                ]);
            }
        }

        $headerIndexes = array_flip($headers);
        $sections = [];
        $title = 'Imported Form';

        foreach (array_slice($rows, 1) as $rowIndex => $row) {
            $sectionName = trim((string) ($row[$headerIndexes['section']] ?? ''));
            $fieldLabel = trim((string) ($row[$headerIndexes['field']] ?? ''));
            $fieldType = Str::of((string) ($row[$headerIndexes['type']] ?? ''))->lower()->trim()->toString();
            $requiredRaw = Str::of((string) ($row[$headerIndexes['required']] ?? ''))->lower()->trim()->toString();
            $optionsRaw = trim((string) ($row[$headerIndexes['options']] ?? ''));

            if ($sectionName === '' && $fieldLabel === '' && $fieldType === '') {
                continue;
            }

            if ($sectionName === '' || $fieldLabel === '' || $fieldType === '') {
                throw ValidationException::withMessages([
                    'file' => ['Row '.($rowIndex + 2).' is missing section, field, or type.'],
                ]);
            }

            if (! in_array($fieldType, FieldService::SUPPORTED_TYPES, true)) {
                throw ValidationException::withMessages([
                    'file' => ["Row ".($rowIndex + 2)." contains unsupported field type '{$fieldType}'."],
                ]);
            }

            $field = [
                'key' => Str::snake($fieldLabel),
                'label' => $fieldLabel,
                'type' => $fieldType,
                'required' => in_array($requiredRaw, ['yes', 'true', '1'], true),
                'config' => $this->buildConfig($fieldType, $optionsRaw),
            ];

            $sections[$sectionName]['title'] = $sectionName;
            $sections[$sectionName]['description'] = null;
            $sections[$sectionName]['fields'][] = $field;
        }

        if ($sections === []) {
            throw ValidationException::withMessages([
                'file' => ['The spreadsheet does not contain any importable field rows.'],
            ]);
        }

        return [
            'title' => $title,
            'description' => null,
            'sections' => array_values($sections),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildConfig(string $type, string $optionsRaw): array
    {
        if (! in_array($type, ['select', 'radio', 'checkbox'], true)) {
            return [];
        }

        if ($optionsRaw === '') {
            throw ValidationException::withMessages([
                'file' => ["Field type '{$type}' requires options."],
            ]);
        }

        $options = array_values(array_filter(array_map(
            fn (string $option) => trim($option),
            preg_split('/\s*,\s*/', $optionsRaw) ?: [],
        )));

        if ($options === []) {
            throw ValidationException::withMessages([
                'file' => ["Field type '{$type}' requires non-empty options."],
            ]);
        }

        return ['options' => $options];
    }
}
