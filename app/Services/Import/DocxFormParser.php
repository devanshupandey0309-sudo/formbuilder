<?php

namespace App\Services\Import;

use App\Contracts\FormImportParser;
use App\Services\FieldService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;

class DocxFormParser implements FormImportParser
{
    public function parse(string $path): array
    {
        $phpWord = IOFactory::load($path);
        $currentSection = null;
        $sections = [];
        $title = 'Imported Form';

        foreach ($phpWord->getSections() as $documentSection) {
            $elements = $documentSection->getElements();

            foreach ($elements as $index => $element) {
                if ($element instanceof Title) {
                    $heading = trim((string) $element->getText());

                    if ($heading !== '') {
                        $currentSection = $heading;
                        $sections[$currentSection] = [
                            'title' => $heading,
                            'description' => null,
                            'fields' => [],
                        ];
                    }

                    continue;
                }

                if ($element instanceof Table) {
                    $tableFields = $this->parseTable($element);

                    if ($tableFields === []) {
                        continue;
                    }

                    if ($currentSection === null) {
                        $currentSection = $this->findHeadingBefore($elements, $index) ?? 'General';
                        $sections[$currentSection] = [
                            'title' => $currentSection,
                            'description' => null,
                            'fields' => [],
                        ];
                    }

                    $sections[$currentSection]['fields'] = array_merge(
                        $sections[$currentSection]['fields'],
                        $tableFields,
                    );
                }
            }
        }

        if ($sections === []) {
            throw ValidationException::withMessages([
                'file' => ['The DOCX document does not contain any importable headings and field tables.'],
            ]);
        }

        foreach ($sections as $section) {
            if ($section['fields'] === []) {
                throw ValidationException::withMessages([
                    'file' => ["Section '{$section['title']}' does not contain any fields."],
                ]);
            }
        }

        return [
            'title' => $title,
            'description' => null,
            'sections' => array_values($sections),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseTable(Table $table): array
    {
        $rows = $table->getRows();

        if ($rows === []) {
            return [];
        }

        $headers = $this->extractRowValues($rows[0]);

        if ($headers === []) {
            return [];
        }

        $normalizedHeaders = array_map(
            fn (string $header) => Str::of($header)->lower()->trim()->toString(),
            $headers,
        );

        if (! in_array('field', $normalizedHeaders, true) || ! in_array('type', $normalizedHeaders, true)) {
            throw ValidationException::withMessages([
                'file' => ['DOCX tables must include Field and Type columns.'],
            ]);
        }

        $headerIndexes = array_flip($normalizedHeaders);
        $fields = [];

        foreach (array_slice($rows, 1) as $rowIndex => $row) {
            $values = $this->extractRowValues($row);
            $fieldLabel = trim((string) ($values[$headerIndexes['field']] ?? ''));
            $fieldType = Str::of((string) ($values[$headerIndexes['type']] ?? ''))->lower()->trim()->toString();
            $requiredRaw = Str::of((string) ($values[$headerIndexes['required']] ?? ''))->lower()->trim()->toString();
            $optionsRaw = trim((string) ($values[$headerIndexes['options']] ?? ''));

            if ($fieldLabel === '' && $fieldType === '') {
                continue;
            }

            if ($fieldLabel === '' || $fieldType === '') {
                throw ValidationException::withMessages([
                    'file' => ['DOCX table row '.($rowIndex + 2).' is missing field or type.'],
                ]);
            }

            if (! in_array($fieldType, FieldService::SUPPORTED_TYPES, true)) {
                throw ValidationException::withMessages([
                    'file' => ["DOCX table row ".($rowIndex + 2)." contains unsupported field type '{$fieldType}'."],
                ]);
            }

            $config = [];

            if (in_array($fieldType, ['select', 'radio', 'checkbox'], true)) {
                if ($optionsRaw === '') {
                    throw ValidationException::withMessages([
                        'file' => ["DOCX field '{$fieldLabel}' requires options."],
                    ]);
                }

                $options = array_values(array_filter(array_map(
                    fn (string $option) => trim($option),
                    preg_split('/\s*,\s*/', $optionsRaw) ?: [],
                )));

                if ($options === []) {
                    throw ValidationException::withMessages([
                        'file' => ["DOCX field '{$fieldLabel}' requires non-empty options."],
                    ]);
                }

                $config['options'] = $options;
            }

            $fields[] = [
                'key' => Str::snake($fieldLabel),
                'label' => $fieldLabel,
                'type' => $fieldType,
                'required' => in_array($requiredRaw, ['yes', 'true', '1'], true),
                'config' => $config,
            ];
        }

        return $fields;
    }

    /**
     * @param  list<AbstractElement>  $elements
     */
    private function findHeadingBefore(array $elements, int $index): ?string
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $text = trim($this->extractElementText($elements[$cursor]));

            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractRowValues(mixed $row): array
    {
        $values = [];

        foreach ($row->getCells() as $cell) {
            $values[] = trim($this->extractElementText($cell));
        }

        return $values;
    }

    private function extractElementText(AbstractElement $element): string
    {
        if ($element instanceof Text) {
            return (string) $element->getText();
        }

        if ($element instanceof TextRun) {
            $parts = [];

            foreach ($element->getElements() as $child) {
                if ($child instanceof Text) {
                    $parts[] = (string) $child->getText();
                }
            }

            return trim(implode('', $parts));
        }

        if ($element instanceof Title) {
            return trim((string) $element->getText());
        }

        if (method_exists($element, 'getElements')) {
            $parts = [];

            foreach ($element->getElements() as $child) {
                if ($child instanceof AbstractElement) {
                    $parts[] = $this->extractElementText($child);
                }
            }

            return trim(implode(' ', array_filter($parts)));
        }

        return '';
    }
}
