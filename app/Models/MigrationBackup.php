<?php

namespace App\Models;

use Database\Factories\MigrationBackupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['source', 'filename', 'checksum', 'record_count', 'record_counts', 'payload'])]
#[Hidden(['payload'])]
class MigrationBackup extends Model
{
    /** @use HasFactory<MigrationBackupFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['record_count' => 'integer', 'record_counts' => 'array', 'payload' => 'encrypted:array'];
    }
}
