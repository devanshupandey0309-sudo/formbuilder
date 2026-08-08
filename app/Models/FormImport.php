<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormImport extends Model
{
    protected $fillable = [
        'user_id',
        'form_id',
        'source_type',
        'original_filename',
        'file_path',
        'status',
        'detected_structure',
        'field_candidates',
        'ambiguities',
        'mapping',
        'preview_data',
        'error_message',
        'ai_job_id',
    ];

    protected function casts(): array
    {
        return [
            'detected_structure' => 'array',
            'field_candidates' => 'array',
            'ambiguities' => 'array',
            'mapping' => 'array',
            'preview_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function aiJob(): BelongsTo
    {
        return $this->belongsTo(AIJob::class);
    }
}
