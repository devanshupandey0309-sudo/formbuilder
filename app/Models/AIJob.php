<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIJob extends Model
{
    protected $table = 'ai_jobs';

    protected $fillable = [
        'user_id',
        'form_id',
        'type',
        'status',
        'prompt',
        'input',
        'raw_output',
        'validated_output',
        'error_message',
        'attempt_count',
        'max_attempts',
        'laravel_job_id',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'raw_output' => 'array',
            'validated_output' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
}
