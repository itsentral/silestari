<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['year', 'department', 'creator_id', 'copied_from_id', 'payload', 'signatures', 'revision'])]
#[Hidden(['payload', 'signatures'])]
class CommunicationMatrix extends Model
{
    protected function casts(): array
    {
        return ['year' => 'integer', 'revision' => 'integer', 'payload' => 'encrypted:array', 'signatures' => 'encrypted:array'];
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(CommunicationEvidence::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CommunicationEvent::class);
    }
}
