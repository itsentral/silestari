<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

class LegacyUserProvider extends EloquentUserProvider
{
    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        if ($user instanceof User && filled($user->legacy_password_hash)) {
            $plain = $credentials['password'] ?? null;
            if (! is_string($plain) || ! is_string($user->legacy_password_salt)) {
                return false;
            }
            $derived = hash_pbkdf2('sha256', $plain, $user->legacy_password_salt, 150000, 64, false);

            return hash_equals($user->legacy_password_hash, $derived);
        }

        return parent::validateCredentials($user, $credentials);
    }

    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false): void
    {
        if ($user instanceof User && filled($user->legacy_password_hash)) {
            if (strlen($credentials['password']) > 72) {
                return;
            }
            $password = $this->hasher->make($credentials['password']);
            $updated = $user->newQuery()->whereKey($user->getKey())->where('auth_version', $user->auth_version)->where('legacy_password_hash', $user->getRawOriginal('legacy_password_hash'))->update(['password' => $password, 'legacy_password_hash' => null, 'legacy_password_salt' => null]);
            if ($updated) {
                $user->forceFill(['password' => $password, 'legacy_password_hash' => null, 'legacy_password_salt' => null]);
            }

            return;
        }
        parent::rehashPasswordIfRequired($user, $credentials, $force);
    }
}
