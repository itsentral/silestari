<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['version', 'title', 'original_name', 'path', 'mime', 'size', 'sha256', 'change_summary', 'uploaded_by', 'uploaded_by_name'])]
#[Hidden(['path'])]
class PolicyDocument extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['version' => 'integer', 'size' => 'integer'];
    }
}
