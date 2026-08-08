<?php

namespace App\Http\Requests;

use App\Services\FormImportService;
use Illuminate\Foundation\Http\FormRequest;

class StoreFormImportRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'max:'.FormImportService::MAX_FILE_SIZE_KB,
                'mimes:docx,xlsx',
            ],
        ];
    }
}
