<?php

namespace App\Services;

use App\Models\Field;
use App\Models\Form;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FormService
{
    public function createForm(User $user, array $data): Form
    {
        return DB::transaction(function () use ($user, $data) {
            return Form::create([
                'user_id' => $user->id,
                'title' => $data['title'],
                'slug' => $this->generateUniqueSlug($data['title']),
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'settings' => $data['settings'] ?? null,
                'version' => 1,
            ]);
        });
    }

    public function updateForm(Form $form, array $data): Form
    {
        $form->update([
            'title' => $data['title'] ?? $form->title,
            'description' => array_key_exists('description', $data)
                ? $data['description']
                : $form->description,
            'settings' => array_key_exists('settings', $data)
                ? $data['settings']
                : $form->settings,
        ]);

        return $form->fresh();
    }

    public function deleteForm(Form $form): void
    {
        $form->delete();
    }

    public function publishForm(Form $form): Form
    {
        return DB::transaction(function () use ($form) {
            $this->assertFormIsPublishable($form);

            $compiledSchema = $this->compileSchema($form);

            if ($form->schema !== null && $this->schemaContentChanged($form->schema, $compiledSchema)) {
                $form->version = $form->version + 1;
                $compiledSchema = $this->compileSchema($form);
            }

            $form->update([
                'status' => 'published',
                'schema' => $compiledSchema,
                'published_at' => now(),
            ]);

            return $form->fresh();
        });
    }

    public function unpublishForm(Form $form): Form
    {
        $form->update([
            'status' => 'draft',
        ]);

        return $form->fresh();
    }

    public function compileSchema(Form $form): array
    {
        $form->load([
            'sections' => fn ($query) => $query->orderBy('sort_order'),
            'sections.fields' => fn ($query) => $query->orderBy('sort_order'),
        ]);

        return [
            'version' => $form->version,
            'title' => $form->title,
            'description' => $form->description,
            'sections' => $form->sections->map(fn (Section $section) => [
                'id' => $section->id,
                'title' => $section->title,
                'description' => $section->description,
                'fields' => $section->fields->map(fn (Field $field) => [
                    'key' => $field->key,
                    'type' => $field->type,
                    'label' => $field->label,
                    'required' => $field->is_required,
                    'config' => $field->config ?? [],
                    'validation' => $field->validation ?? [],
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function generateUniqueSlug(string $title): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = 'form';
        }

        $slug = $base;
        $counter = 1;

        while (Form::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    private function assertFormIsPublishable(Form $form): void
    {
        $form->load([
            'sections.fields' => fn ($query) => $query->orderBy('sort_order'),
        ]);

        if ($form->sections->isEmpty()) {
            throw ValidationException::withMessages([
                'form' => ['A form must have at least one section before it can be published.'],
            ]);
        }

        foreach ($form->sections as $section) {
            if ($section->fields->isEmpty()) {
                throw ValidationException::withMessages([
                    'form' => ['Each section must contain at least one field before the form can be published.'],
                ]);
            }

            foreach ($section->fields as $field) {
                if (blank($field->key) || blank($field->label) || blank($field->type)) {
                    throw ValidationException::withMessages([
                        'form' => ['All fields must have a key, label, and type before the form can be published.'],
                    ]);
                }
            }
        }
    }

    private function schemaContentChanged(array $storedSchema, array $compiledSchema): bool
    {
        $stored = $storedSchema;
        $compiled = $compiledSchema;

        unset($stored['version'], $compiled['version']);

        return $stored != $compiled;
    }
}
