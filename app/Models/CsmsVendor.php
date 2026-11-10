<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'owner_id', 'name', 'nib', 'npwp', 'pic', 'email', 'phone', 'business', 'ossRisk', 'branch', 'evaluator', 'validator', 'fieldLocation', 'fieldResult', 'address', 'scope', 'notes', 'status', 'docDate', 'fieldDate', 'finalRisk', 'finalizedAt', 'final_snapshot'])]
class CsmsVendor extends Model
{
    protected $table = 'csms_vendors';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'final_snapshot' => 'array'];
    }
}
