<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIJob extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

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
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'raw_output' => 'array',
            'validated_output' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function terminalStatuses(): array
    {
        return [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::terminalStatuses(), true);
    }

    public function wasApplied(): bool
    {
        return $this->applied_at !== null;
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
