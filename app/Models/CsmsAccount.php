<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['internal_user_id', 'name', 'email', 'username', 'password', 'roles', 'active', 'auth_version'])]
#[Hidden(['password'])]
class CsmsAccount extends Model
{
    protected $attributes = ['active' => true, 'auth_version' => 1];

    protected function casts(): array
    {
        return ['roles' => 'array', 'password' => 'hashed', 'active' => 'boolean', 'auth_version' => 'integer', 'internal_user_id' => 'integer'];
    }

    public function hasRole(string ...$roles): bool
    {
        return $this->active && (in_array('admin', $this->roles ?? [], true) || count(array_intersect($roles, $this->roles ?? [])) > 0);
    }

    public function publicData(): array
    {
        return ['id' => $this->id, 'username' => $this->email ?? $this->username, 'name' => $this->name, 'role' => $this->roles, 'linked' => (bool) $this->internal_user_id];
    }
}
