<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFormDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('form')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'draft_revision' => ['required', 'integer', 'min:0'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'field_id' => ['sometimes', 'nullable', 'integer'],
            'field_editor' => ['sometimes', 'nullable', 'array'],
            'json_editor' => ['sometimes', 'nullable', 'string'],
            'apply_json' => ['sometimes', 'boolean'],
        ];
    }
}
