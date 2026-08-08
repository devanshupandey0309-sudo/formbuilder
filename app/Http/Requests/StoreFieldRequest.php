<?php

namespace App\Http\Requests;

use App\Services\FieldService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFieldRequest extends FormRequest
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

        return [
            'key' => [
                'required',
                'string',
                'max:255',
                Rule::unique('fields', 'key')->where('form_id', $form->id),
            ],
            'type' => ['required', 'string', Rule::in(FieldService::SUPPORTED_TYPES)],
            'label' => ['required', 'string', 'max:255'],
            'placeholder' => ['nullable', 'string', 'max:255'],
            'is_required' => ['sometimes', 'boolean'],
            'config' => ['nullable', 'array'],
            'validation' => ['nullable', 'array'],
        ];
    }
}
