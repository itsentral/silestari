<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('shows first admin setup without creating a default account', function (): void {
    $this->get('/')->assertOk()->assertSee('Buat akun admin');
    $this->assertDatabaseCount('users', 0);
});
test('creates the first admin with hashed password and authenticated session', function (): void {
    $this->post('/setup', ['name' => 'Cahyadi', 'email' => 'admin@example.test', 'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123', 'role' => 'user'])->assertRedirect(route('dashboard'));
    $user = User::query()->sole();
    expect($user->role)->toBe('admin');
    expect(Hash::check('StrongPassword123', $user->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('app_installations', ['id' => 1]);
});
test('rejects remote first admin setup', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.10'])->post('/setup', ['name' => 'Other', 'email' => 'other@example.test', 'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123'])->assertForbidden();
    $this->assertDatabaseCount('users', 0);
});
test('closes setup when an account already exists', function (): void {
    User::factory()->admin()->create();
    $this->get('/setup')->assertRedirect(route('login'));
    $this->post('/setup', ['name' => 'Other', 'email' => 'other@example.test', 'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123'])->assertForbidden();
    $this->assertDatabaseCount('users', 1);
});
test('rejects a short password during setup', function (): void {
    $this->post('/setup', ['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'Short123', 'password_confirmation' => 'Short123'])->assertSessionHasErrors('password');
    $this->assertDatabaseCount('users', 0);
});
test('requires authentication for protected pages', function (): void {
    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->get('/users')->assertRedirect(route('login'));
});
test('authenticates a valid active account', function (): void {
    $user = User::factory()->admin()->create(['password' => 'StrongPassword123']);
    $this->post('/login', ['email' => $user->email, 'password' => 'StrongPassword123'])->assertRedirect(route('dashboard'))->assertSessionHas('auth_version', 1);
    $this->assertAuthenticatedAs($user);
});
test('rejects invalid credentials or inactive accounts', function (bool $active, string $password): void {
    $user = User::factory()->create(['is_active' => $active, 'password' => 'StrongPassword123']);
    $this->post('/login', ['email' => $user->email, 'password' => $password])->assertSessionHasErrors('email');
    $this->assertGuest();
})->with(['wrong password' => [true, 'WrongPassword123'], 'disabled' => [false, 'StrongPassword123']]);
test('limits repeated login attempts', function (): void {
    User::factory()->create(['email' => 'limited@example.test']);
    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => 'limited@example.test', 'password' => 'Incorrect123'])->assertSessionHasErrors('email');
    }
    $this->post('/login', ['email' => 'limited@example.test', 'password' => 'Incorrect123'])->assertTooManyRequests();
});
test('logs out and invalidates the authenticated session', function (): void {
    $user = User::factory()->admin()->create();
    $this->actingAs($user)->withSession(['auth_version' => 1])->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
    $this->get('/dashboard')->assertRedirect(route('login'));
});
test('rejects a session after account access version changes', function (): void {
    $user = User::factory()->admin()->create(['auth_version' => 2]);
    $this->actingAs($user)->withSession(['auth_version' => 1])->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
});
test('rejects a disabled account session', function (): void {
    $user = User::factory()->admin()->create(['is_active' => false]);
    $this->actingAs($user)->withSession(['auth_version' => 1])->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
});
