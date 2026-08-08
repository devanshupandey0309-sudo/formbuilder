<?php

namespace App\Http\Requests;

use App\Services\FieldService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFieldRequest extends FormRequest
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
        /** @var \App\Models\Form $form */
        $form = $this->route('form');
        /** @var \App\Models\Field $field */
        $field = $this->route('field');

        return [
            'key' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('fields', 'key')
                    ->where('form_id', $form->id)
                    ->ignore($field->id),
            ],
            'type' => ['sometimes', 'required', 'string', Rule::in(FieldService::SUPPORTED_TYPES)],
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            'placeholder' => ['nullable', 'string', 'max:255'],
            'is_required' => ['sometimes', 'boolean'],
            'config' => ['nullable', 'array'],
            'validation' => ['nullable', 'array'],
        ];
    }
}
