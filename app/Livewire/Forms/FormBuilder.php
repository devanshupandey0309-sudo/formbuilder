<?php

namespace App\Livewire\Forms;

use App\Models\Field;
use App\Models\Form;
use App\Models\Section;
use App\Models\AIJob;
use App\Services\AIFormApplyService;
use App\Services\AIFormGenerationService;
use App\Services\FieldService;
use App\Services\FormSchemaValidator;
use App\Services\FormService;
use App\Services\FormStructureApplyService;
use App\Services\SectionService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.builder')]
class FormBuilder extends Component
{
    public Form $form;

    public string $formTitle = '';

    public ?string $formDescription = null;

    public string $activeTab = 'builder';

    public string $jsonEditor = '';

    public ?string $jsonError = null;

    public ?int $selectedSectionId = null;

    public ?int $selectedFieldId = null;

    public ?string $statusMessage = null;

    public string $statusType = 'success';

    public bool $isProcessing = false;

    /** @var array<string, mixed> */
    public array $fieldEditor = [];

    public string $aiPrompt = '';

    public ?int $activeAiJobId = null;

    public ?string $aiJobStatus = null;

    public ?string $aiJobType = null;

    public ?string $aiJobError = null;

    public ?string $aiProposedJson = null;

    public function mount(Form $form): void
    {
        $this->authorize('update', $form);
        $this->loadForm($form);
        $this->syncJsonFromForm(app(FormService::class));
    }

    public function updatedFormTitle(): void
    {
        $this->saveFormMetadata();
    }

    public function updatedFormDescription(): void
    {
        $this->saveFormMetadata();
    }

    public function setTab(string $tab): void
    {
        if ($tab === 'json') {
            $this->syncJsonFromForm(app(FormService::class));
        }

        $this->activeTab = $tab;
    }

    public function addSection(SectionService $sectionService): void
    {
        $this->runBuilderAction(function () use ($sectionService) {
            $section = $sectionService->createSection($this->form, [
                'title' => 'New Section',
            ]);

            $this->selectedSectionId = $section->id;
            $this->selectedFieldId = null;
            $this->flashSuccess('Section added.');
        });
    }

    public function updateSectionTitle(int $sectionId, string $title, SectionService $sectionService): void
    {
        if (blank($title)) {
            return;
        }

        $section = $this->findOwnedSection($sectionId);

        $this->runBuilderAction(function () use ($section, $title, $sectionService) {
            $sectionService->updateSection($section, ['title' => $title]);
            $this->flashSuccess('Section updated.');
        }, refreshJson: false);
    }

    public function deleteSection(int $sectionId, SectionService $sectionService): void
    {
        $section = $this->findOwnedSection($sectionId);

        $this->runBuilderAction(function () use ($section, $sectionService) {
            $sectionService->deleteSection($section);

            if ($this->selectedSectionId === $section->id) {
                $this->selectedSectionId = null;
                $this->selectedFieldId = null;
            }

            $this->flashSuccess('Section deleted.');
        });
    }

    /**
     * @param  list<int>  $sectionIds
     */
    public function reorderSections(array $sectionIds, SectionService $sectionService): void
    {
        $this->runBuilderAction(function () use ($sectionIds, $sectionService) {
            $sectionService->reorderSections($this->form, $sectionIds);
            $this->flashSuccess('Sections reordered.');
        }, refreshJson: false);
    }

    public function selectSection(int $sectionId): void
    {
        $this->findOwnedSection($sectionId);
        $this->selectedSectionId = $sectionId;
        $this->selectedFieldId = null;
        $this->fieldEditor = [];
    }

    public function addField(int $sectionId, FieldService $fieldService): void
    {
        $section = $this->findOwnedSection($sectionId);

        $this->runBuilderAction(function () use ($section, $fieldService) {
            $key = $this->generateDefaultFieldKey('new_field');

            $field = $fieldService->createField($this->form, $section, [
                'key' => $key,
                'label' => 'New Field',
                'type' => 'text',
                'is_required' => false,
            ]);

            $this->selectedSectionId = $section->id;
            $this->selectField($field->id);
            $this->flashSuccess('Field added.');
        });
    }

    public function selectField(int $fieldId): void
    {
        $field = $this->findOwnedField($fieldId);

        $this->selectedSectionId = $field->section_id;
        $this->selectedFieldId = $field->id;
        $this->fieldEditor = $this->fieldToEditorState($field);
    }

    public function saveSelectedField(FieldService $fieldService): void
    {
        if ($this->selectedFieldId === null) {
            return;
        }

        $field = $this->findOwnedField($this->selectedFieldId);

        $validated = $this->validate([
            'fieldEditor.label' => ['required', 'string', 'max:255'],
            'fieldEditor.key' => ['required', 'string', 'max:255', 'regex:/^[a-z][a-z0-9_]*$/'],
            'fieldEditor.type' => ['required', 'string', 'in:'.implode(',', FieldService::SUPPORTED_TYPES)],
            'fieldEditor.is_required' => ['boolean'],
            'fieldEditor.placeholder' => ['nullable', 'string', 'max:255'],
            'fieldEditor.optionsText' => ['nullable', 'string'],
            'fieldEditor.validation_min' => ['nullable', 'numeric'],
            'fieldEditor.validation_max' => ['nullable', 'numeric'],
        ]);

        $editor = $validated['fieldEditor'];

        if ($editor['key'] !== $field->key
            && $this->form->fields()->where('key', $editor['key'])->where('id', '!=', $field->id)->exists()) {
            $this->addError('fieldEditor.key', 'Field key must be unique within the form.');

            return;
        }

        $payload = [
            'label' => $editor['label'],
            'key' => $editor['key'],
            'type' => $editor['type'],
            'is_required' => (bool) $editor['is_required'],
            'placeholder' => $editor['placeholder'] ?? null,
            'validation' => $this->buildValidationPayload($editor),
            'config' => $this->buildConfigPayload($editor),
        ];

        $this->runBuilderAction(function () use ($field, $payload, $fieldService) {
            $fieldService->updateField($field, $payload);
            $this->selectField($field->id);
            $this->flashSuccess('Field saved.');
        });
    }

    public function duplicateField(int $fieldId, FieldService $fieldService): void
    {
        $field = $this->findOwnedField($fieldId);

        $this->runBuilderAction(function () use ($field, $fieldService) {
            $duplicate = $fieldService->duplicateField($field);
            $this->selectField($duplicate->id);
            $this->flashSuccess('Field duplicated.');
        });
    }

    public function deleteField(int $fieldId, FieldService $fieldService): void
    {
        $field = $this->findOwnedField($fieldId);

        $this->runBuilderAction(function () use ($field, $fieldService) {
            $fieldService->deleteField($field);

            if ($this->selectedFieldId === $field->id) {
                $this->selectedFieldId = null;
                $this->fieldEditor = [];
            }

            $this->flashSuccess('Field deleted.');
        });
    }

    /**
     * @param  list<int>  $fieldIds
     */
    public function reorderFields(int $sectionId, array $fieldIds, FieldService $fieldService): void
    {
        $section = $this->findOwnedSection($sectionId);

        $this->runBuilderAction(function () use ($section, $fieldIds, $fieldService) {
            $fieldService->reorderFields($section, $fieldIds);
            $this->flashSuccess('Fields reordered.');
        }, refreshJson: false);
    }

    public function applyJson(
        FormSchemaValidator $schemaValidator,
        FormStructureApplyService $structureApplyService,
        FormService $formService,
    ): void {
        $this->jsonError = null;

        try {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($this->jsonEditor, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                throw ValidationException::withMessages([
                    'json' => ['JSON must decode to an object.'],
                ]);
            }

            $validated = $schemaValidator->validateCompiledSchema($decoded);
        } catch (\JsonException $exception) {
            $this->jsonError = 'Invalid JSON: '.$exception->getMessage();

            return;
        } catch (ValidationException $exception) {
            $this->jsonError = 'Invalid JSON: '.collect($exception->errors())->flatten()->first();

            return;
        }

        $this->runBuilderAction(function () use ($validated, $structureApplyService, $formService) {
            $structureApplyService->apply($this->form, $validated);
            $this->selectedFieldId = null;
            $this->selectedSectionId = null;
            $this->fieldEditor = [];
            $this->activeTab = 'builder';
            $this->flashSuccess('JSON applied to builder.');
        });

        $this->syncJsonFromForm($formService);
    }

    public function publish(FormService $formService): void
    {
        $this->runBuilderAction(function () use ($formService) {
            $formService->publishForm($this->form);
            $this->flashSuccess('Form published successfully.');
        });
    }

    public function unpublish(FormService $formService): void
    {
        $this->runBuilderAction(function () use ($formService) {
            $formService->unpublishForm($this->form);
            $this->flashSuccess('Form moved back to draft.');
        });
    }

    public function startAiGenerate(AIFormGenerationService $generationService): void
    {
        $this->validate([
            'aiPrompt' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $aiJob = $generationService->queueGenerate(auth()->user(), $this->form, $this->aiPrompt);
        $this->syncAiJobState($aiJob);
        $this->flashSuccess('AI generation queued.');
    }

    public function startAiEdit(AIFormGenerationService $generationService): void
    {
        $this->validate([
            'aiPrompt' => ['required', 'string', 'min:5', 'max:5000'],
        ]);

        $aiJob = $generationService->queueEdit(auth()->user(), $this->form, $this->aiPrompt);
        $this->syncAiJobState($aiJob);
        $this->flashSuccess('AI edit queued.');
    }

    public function refreshAiJob(AIFormGenerationService $generationService): void
    {
        if ($this->activeAiJobId === null) {
            return;
        }

        $aiJob = AIJob::query()->find($this->activeAiJobId);

        if ($aiJob === null || $aiJob->form_id !== $this->form->id) {
            $this->discardAiJob();

            return;
        }

        $aiJob = $generationService->getJob($this->form, $aiJob);
        $this->syncAiJobState($aiJob);
    }

    public function applyAiJob(AIFormApplyService $applyService): void
    {
        if ($this->activeAiJobId === null) {
            return;
        }

        $aiJob = AIJob::query()->findOrFail($this->activeAiJobId);

        $this->runBuilderAction(function () use ($applyService, $aiJob) {
            $applyService->apply($this->form, $aiJob);
            $this->discardAiJob();
            $this->flashSuccess('AI changes applied to the form.');
        });
    }

    public function discardAiJob(): void
    {
        $this->activeAiJobId = null;
        $this->aiJobStatus = null;
        $this->aiJobType = null;
        $this->aiJobError = null;
        $this->aiProposedJson = null;
    }

    public function render(): View
    {
        return view('livewire.forms.form-builder', [
            'supportedTypes' => FieldService::SUPPORTED_TYPES,
            'hasUnsavedSchema' => $this->form->schema === null && $this->form->wasChanged() === false,
            'needsRepublish' => $this->form->status === 'published' && $this->form->schema === null,
        ]);
    }

    private function saveFormMetadata(): void
    {
        $this->validate([
            'formTitle' => ['required', 'string', 'max:255'],
            'formDescription' => ['nullable', 'string', 'max:1000'],
        ]);

        app(FormService::class)->updateForm($this->form, [
            'title' => $this->formTitle,
            'description' => $this->formDescription,
        ]);

        $this->loadForm($this->form);
    }

    /**
     * @param  callable(): void  $callback
     */
    private function runBuilderAction(callable $callback, bool $refreshJson = true): void
    {
        $this->isProcessing = true;

        try {
            $callback();
            $this->loadForm($this->form);

            if ($refreshJson) {
                $this->syncJsonFromForm(app(FormService::class));
            }
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? 'Validation failed.';
            $this->flashError($message);
        } finally {
            $this->isProcessing = false;
        }
    }

    private function loadForm(Form $form): void
    {
        $form->load([
            'sections' => fn ($query) => $query->orderBy('sort_order'),
            'sections.fields' => fn ($query) => $query->orderBy('sort_order'),
        ]);

        $this->form = $form;
        $this->formTitle = $form->title;
        $this->formDescription = $form->description;
    }

    private function syncJsonFromForm(FormService $formService): void
    {
        $this->jsonEditor = json_encode(
            $formService->compileSchema($this->form),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $this->jsonError = null;
    }

    private function findOwnedSection(int $sectionId): Section
    {
        $section = $this->form->sections->firstWhere('id', $sectionId);

        if ($section === null) {
            abort(404);
        }

        return $section;
    }

    private function findOwnedField(int $fieldId): Field
    {
        foreach ($this->form->sections as $section) {
            $field = $section->fields->firstWhere('id', $fieldId);

            if ($field !== null) {
                return $field;
            }
        }

        abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldToEditorState(Field $field): array
    {
        $options = $field->config['options'] ?? [];

        $optionsText = collect($options)->map(function ($option) {
            if (is_array($option)) {
                return (string) ($option['label'] ?? $option['value'] ?? '');
            }

            return (string) $option;
        })->implode("\n");

        return [
            'label' => $field->label,
            'key' => $field->key,
            'type' => $field->type,
            'is_required' => $field->is_required,
            'placeholder' => $field->config['placeholder'] ?? '',
            'optionsText' => $optionsText,
            'validation_min' => $field->validation['min'] ?? '',
            'validation_max' => $field->validation['max'] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $editor
     * @return array<string, mixed>|null
     */
    private function buildConfigPayload(array $editor): ?array
    {
        $config = [];

        if (($editor['placeholder'] ?? '') !== '') {
            $config['placeholder'] = $editor['placeholder'];
        }

        if (in_array($editor['type'], ['select', 'radio', 'checkbox'], true)) {
            $options = collect(preg_split('/\r\n|\r|\n/', (string) ($editor['optionsText'] ?? '')) ?: [])
                ->map(fn ($line) => trim($line))
                ->filter()
                ->values()
                ->all();

            if ($options !== []) {
                $config['options'] = $options;
            }
        }

        return $config === [] ? null : $config;
    }

    /**
     * @param  array<string, mixed>  $editor
     * @return array<string, mixed>|null
     */
    private function buildValidationPayload(array $editor): ?array
    {
        if ($editor['type'] !== 'number') {
            return null;
        }

        $validation = [];

        if ($editor['validation_min'] !== '' && $editor['validation_min'] !== null) {
            $validation['min'] = $editor['validation_min'];
        }

        if ($editor['validation_max'] !== '' && $editor['validation_max'] !== null) {
            $validation['max'] = $editor['validation_max'];
        }

        return $validation === [] ? null : $validation;
    }

    private function generateDefaultFieldKey(string $base): string
    {
        $key = Str::snake($base);
        $counter = 1;

        while ($this->form->fields()->where('key', $key)->exists()) {
            $key = Str::snake($base).'_'.$counter;
            $counter++;
        }

        return $key;
    }

    private function flashSuccess(string $message): void
    {
        $this->statusMessage = $message;
        $this->statusType = 'success';
    }

    private function flashError(string $message): void
    {
        $this->statusMessage = $message;
        $this->statusType = 'error';
    }

    private function syncAiJobState(AIJob $aiJob): void
    {
        $this->activeAiJobId = $aiJob->id;
        $this->aiJobStatus = $aiJob->status;
        $this->aiJobType = $aiJob->type;
        $this->aiJobError = $aiJob->error_message;
        $this->aiProposedJson = $aiJob->validated_output
            ? json_encode($aiJob->validated_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : null;
    }
}
