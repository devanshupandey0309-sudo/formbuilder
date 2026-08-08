<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Form extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'slug',
        'description',
        'status',
        'schema',
        'settings',
        'version',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'settings' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(Field::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function aiJobs(): HasMany
    {
        return $this->hasMany(AIJob::class);
    }

    public function formImports(): HasMany
    {
        return $this->hasMany(FormImport::class);
    }
}
