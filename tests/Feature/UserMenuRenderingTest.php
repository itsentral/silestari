<?php

use App\Models\User;

it('renders account list with admin, assigned and empty module access', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin Test']);
    User::factory()->departmentUser()->create(['name' => 'Assigned Test', 'allowed_modules' => ['company']]);
    User::factory()->departmentUser()->create(['name' => 'Empty Test', 'allowed_modules' => []]);

    $this->actingAs($admin)->withSession(['auth_version' => $admin->auth_version])
        ->get(route('users.index'))->assertOk()
        ->assertSee('Semua modul')->assertSee('Profil Perusahaan')->assertSee('Belum diberi akses')
        ->assertSee('Assigned Test')->assertSee('Empty Test');
});

it('opens create and edit account forms without changing accounts', function () {
    $admin = User::factory()->admin()->create();
    $worker = User::factory()->departmentUser()->create();
    $this->actingAs($admin)->withSession(['auth_version' => $admin->auth_version]);
    $this->get(route('users.create'))->assertOk();
    $this->get(route('users.edit', $worker))->assertOk();
    expect(User::count())->toBe(2);
});
