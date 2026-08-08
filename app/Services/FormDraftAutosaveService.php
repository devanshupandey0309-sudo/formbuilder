<?php

namespace App\Services;

use App\Models\Field;
use App\Models\Form;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FormDraftAutosaveService
{
    public function __construct(
        private readonly FormService $formService,
        private readonly FieldService $fieldService,
        private readonly FormSchemaValidator $schemaValidator,
        private readonly FormStructureApplyService $structureApplyService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function autosave(Form $form, int $expectedRevision, array $payload): Form
    {
        return DB::transaction(function () use ($form, $expectedRevision, $payload) {
            /** @var Form $lockedForm */
            $lockedForm = Form::query()->lockForUpdate()->findOrFail($form->id);

            $this->assertRevision($lockedForm, $expectedRevision);

            $publishedSchema = $lockedForm->schema;
            $publishedVersion = $lockedForm->version;
            $publishedStatus = $lockedForm->status;
            $publishedAt = $lockedForm->published_at;

            if (array_key_exists('title', $payload) || array_key_exists('description', $payload)) {
                $this->saveMetadataDraft($lockedForm, $payload);
            }

            if (! empty($payload['field_id']) && is_array($payload['field_editor'] ?? null)) {
                $field = $this->findOwnedField($lockedForm, (int) $payload['field_id']);
                $this->saveFieldDraft($lockedForm, $field, $payload['field_editor']);
            }

            if (! empty($payload['json_editor']) && ($payload['apply_json'] ?? false)) {
                $this->applyJsonEditorDraft($lockedForm, (string) $payload['json_editor']);
            }

            $lockedForm->update([
                'draft_revision' => $lockedForm->draft_revision + 1,
                'draft_saved_at' => now(),
            ]);

            $savedForm = $lockedForm->fresh();

            $this->assertPublishedInvariants(
                $savedForm,
                $publishedSchema,
                $publishedVersion,
                $publishedStatus,
                $publishedAt,
            );

            return $savedForm;
        });
    }

    public function touchDraft(Form $form, ?int $expectedRevision = null): Form
    {
        return DB::transaction(function () use ($form, $expectedRevision) {
            /** @var Form $lockedForm */
            $lockedForm = Form::query()->lockForUpdate()->findOrFail($form->id);

            if ($expectedRevision !== null) {
                $this->assertRevision($lockedForm, $expectedRevision);
            }

            $lockedForm->update([
                'draft_revision' => $lockedForm->draft_revision + 1,
                'draft_saved_at' => now(),
            ]);

            return $lockedForm->fresh();
        });
    }

    public function assertRevision(Form $form, int $expectedRevision): void
    {
        if ((int) $form->draft_revision !== $expectedRevision) {
            throw ValidationException::withMessages([
                'draft_revision' => ['A newer draft exists on the server. Refresh to continue.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function saveMetadataDraft(Form $form, array $payload): void
    {
        $updates = [];

        if (array_key_exists('title', $payload) && ! blank($payload['title'])) {
            $updates['title'] = (string) $payload['title'];
        }

        if (array_key_exists('description', $payload)) {
            $updates['description'] = $payload['description'];
        }

        if ($updates === []) {
            return;
        }

        $this->formService->updateForm($form, $updates);
    }

    /**
     * @param  array<string, mixed>  $editor
     */
    public function saveFieldDraft(Form $form, Field $field, array $editor): Field
    {
        if ($field->form_id !== $form->id) {
            abort(404);
        }

        $label = trim((string) ($editor['label'] ?? ''));
        $key = trim((string) ($editor['key'] ?? $field->key));
        $type = (string) ($editor['type'] ?? $field->type);

        if ($label === '') {
            $label = $field->label;
        }

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            $key = $field->key;
        }

        if ($key !== $field->key
            && $form->fields()->where('key', $key)->where('id', '!=', $field->id)->exists()) {
            $key = $field->key;
        }

        if (! in_array($type, FieldService::SUPPORTED_TYPES, true)) {
            $type = $field->type;
        }

        return $this->fieldService->updateField($field, [
            'label' => $label,
            'key' => $key,
            'type' => $type,
            'is_required' => (bool) ($editor['is_required'] ?? $field->is_required),
            'placeholder' => $editor['placeholder'] ?? null,
            'validation' => $this->buildValidationPayload($editor, $type),
            'config' => $this->buildConfigPayload($editor, $type),
        ]);
    }

    private function applyJsonEditorDraft(Form $form, string $jsonEditor): void
    {
        try {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($jsonEditor, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (! is_array($decoded)) {
            return;
        }

        try {
            $validated = $this->schemaValidator->validateCompiledSchema($decoded);
        } catch (ValidationException) {
            return;
        }

        $this->applyStructurePreservingPublication($form, $validated);
    }

    /**
     * @param  array<string, mixed>  $structure
     */
    public function applyStructurePreservingPublication(Form $form, array $structure): Form
    {
        $wasPublished = $form->status === 'published';
        $version = $form->version;
        $publishedAt = $form->published_at;

        $this->structureApplyService->apply($form, $structure);
        $this->restorePublicationState($form, $wasPublished, $version, $publishedAt);

        return $form->fresh(['sections.fields']);
    }

    private function restorePublicationState(
        Form $form,
        bool $wasPublished,
        int $version,
        ?\Illuminate\Support\Carbon $publishedAt,
    ): void {
        if (! $wasPublished) {
            return;
        }

        $form->update([
            'status' => 'published',
            'version' => $version,
            'published_at' => $publishedAt,
            'schema' => null,
        ]);
    }

    private function findOwnedField(Form $form, int $fieldId): Field
    {
        $field = $form->fields()->whereKey($fieldId)->first();

        if ($field === null) {
            abort(404);
        }

        return $field;
    }

    /**
     * @param  array<string, mixed>  $editor
     * @return array<string, mixed>|null
     */
    private function buildConfigPayload(array $editor, string $type): ?array
    {
        $config = [];

        if (($editor['placeholder'] ?? '') !== '') {
            $config['placeholder'] = $editor['placeholder'];
        }

        if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
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
    private function buildValidationPayload(array $editor, string $type): ?array
    {
        if ($type !== 'number') {
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

    private function assertPublishedInvariants(
        Form $form,
        ?array $publishedSchema,
        int $publishedVersion,
        string $publishedStatus,
        ?\Illuminate\Support\Carbon $publishedAt,
    ): void {
        if ($publishedStatus !== 'published') {
            return;
        }

        if ($form->status !== 'published') {
            throw ValidationException::withMessages([
                'form' => ['Autosave must not unpublish a published form.'],
            ]);
        }

        if ($form->version !== $publishedVersion) {
            throw ValidationException::withMessages([
                'form' => ['Autosave must not change the published version.'],
            ]);
        }

        if ($form->published_at?->eq($publishedAt) === false) {
            throw ValidationException::withMessages([
                'form' => ['Autosave must not change the published timestamp.'],
            ]);
        }

        if ($publishedSchema !== null && $form->schema !== null && $form->schema != $publishedSchema) {
            throw ValidationException::withMessages([
                'form' => ['Autosave must not modify the published schema snapshot.'],
            ]);
        }
    }
}
