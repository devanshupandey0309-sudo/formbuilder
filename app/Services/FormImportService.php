<?php

namespace App\Services;

use App\Contracts\FormImportParser;
use App\Models\Form;
use App\Models\FormImport;
use App\Models\User;
use App\Services\AI\AIOutputValidator;
use App\Services\Import\DocxFormParser;
use App\Services\Import\XlsxFormParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class FormImportService
{
    public const MAX_FILE_SIZE_KB = 5120;

    /** @var array<string, list<string>> */
    private const ALLOWED_TYPES = [
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
    ];

    public function __construct(
        private readonly AIOutputValidator $validator,
        private readonly FormStructureApplyService $structureApplyService,
        private readonly DocxFormParser $docxParser,
        private readonly XlsxFormParser $xlsxParser,
    ) {}

    public function createImport(User $user, Form $form, UploadedFile $file): FormImport
    {
        $sourceType = $this->validateUploadedFile($file);

        $storedPath = $file->store('form-imports');

        if ($storedPath === false) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file could not be stored.'],
            ]);
        }

        $import = FormImport::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'source_type' => $sourceType,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'status' => 'pending',
        ]);

        return $this->processImport($import);
    }

    public function getImport(Form $form, FormImport $formImport): FormImport
    {
        if ($formImport->form_id !== $form->id) {
            abort(404);
        }

        return $formImport;
    }

    public function getPreview(Form $form, FormImport $formImport): array
    {
        $formImport = $this->getImport($form, $formImport);

        if ($formImport->status !== 'preview_ready') {
            throw ValidationException::withMessages([
                'form_import' => ['Preview is only available for imports in preview_ready status.'],
            ]);
        }

        return [
            'form_import' => $formImport,
            'preview' => $formImport->preview_data,
        ];
    }

    public function commit(Form $form, FormImport $formImport): Form
    {
        $formImport = $this->getImport($form, $formImport);

        if ($formImport->status !== 'preview_ready') {
            throw ValidationException::withMessages([
                'form_import' => ['Only preview-ready imports can be committed.'],
            ]);
        }

        if (empty($formImport->preview_data)) {
            throw ValidationException::withMessages([
                'form_import' => ['The import does not contain preview data to commit.'],
            ]);
        }

        return DB::transaction(function () use ($form, $formImport) {
            $form = $this->structureApplyService->apply($form, $formImport->preview_data);

            $formImport->update([
                'status' => 'committed',
            ]);

            return $form;
        });
    }

    private function processImport(FormImport $import): FormImport
    {
        $import->update(['status' => 'processing']);

        $absolutePath = Storage::path($import->file_path);

        try {
            $parser = $this->resolveParser($import->source_type);
            $detectedStructure = $parser->parse($absolutePath);
            $validatedStructure = $this->validator->validate($detectedStructure);

            $import->update([
                'status' => 'preview_ready',
                'detected_structure' => $detectedStructure,
                'preview_data' => $validatedStructure,
                'field_candidates' => $this->extractFieldCandidates($detectedStructure),
                'ambiguities' => null,
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            $import->update([
                'status' => 'failed',
                'error_message' => $this->resolveErrorMessage($exception),
            ]);
        }

        return $import->fresh();
    }

    private function validateUploadedFile(UploadedFile $file): string
    {
        if ($file->getSize() > self::MAX_FILE_SIZE_KB * 1024) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file exceeds the maximum allowed size.'],
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! array_key_exists($extension, self::ALLOWED_TYPES)) {
            throw ValidationException::withMessages([
                'file' => ['Only DOCX and XLSX files are supported.'],
            ]);
        }

        $mimeType = (string) $file->getMimeType();

        if (! in_array($mimeType, self::ALLOWED_TYPES[$extension], true)) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file MIME type is not allowed.'],
            ]);
        }

        return $extension;
    }

    private function resolveParser(string $sourceType): FormImportParser
    {
        return match ($sourceType) {
            'docx' => $this->docxParser,
            'xlsx' => $this->xlsxParser,
            default => throw ValidationException::withMessages([
                'file' => ['Unsupported import file type.'],
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return list<array<string, mixed>>
     */
    private function extractFieldCandidates(array $structure): array
    {
        $candidates = [];

        foreach ($structure['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $candidates[] = [
                    'section' => $section['title'] ?? null,
                    'key' => $field['key'] ?? null,
                    'label' => $field['label'] ?? null,
                    'type' => $field['type'] ?? null,
                ];
            }
        }

        return $candidates;
    }

    private function resolveErrorMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return collect($exception->errors())->flatten()->first()
                ?? 'Import validation failed.';
        }

        return 'Import processing failed.';
    }
}
