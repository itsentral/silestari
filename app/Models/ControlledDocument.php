<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'department', 'creator_id', 'current_revision_id', 'published_revision_id', 'is_archived'])]
class ControlledDocument extends Model
{
    protected function casts(): array
    {
        return ['is_archived' => 'boolean', 'creator_id' => 'integer', 'current_revision_id' => 'integer', 'published_revision_id' => 'integer'];
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(ProcedureRevision::class, 'current_revision_id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(ProcedureRevision::class, 'published_revision_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ProcedureRevision::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProcedureEvent::class);
    }
}
