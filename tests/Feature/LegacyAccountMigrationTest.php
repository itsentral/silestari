<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function legacyAccountFixture(string $role = 'admin'): array
{
    $salt = '00112233445566778899aabbccddeeff';

    return ['id' => 'legacy-'.$role, 'name' => 'Legacy User', 'username' => 'legacy_'.$role, 'role' => $role, 'active' => true, 'allowed' => $role === 'admin' ? [] : ['risk'], 'department' => 'HSE', 'salt' => $salt, 'hash' => '7844e62f719801b0ee46a5d4b1b0f59c4834dfd4f850622a9c13048fe32cd434'];
}
function withLegacyAccountFile(array $accounts, Closure $callback): void
{
    $dir = storage_path('framework/testing/account-'.Str::uuid());
    File::ensureDirectoryExists($dir);
    $file = $dir.'/accounts.json';
    file_put_contents($file, json_encode(['schemaVersion' => 1, 'type' => 'silestari-accounts', 'accounts' => $accounts]));
    try {
        $callback($file);
    } finally {
        File::deleteDirectory($dir);
    }
}

test('imports old accounts and preserves department status and module grants idempotently', function (): void {
    withLegacyAccountFile([legacyAccountFixture(), legacyAccountFixture('user')], function (string $file): void {
        $this->artisan('silestari:import-accounts', ['file' => $file])->assertSuccessful();
        $this->assertDatabaseCount('users', 2);
        $user = User::query()->where('username', 'legacy_user')->sole();
        expect($user->department)->toBe('HSE');
        expect($user->allowed_modules)->toBe(['risk']);
        expect($user->hasModule('audit'))->toBeFalse();
        $this->get('/setup')->assertRedirect(route('login'));
        $this->artisan('silestari:import-accounts', ['file' => $file])->assertSuccessful();
        $this->assertDatabaseCount('users', 2);
    });
});
test('accepts the old password and upgrades its verifier after successful login', function (): void {
    withLegacyAccountFile([legacyAccountFixture()], function (string $file): void {
        $this->artisan('silestari:import-accounts', ['file' => $file])->assertSuccessful();
        $this->post('/login', ['email' => 'legacy_admin', 'password' => 'OldPassword123'])->assertRedirect(route('dashboard'));
        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        expect($user->legacy_password_hash)->toBeNull();
        expect(Hash::check('OldPassword123', $user->password))->toBeTrue();
        $this->post('/logout')->assertRedirect(route('login'));
        $this->post('/login', ['email' => 'legacy_admin', 'password' => 'OldPassword123'])->assertRedirect(route('dashboard'));
    });
});
test('rejects the wrong legacy password without modifying its verifier', function (): void {
    withLegacyAccountFile([legacyAccountFixture()], function (string $file): void {
        $this->artisan('silestari:import-accounts', ['file' => $file])->assertSuccessful();
        $hash = User::query()->sole()->getRawOriginal('legacy_password_hash');
        $this->post('/login', ['email' => 'legacy_admin', 'password' => 'Incorrect123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        expect(User::query()->sole()->getRawOriginal('legacy_password_hash'))->toBe($hash);
    });
});
test('keeps a reset password instead of reimporting a stale old password', function (): void {
    withLegacyAccountFile([legacyAccountFixture()], function (string $file): void {
        $this->artisan('silestari:import-accounts', ['file' => $file])->assertSuccessful();
        $u = User::query()->sole();
        $u->password = 'ResetPassword123';
        $u->legacy_password_hash = null;
        $u->legacy_password_salt = null;
        $u->save();
        $this->artisan('silestari:import-accounts', ['file' => $file])->assertSuccessful();
        expect(Hash::check('ResetPassword123', User::query()->sole()->password))->toBeTrue();
    });
});
test('validates the entire import before changing accounts', function (): void {
    $bad = legacyAccountFixture('user');
    $bad['hash'] = 'invalid';
    withLegacyAccountFile([legacyAccountFixture(), $bad], function (string $file): void {
        $this->artisan('silestari:import-accounts', ['file' => $file])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    });
});
test('does not store passwords or hash material in account serialization', function (): void {
    withLegacyAccountFile([legacyAccountFixture()], function (string $file): void {
        $this->artisan('silestari:import-accounts', ['file' => $file])->assertSuccessful();
        expect(User::query()->sole()->toArray())->not->toHaveKeys(['password', 'legacy_password_hash', 'legacy_password_salt']);
    });
});

test('clears old password verification when an admin resets an imported account password', function (): void {
    withLegacyAccountFile([legacyAccountFixture(), legacyAccountFixture('user')], function (string $file): void {
        $this->artisan('silestari:import-accounts', ['file' => $file])->assertSuccessful();
        $admin = User::query()->where('role', 'admin')->sole();
        $worker = User::query()->where('role', 'user')->sole();
        $this->actingAs($admin)->withSession(['auth_version' => 1])->put(route('users.update', $worker), ['name' => $worker->name, 'email' => null, 'username' => $worker->username, 'role' => 'user', 'is_active' => '1', 'department' => 'HSE', 'allowed_modules' => ['risk'], 'password' => 'NewPassword456', 'password_confirmation' => 'NewPassword456'])->assertRedirect(route('users.index'));
        expect($worker->fresh()->legacy_password_hash)->toBeNull();
        $this->post('/logout')->assertRedirect(route('login'));
        $this->post('/login', ['email' => $worker->username, 'password' => 'OldPassword123'])->assertSessionHasErrors('email');
        $this->post('/login',['email' => $worker->username, 'password' => 'NewPassword456'])->assertRedirect(route('dashboard'));
    });
});
