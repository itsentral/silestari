<?php

namespace App\Models;

use App\Support\ModuleRegistry;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'legacy_password_hash', 'legacy_password_salt'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed',
            'legacy_password_hash' => 'encrypted',
            'legacy_password_salt' => 'encrypted', 'is_active' => 'boolean', 'allowed_modules' => 'array', 'auth_version' => 'integer'];
    }

    public function isAdmin(): bool
    {
        return $this->is_active && $this->role === 'admin';
    }

    public function hasModule(string $module): bool
    {
        if (! $this->is_active || ! array_key_exists($module, ModuleRegistry::all())) {
            return false;
        }

        return $this->isAdmin() || (filled($this->department) && in_array($module, $this->allowed_modules ?? [], true));
    }
}
