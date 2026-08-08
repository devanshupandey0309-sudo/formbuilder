<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EditFormAIRequest extends FormRequest
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
            'prompt' => ['required', 'string', 'min:5', 'max:5000'],
        ];
    }
}
