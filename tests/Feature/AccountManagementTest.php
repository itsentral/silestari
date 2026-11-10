<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

test('allows admins to create accounts with department and separate module permissions', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->withSession(['auth_version' => 1])->post('/users', ['name' => 'Worker', 'email' => 'worker@example.test', 'password' => 'WorkerPassword123', 'password_confirmation' => 'WorkerPassword123', 'role' => 'user', 'department' => 'HSE', 'is_active' => '1', 'allowed_modules' => ['risk']])->assertRedirect(route('users.index'));
    $worker = User::query()->where('email', 'worker@example.test')->sole();
    expect($worker->department)->toBe('HSE');
    expect($worker->allowed_modules)->toBe(['risk']);
    expect(Hash::check('WorkerPassword123', $worker->password))->toBeTrue();
    expect($worker->hasModule('audit'))->toBeFalse();
});
test('forbids ordinary users from managing accounts or elevating privileges', function (): void {
    $user = User::factory()->departmentUser()->create();
    $this->actingAs($user)->withSession(['auth_version' => 1])->get('/users')->assertForbidden();
    $this->post('/users', ['name' => 'Hacker', 'email' => 'hack@example.test', 'role' => 'admin', 'password' => 'ExamplePassword123', 'password_confirmation' => 'ExamplePassword123', 'is_active' => '1'])->assertForbidden();
    $this->assertDatabaseCount('users', 1);
});
test('requires a department for a non admin account', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->withSession(['auth_version' => 1])->post('/users', ['name' => 'Worker', 'email' => 'worker@example.test', 'role' => 'user', 'is_active' => '1', 'password' => 'ExamplePassword123', 'password_confirmation' => 'ExamplePassword123'])->assertSessionHasErrors('department');
    $this->assertDatabaseCount('users', 1);
});
test('rejects an unknown or removed module permission', function (string $module): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->withSession(['auth_version' => 1])->post('/users', ['name' => 'Worker', 'email' => 'worker@example.test', 'role' => 'user', 'department' => 'HSE', 'is_active' => '1', 'password' => 'ExamplePassword123', 'password_confirmation' => 'ExamplePassword123', 'allowed_modules' => [$module]])->assertSessionHasErrors('allowed_modules.0');
    $this->assertDatabaseCount('users', 1);
})->with(['scope', 'opportunity', 'unknown']);
test('does not allow the signed in admin to disable or demote themself', function (string $role, string $active): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->withSession(['auth_version' => 1])->put(route('users.update', $admin), ['name' => $admin->name, 'email' => $admin->email, 'role' => $role, 'department' => 'HSE', 'is_active' => $active])->assertSessionHasErrors('role');
    expect($admin->fresh()->role)->toBe('admin');
    expect($admin->fresh()->is_active)->toBeTrue();
})->with(['demotion' => ['user', '1'], 'disable' => ['admin', '0']]);
test('updates permissions and invalidates old account sessions without replacing a blank password', function (): void {
    $admin = User::factory()->admin()->create();
    $worker = User::factory()->departmentUser()->create(['allowed_modules' => ['risk']]);
    $hash = $worker->password;
    $this->actingAs($admin)->withSession(['auth_version' => 1])->put(route('users.update', $worker), ['name' => $worker->name, 'email' => $worker->email, 'role' => 'user', 'department' => 'Finance', 'is_active' => '1', 'allowed_modules' => ['audit'], 'password' => '', 'password_confirmation' => ''])->assertRedirect(route('users.index'));
    $worker->refresh();
    expect($worker->auth_version)->toBe(2);
    expect($worker->allowed_modules)->toBe(['audit']);
    expect($worker->department)->toBe('Finance');
    expect($worker->password)->toBe($hash);
});
test('checks independent HIRADC and audit permissions in the server gate', function (array $modules, bool $risk, bool $audit): void {
    $user = User::factory()->departmentUser()->create(['allowed_modules' => $modules]);
    expect(Gate::forUser($user)->allows('access-module', 'risk'))->toBe($risk);
    expect(Gate::forUser($user)->allows('access-module', 'audit'))->toBe($audit);
})->with(['none' => [[], false, false], 'risk only' => [['risk'], true, false], 'audit only' => [['audit'], false, true], 'both' => [['risk', 'audit'], true, true]]);
test('blocks direct URLs for an unpermitted module', function (): void {
    $user = User::factory()->departmentUser()->create(['allowed_modules' => ['risk']]);
    $this->actingAs($user)->withSession(['auth_version' => 1])->get('/modules/audit')->assertForbidden();
    $this->get('/modules/risk')->assertRedirect('/dashboard?module=risk');
});
test('does not grant module access to a user without a department', function (): void {
    $user = User::factory()->create(['department' => null, 'allowed_modules' => ['risk', 'audit']]);
    expect(Gate::forUser($user)->allows('access-module', 'risk'))->toBeFalse();
    expect(Gate::forUser($user)->allows('access-module', 'audit'))->toBeFalse();
});
test('escapes account names when rendered in the dashboard', function (): void {
    $user = User::factory()->admin()->create(['name' => '<script>alert(1)</script>']);
    $this->actingAs($user)->withSession(['auth_version' => 1])->get('/dashboard')->assertOk()->assertSee('\\u003Cscript\\u003E', false)->assertDontSee('<script>alert(1)</script>', false);
});
