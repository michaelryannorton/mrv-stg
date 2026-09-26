<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicSubmission extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'uuid', 'submission_type', 'email', 'name', 'source_url', 'submitted_data',
        'status', 'event_id', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_data' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
