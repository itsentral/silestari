<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['signature', 'snapshot'])]
#[Fillable(['controlled_document_id', 'procedure_revision_id', 'actor_id', 'actor_name', 'actor_username', 'action', 'from_status', 'to_status', 'cycle', 'reason', 'content_hash', 'snapshot', 'signature', 'created_at'])]
class ProcedureEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'signature' => 'encrypted', 'created_at' => 'datetime', 'cycle' => 'integer'];
    }
}
