<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CuratorProfile extends Model
{
    public const BLURB_LIMIT = 300;

    protected $fillable = ['user_id', 'display_name', 'blurb', 'is_public'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
