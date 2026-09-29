<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Relationship extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'production_rel_id', 'legacy_edge_id', 'from_type', 'from_legacy_id', 'from_canonical_id',
        'from_name', 'relationship_type', 'to_type', 'to_legacy_id', 'to_canonical_id', 'to_name',
        'evidence_source_id', 'confidence', 'resolution_method', 'notes',
    ];

    public function evidenceSource(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'evidence_source_id');
    }
}
