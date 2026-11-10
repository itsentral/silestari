<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['communication_matrix_id', 'actor_id', 'action', 'revision', 'snapshot', 'created_at'])]
#[Hidden(['snapshot'])]
class CommunicationEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'revision' => 'integer'];
    }
}
