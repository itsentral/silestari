<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['group_uuid', 'kind', 'number', 'title', 'department', 'version', 'original_name', 'path', 'mime', 'size', 'sha256', 'change_summary', 'uploaded_by', 'uploaded_by_name'])]
class DocumentAttachment extends Model
{
    use SoftDeletes;

    public const KINDS = ['pedoman' => 'Pedoman', 'ik' => 'IK', 'form' => 'Form / Standar'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'size' => 'integer', 'original_name' => 'encrypted', 'change_summary' => 'encrypted', 'uploaded_by_name' => 'encrypted'];
    }
}
