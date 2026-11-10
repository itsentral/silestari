<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['communication_matrix_id', 'material_id', 'original_name', 'path', 'mime', 'size', 'sha256', 'uploaded_by', 'uploaded_by_name'])]
class CommunicationEvidence extends Model
{
    use SoftDeletes;

    protected $table = 'communication_evidence';

    protected function casts(): array
    {
        return ['original_name' => 'encrypted', 'uploaded_by_name' => 'encrypted', 'size' => 'integer'];
    }
}
