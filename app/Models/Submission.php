<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Submission extends Model
{
    protected $fillable = [
        'form_id',
        'form_version',
        'schema_snapshot',
        'status',
        'ip_address',
        'user_agent',
        'metadata',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'schema_snapshot' => 'array',
            'metadata' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function submissionAnswers(): HasMany
    {
        return $this->hasMany(SubmissionAnswer::class);
    }
}
