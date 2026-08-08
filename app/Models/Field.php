<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Field extends Model
{
    /** @use HasFactory<\Database\Factories\FieldFactory> */
    use HasFactory;
    protected $fillable = [
        'form_id',
        'section_id',
        'key',
        'label',
        'type',
        'sort_order',
        'config',
        'validation',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'validation' => 'array',
            'is_required' => 'boolean',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
