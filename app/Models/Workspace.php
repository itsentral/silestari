<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['source', 'payload', 'revision', 'history'])]
#[Hidden(['payload', 'history'])]
class Workspace extends Model
{
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'history' => 'encrypted:array', 'revision' => 'integer'];
    }
}
