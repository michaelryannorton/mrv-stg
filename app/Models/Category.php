<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'parent_id', 'description', 'sort_order', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_categories');
    }

    public function defaultForSources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'source_default_categories');
    }
}
