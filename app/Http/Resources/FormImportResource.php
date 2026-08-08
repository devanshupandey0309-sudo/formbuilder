<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FormImport */
class FormImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'form_id' => $this->form_id,
            'source_type' => $this->source_type,
            'original_filename' => $this->original_filename,
            'status' => $this->status,
            'detected_structure' => $this->detected_structure,
            'field_candidates' => $this->field_candidates,
            'ambiguities' => $this->ambiguities,
            'mapping' => $this->mapping,
            'preview_data' => $this->preview_data,
            'error_message' => $this->error_message,
            'ai_job_id' => $this->ai_job_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
